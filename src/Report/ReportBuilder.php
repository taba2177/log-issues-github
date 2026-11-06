<?php

declare(strict_types=1);

namespace Taba\LogIssues\Report;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Monolog\LogRecord;
use Taba\LogIssues\Support\Paths;
use Taba\LogIssues\Support\ReleaseResolver;
use Taba\LogIssues\Support\RuntimeContext;
use Throwable;

/**
 * Builds a (not yet redacted) Report from a Monolog record or from a parsed log file entry.
 */
final class ReportBuilder
{
    private const MAX_FRAMES = 60;

    private const MAX_PREVIOUS = 5;

    public function __construct(
        private readonly Application $app,
        private readonly Paths $paths,
        private readonly Fingerprinter $fingerprinter,
        private readonly ReleaseResolver $release,
        private readonly RuntimeContext $runtime,
    ) {}

    public function fromRecord(LogRecord $record): Report
    {
        $context = $record->context;
        $exception = $context['exception'] ?? null;
        $override = $context['fingerprint'] ?? null;
        unset($context['exception'], $context['fingerprint']);

        $previous = [];
        if ($exception instanceof Throwable) {
            $class = $exception::class;
            $message = $exception->getMessage();
            $frames = $this->frames($exception->getFile(), $exception->getLine(), $exception->getTrace());
            $prev = $exception->getPrevious();
            while ($prev !== null && count($previous) < self::MAX_PREVIOUS) {
                $previous[] = ['class' => $prev::class, 'message' => $prev->getMessage()];
                $prev = $prev->getPrevious();
            }
        } else {
            $class = null;
            $message = $record->message;
            $frames = $this->callerFrames(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80));
        }

        return new Report(
            fingerprint: $this->fingerprinter->fingerprint($class, $message, $frames, $override),
            environment: (string) $this->app->environment(),
            level: strtolower($record->level->getName()),
            exceptionClass: $class,
            message: $message,
            frames: $frames,
            previous: $previous,
            context: self::sanitize($context),
            runtime: $this->runtimeInfo(),
            occurredAt: $record->datetime->format(DATE_ATOM),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $trace  PHP-trace shaped frames (file, line, class, type, function).
     * @param  list<array{class: string, message: string}>  $previous
     * @param  array<string, string|int|bool|null>  $runtime
     */
    public function fromParsed(
        string $environment,
        string $level,
        ?string $class,
        string $message,
        ?string $file,
        ?int $line,
        array $trace,
        array $previous,
        string $occurredAt,
        array $runtime = [],
    ): Report {
        $frames = $this->frames($file, $line, $trace);

        return new Report(
            fingerprint: $this->fingerprinter->fingerprint($class, $message, $frames),
            environment: $environment,
            level: strtolower($level),
            exceptionClass: $class,
            message: $message,
            frames: $frames,
            previous: array_slice($previous, 0, self::MAX_PREVIOUS),
            context: [],
            runtime: $runtime + $this->versions(),
            occurredAt: $occurredAt,
        );
    }

    /**
     * Converts a PHP trace into frames whose "function" is the function that CONTAINS file:line
     * (PHP traces name the callee instead).
     *
     * @param  array<int, array<string, mixed>>  $trace
     * @return list<Frame>
     */
    public function frames(?string $file, ?int $line, array $trace): array
    {
        $trace = array_values($trace);
        $frames = [];

        if ($file !== null && $file !== '') {
            $frames[] = $this->frame($file, (int) $line, $trace[0] ?? null);
        }

        foreach ($trace as $i => $item) {
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
            if (! isset($item['file']) || ! is_string($item['file'])) {
                continue; // [internal function]
            }
            $frames[] = $this->frame($item['file'], (int) ($item['line'] ?? 0), $trace[$i + 1] ?? null);
        }

        return $frames;
    }

    /**
     * Frames for a plain Log::error() call: drop Monolog / Laravel / package frames above the caller.
     *
     * @param  array<int, array<string, mixed>>  $backtrace
     * @return list<Frame>
     */
    private function callerFrames(array $backtrace): array
    {
        $frames = $this->frames(null, null, $backtrace);

        foreach ($frames as $i => $frame) {
            if ($frame->application) {
                return array_slice($frames, $i);
            }
        }

        // No application frame (e.g. vendor code logging): drop only the logging plumbing.
        return array_values(array_filter(
            $frames,
            fn (Frame $f): bool => ! str_contains($f->file, 'monolog/monolog/')
                && ! str_contains($f->file, '/Illuminate/Log/')
                && ! str_contains($f->file, '/Illuminate/Support/Facades/')
                && ! $this->paths->isPackageFile($f->file),
        ));
    }

    /** @param  array<string, mixed>|null  $caller */
    private function frame(string $file, int $line, ?array $caller): Frame
    {
        $function = '{main}';
        if ($caller !== null && isset($caller['function']) && is_string($caller['function'])) {
            $function = (isset($caller['class']) && is_string($caller['class']) ? $caller['class'] : '')
                .(isset($caller['type']) && is_string($caller['type']) ? $caller['type'] : '')
                .$caller['function'];
        }

        return new Frame(
            $this->paths->relative($file),
            $line,
            self::normalizeFunction($function),
            $this->paths->isApplicationFile($file),
        );
    }

    /** PHP 8.4 names closures "{closure:Foo::bar():12}" — strip the line so fingerprints survive shifts. */
    public static function normalizeFunction(string $function): string
    {
        return (string) preg_replace('/\{closure:[^}]*\}/', '{closure}', $function);
    }

    /** @return array<string, string|int|bool|null> */
    private function runtimeInfo(): array
    {
        $info = [];

        try {
            if ($this->app->runningInConsole()) {
                $argv = $_SERVER['argv'] ?? [];
                $command = is_array($argv) && isset($argv[1]) && is_string($argv[1]) ? $argv[1] : null;
                $info['command'] = $command !== null && ! str_starts_with($command, '-') ? $command : null;
            } elseif ($this->app->bound('request')) {
                /** @var Request $request */
                $request = $this->app->make('request');
                $info['method'] = $request->getMethod();
                $route = $request->route();
                $info['route'] = $route instanceof Route ? ($route->getName() ?? $route->uri()) : null;
            }

            $info['job'] = $this->runtime->job();
            $info['authenticated'] = $this->authenticated();
        } catch (Throwable) {
            // runtime details are best effort
        }

        return array_filter($info, static fn ($v): bool => $v !== null) + $this->versions();
    }

    /** @return array<string, string|null> */
    private function versions(): array
    {
        return [
            'php' => PHP_VERSION,
            'laravel' => $this->app->version(),
            'release' => $this->release->release(),
            'ref' => $this->release->ref(),
        ];
    }

    /** Never loads a user: only asks a guard that was already resolved whether it holds one. */
    private function authenticated(): ?bool
    {
        try {
            if (! $this->app->resolved('auth')) {
                return false;
            }
            /** @var AuthManager $auth */
            $auth = $this->app->make('auth');
            if (! $auth->hasResolvedGuards()) {
                return false;
            }

            return $auth->guard()->hasUser();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Makes log context safe to serialize: objects become "[object Class]", depth is limited.
     *
     * @param  array<mixed>  $context
     * @return array<string, mixed>
     */
    public static function sanitize(array $context, int $depth = 0): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $out[(string) $key] = self::sanitizeValue($value, $depth);
        }

        return $out;
    }

    private static function sanitizeValue(mixed $value, int $depth): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value), is_string($value) => $value,
            is_array($value) => $depth >= 5 ? '[array]' : self::sanitize($value, $depth + 1),
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            is_object($value) => '[object '.$value::class.']',
            default => '['.get_debug_type($value).']',
        };
    }
}
