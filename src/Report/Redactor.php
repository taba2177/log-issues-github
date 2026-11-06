<?php

declare(strict_types=1);

namespace Taba\LogIssues\Report;

use Taba\LogIssues\Settings;

/**
 * Removes secrets and personal data from everything that leaves the application.
 */
final class Redactor
{
    public const MASK = '[REDACTED]';

    /** Context keys (normalized: lowercase, no "_", "-", " ") whose values are always masked. */
    private const KEYS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'apikey', 'authorization', 'cookie',
        'session', 'credential', 'privatekey', 'userid', 'email', 'phone', 'creditcard',
        'cardnumber', 'cvv', 'cvc', 'iban', 'ssn',
    ];

    private const MAX_DEPTH = 6;

    private const MAX_ITEMS = 50;

    public function __construct(private readonly Settings $settings) {}

    public function report(Report $report): Report
    {
        $previous = array_map(fn (array $p): array => [
            'class' => $p['class'],
            'message' => $this->string($p['message']),
        ], $report->previous);

        $runtime = [];
        foreach ($report->runtime as $key => $value) {
            $runtime[$key] = is_string($value) ? $this->string($value) : $value;
        }

        /** @var array<string, mixed> $context */
        $context = $this->value($report->context);

        return $report->with([
            'message' => $this->string($report->message),
            'previous' => $previous,
            'context' => $context,
            'runtime' => $runtime,
        ]);
    }

    /** Recursively redacts arrays (by key and value) and strings. */
    public function value(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return $this->string($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        if ($depth >= self::MAX_DEPTH) {
            return '[…]';
        }

        $out = [];
        $i = 0;
        foreach ($value as $key => $item) {
            if (++$i > self::MAX_ITEMS) {
                $out['…'] = (count($value) - self::MAX_ITEMS).' more';
                break;
            }
            $out[$key] = is_string($key) && $this->isSensitiveKey($key)
                ? self::MASK
                : $this->value($item, $depth + 1);
        }

        return $out;
    }

    public function string(string $text): string
    {
        foreach ($this->settings->secretValues() as $secret) {
            $text = str_replace($secret, self::MASK, $text);
        }

        $text = (string) preg_replace_callback_array([
            // JSON Web Tokens
            '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/' => fn (): string => self::MASK,
            // Authorization headers
            '/\b(Bearer|Basic|token)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => fn (array $m): string => $m[1].' '.self::MASK,
            // GitHub / common API key shapes
            '/\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9_-]{20,}|xox[abpr]-[A-Za-z0-9-]{10,}|AKIA[0-9A-Z]{16})\b/' => fn (): string => self::MASK,
            // key=value / key: value pairs inside free text
            '/\b((?:password|passwd|pwd|secret|token|api[_-]?key|authorization|cookie)\s*[=:]\s*)(?!(?:Bearer|Basic|token)\s)("[^"]*"|\'[^\']*\'|[^\s,;&]+)/i' => fn (array $m): string => $m[1].self::MASK,
            // query strings of URLs
            '~\b([a-z][a-z0-9+.-]*://[^\s?#"\'<>]+)\?[^\s#"\'<>]*~i' => fn (array $m): string => $m[1].'?'.self::MASK,
            // e-mail addresses
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => fn (): string => '[email]',
            // IBAN
            '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,4})?\b/' => fn (): string => '[iban]',
            // card-like numbers (Luhn-valid only)
            '/(?<![\d.])\d(?:[ -]?\d){12,18}(?![\d.])/' => fn (array $m): string => self::luhn($m[0]) ? '[card]' : $m[0],
            // phone numbers: international, local with leading 0, (xxx) xxx-xxxx
            '/(?<![\w+])\+\d{1,3}[\s.-]?\(?\d{1,4}\)?(?:[\s.-]?\d{2,4}){2,4}\b/' => fn (): string => '[phone]',
            '/(?<![\w.])0\d{2}[\s.-]?\d{3}[\s.-]?\d{3,4}\b/' => fn (): string => '[phone]',
            '/\(\d{3}\)\s?\d{3}[\s.-]\d{4}\b/' => fn (): string => '[phone]',
        ], $text);

        foreach ($this->settings->redactPatterns() as $pattern) {
            $result = @preg_replace($pattern, self::MASK, $text);
            if (is_string($result)) {
                $text = $result;
            }
        }

        $max = $this->settings->maxStringLength();
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max).'… [truncated '.(mb_strlen($text) - $max).' chars]';
        }

        return $text;
    }

    public function isSensitiveKey(string $key): bool
    {
        $normalized = self::normalizeKey($key);
        if ($normalized === '') {
            return false;
        }

        foreach ([...self::KEYS, ...$this->settings->redactKeys()] as $needle) {
            $needle = self::normalizeKey($needle);
            if ($needle !== '' && str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['_', '-', ' ', '.'], '', $key));
    }

    private static function luhn(string $number): bool
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';
        $length = strlen($digits);
        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$length - 1 - $i];
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
