<?php

declare(strict_types=1);

namespace Taba\LogIssues\Scan;

use Generator;

/**
 * Streams Laravel's default log format (single and daily files, multi-line stack traces):
 *
 *   [2026-11-06 14:44:00] production.ERROR: Message {"exception":"[object] (Class(code: 0): Message at /path/File.php:12)
 *   [stacktrace]
 *   #0 /path/Other.php(34): App\Foo->bar()
 *   #1 {main}
 *   "}
 */
final class LogFileParser
{
    private const HEADER = '/^\[(?<date>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\] (?<env>[\w.-]+)\.(?<level>[A-Z]+): (?<rest>.*)$/';

    private const MAX_ENTRY_BYTES = 1_000_000;

    /**
     * Reads entries starting at byte $offset; $endOffset receives the byte offset after the last
     * complete line that was consumed.
     *
     * @return Generator<int, LogEntry>
     */
    public function parse(string $path, int $offset = 0, int &$endOffset = 0): Generator
    {
        $endOffset = $offset;
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            if ($offset > 0) {
                fseek($handle, $offset);
            }

            $buffer = null;
            while (($line = fgets($handle)) !== false) {
                if (! str_ends_with($line, "\n") && feof($handle)) {
                    break; // a line still being written: leave it for the next run
                }
                $endOffset += strlen($line);
                $line = rtrim($line, "\r\n");

                if (preg_match(self::HEADER, $line) === 1) {
                    if ($buffer !== null && ($entry = $this->entry($buffer)) !== null) {
                        yield $entry;
                    }
                    $buffer = $line;
                } elseif ($buffer !== null && strlen($buffer) < self::MAX_ENTRY_BYTES) {
                    $buffer .= "\n".$line;
                }
            }

            if ($buffer !== null && ($entry = $this->entry($buffer)) !== null) {
                yield $entry;
            }
        } finally {
            fclose($handle);
        }
    }

    public function entry(string $text): ?LogEntry
    {
        $firstLine = strtok($text, "\n");
        if ($firstLine === false || preg_match(self::HEADER, $firstLine, $header) !== 1) {
            return null;
        }

        $body = substr($text, strlen($header[0]) - strlen($header['rest']));
        $exception = $this->exceptionString($body);

        if ($exception === null) {
            return new LogEntry($header['date'], $header['env'], strtolower($header['level']), $this->plainMessage($body));
        }

        $segments = preg_split('/\n\[previous exception\] /', $exception) ?: [$exception];
        $main = $this->segment((string) array_shift($segments));

        if ($main === null) {
            return new LogEntry($header['date'], $header['env'], strtolower($header['level']), $this->plainMessage($body));
        }

        $previous = [];
        foreach ($segments as $segment) {
            $parsed = $this->segment($segment);
            if ($parsed !== null) {
                $previous[] = ['class' => $parsed['class'], 'message' => $parsed['message']];
            }
        }

        return new LogEntry(
            $header['date'],
            $header['env'],
            strtolower($header['level']),
            $main['message'],
            $main['class'],
            $main['file'],
            $main['line'],
            $main['trace'],
            $previous,
        );
    }

    /** Extracts and JSON-decodes the "exception" value of the context. */
    private function exceptionString(string $body): ?string
    {
        $start = strpos($body, '"exception":"[object] (');
        if ($start === false) {
            return null;
        }
        $start += strlen('"exception":"');

        $length = strlen($body);
        $raw = '';
        for ($i = $start; $i < $length; $i++) {
            $char = $body[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $raw .= $char.$body[++$i];

                continue;
            }
            if ($char === '"') {
                break;
            }
            $raw .= $char;
        }

        $raw = str_replace(["\r", "\n", "\t"], ['', '\n', '\t'], $raw);
        $decoded = json_decode('"'.$raw.'"');

        return is_string($decoded) ? $decoded : stripslashes($raw);
    }

    /**
     * @return array{class: string, message: string, file: string, line: int, trace: list<array<string, mixed>>}|null
     */
    private function segment(string $segment): ?array
    {
        $parts = explode("\n[stacktrace]\n", $segment, 2);
        $head = rtrim($parts[0]);

        if (preg_match('/^\[object\] \((?<class>[^\s(]+)\(code: [^)]*\): (?<message>.*) at (?<file>[^\n]+?):(?<line>\d+)\)$/s', $head, $m) !== 1) {
            return null;
        }

        return [
            'class' => self::className($m['class']),
            'message' => $m['message'],
            'file' => $m['file'],
            'line' => (int) $m['line'],
            'trace' => $this->trace($parts[1] ?? ''),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function trace(string $text): array
    {
        $frames = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if (preg_match('/^#\d+ (?:(?<file>.+)\((?<line>\d+)\)|\[internal function\]): (?<call>.+)$/', $line, $m) === 1) {
                $frame = $this->call($m['call']);
                if ($m['file'] !== '') {
                    $frame['file'] = $m['file'];
                    $frame['line'] = (int) $m['line'];
                }
                $frames[] = $frame;
            } elseif (preg_match('/^#\d+ \{main\}$/', $line) === 1) {
                break;
            }
        }

        return $frames;
    }

    /** @return array<string, mixed> */
    private function call(string $call): array
    {
        // PHP 8.4 closure names contain "::" and "()" — collapse them before splitting.
        $call = (string) preg_replace('/\{closure:[^}]*\}/', '{closure}', $call);
        $call = (string) preg_replace('/\(.*$/s', '', $call);

        if (preg_match('/^(?<class>.+?)(?<type>->|::)(?<function>.+)$/', $call, $m) === 1) {
            return ['class' => self::className($m['class']), 'type' => $m['type'], 'function' => self::className($m['function'])];
        }

        return ['function' => self::className($call)];
    }

    /** Monolog writes namespaces with "/" on Windows; PHP traces use "\". */
    private static function className(string $name): string
    {
        return ltrim(str_replace('/', '\\', $name), '\\');
    }

    /** "Message {"ctx":1} []" → "Message". */
    private function plainMessage(string $body): string
    {
        $firstLine = strtok($body, "\n");
        $message = $firstLine === false ? $body : $firstLine;
        $message = (string) preg_replace('/\s+(?:\{.*\}|\[\])\s+(?:\{.*\}|\[\])\s*$/', '', $message);
        $message = (string) preg_replace('/\s+(?:\{.*\}|\[\])\s*$/', '', $message);

        return trim($message);
    }
}
