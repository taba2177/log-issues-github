<?php

declare(strict_types=1);

namespace Taba\LogIssues\Report;

/**
 * sha1(exception class | normalized message | first application frame "path.php:function").
 * Stable across deploys, line shifts and variable ids in messages.
 */
final class Fingerprinter
{
    /** @param  list<Frame>  $frames */
    public function fingerprint(?string $class, string $message, array $frames, mixed $override = null): string
    {
        if (is_scalar($override) && (string) $override !== '') {
            return sha1('custom|'.(string) $override);
        }

        $anchor = '';
        foreach ($frames as $frame) {
            if ($frame->application) {
                $anchor = $frame->signature();
                break;
            }
        }
        if ($anchor === '' && isset($frames[0])) {
            $anchor = $frames[0]->signature();
        }

        return sha1(($class ?? 'log').'|'.self::normalize($message).'|'.$anchor);
    }

    public static function normalize(string $message): string
    {
        $patterns = [
            '~\b[a-z][a-z0-9+.-]*://[^\s\'"<>)]+~i' => '{url}',
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '{email}',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
            '/\b[0-9A-HJKMNP-TV-Z]{26}\b/' => '{ulid}',
            '/"(?:[^"\\\\]|\\\\.)*"/' => '{str}',
            "/'(?:[^'\\\\]|\\\\.)*'/" => '{str}',
            '/\b0x[0-9a-f]+\b/i' => '{hex}',
            '/\b(?=[0-9a-f]*\d)(?=[0-9a-f]*[a-f])[0-9a-f]{8,}\b/i' => '{hex}',
            '/-?\d+(?:[.,]\d+)*/' => '{n}',
            '/\s+/' => ' ',
        ];

        $normalized = (string) preg_replace(array_keys($patterns), array_values($patterns), $message);

        return trim(mb_substr($normalized, 0, 500));
    }
}
