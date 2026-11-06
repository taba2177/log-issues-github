<?php

declare(strict_types=1);

namespace Taba\LogIssues;

use Illuminate\Contracts\Config\Repository;
use Monolog\Level;

/**
 * Typed, lazy view over config('log-issues'). Values are read on every call so runtime
 * config changes (tests, tenants) are honoured.
 */
final class Settings
{
    public const ON_CLOSED = ['reopen', 'new', 'ignore'];

    public const MENTION_ON = ['new', 'milestone', 'never'];

    public function __construct(private readonly Repository $config) {}

    public function enabled(): bool
    {
        return (bool) $this->get('enabled', true);
    }

    public function repo(): string
    {
        return trim((string) $this->get('repo', ''));
    }

    public function token(): string
    {
        return trim((string) $this->get('token', ''));
    }

    /** True when the package may talk to GitHub at all. */
    public function canSend(): bool
    {
        return $this->enabled() && $this->repo() !== '' && $this->token() !== '';
    }

    public function apiUrl(): string
    {
        return rtrim((string) $this->get('api_url', 'https://api.github.com'), '/');
    }

    public function webUrl(): string
    {
        return rtrim((string) $this->get('web_url', 'https://github.com'), '/');
    }

    public function level(): Level
    {
        return self::parseLevel($this->get('level', 'error')) ?? Level::Error;
    }

    /** Parses "error", "ERROR", 400 or a Level; null when it is not a log level. */
    public static function parseLevel(mixed $value): ?Level
    {
        if ($value instanceof Level) {
            return $value;
        }
        if (is_int($value)) {
            return Level::tryFrom($value);
        }
        if (! is_string($value)) {
            return null;
        }

        foreach (Level::cases() as $level) {
            if (strcasecmp($level->name, trim($value)) === 0) {
                return $level;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function environments(): array
    {
        return $this->stringList('environments', ['production', 'staging']);
    }

    public function environmentAllowed(string $environment): bool
    {
        $allowed = $this->environments();

        return in_array('*', $allowed, true) || in_array($environment, $allowed, true);
    }

    /** @return list<string> */
    public function labels(): array
    {
        return $this->stringList('labels', ['bug', 'auto-reported']);
    }

    public function severityLabel(): bool
    {
        return (bool) $this->get('severity_label', true);
    }

    public function mention(): string
    {
        return trim((string) $this->get('mention', ''));
    }

    public function mentionOn(): string
    {
        $value = strtolower((string) $this->get('mention_on', 'new'));

        return in_array($value, self::MENTION_ON, true) ? $value : 'new';
    }

    public function agentBrief(): string
    {
        return (string) $this->get('agent_brief', '');
    }

    /** @return array{connection: string|null, name: string|null}|false */
    public function queue(): array|false
    {
        $queue = $this->get('queue', false);

        if (! is_array($queue)) {
            return false;
        }

        $connection = $queue['connection'] ?? null;
        $name = $queue['name'] ?? null;

        return [
            'connection' => is_string($connection) && $connection !== '' ? $connection : null,
            'name' => is_string($name) && $name !== '' ? $name : null,
        ];
    }

    public function timeoutSeconds(): int
    {
        return max(1, (int) $this->get('timeout_seconds', 5));
    }

    public function retries(): int
    {
        return max(0, min(5, (int) $this->get('retries', 2)));
    }

    public function maxNewIssuesPerHour(): int
    {
        return max(0, (int) $this->get('max_new_issues_per_hour', 10));
    }

    public function commentCooldownMinutes(): int
    {
        return max(0, (int) $this->get('comment_cooldown_minutes', 60));
    }

    /** @return list<int> */
    public function milestones(): array
    {
        $raw = $this->get('milestones', [10, 100, 1000, 10000, 100000]);
        $values = array_map('intval', is_array($raw) ? $raw : []);
        $values = array_values(array_unique(array_filter($values, static fn (int $v): bool => $v > 1)));
        sort($values);

        return $values;
    }

    public function onClosed(): string
    {
        $value = strtolower((string) $this->get('on_closed', 'reopen'));

        return in_array($value, self::ON_CLOSED, true) ? $value : 'reopen';
    }

    public function sampleRate(): float
    {
        return max(0.0, min(1.0, (float) $this->get('sample_rate', 1.0)));
    }

    /** @return list<string> */
    public function ignoreExceptions(): array
    {
        return $this->stringList('ignore_exceptions', []);
    }

    /** @return list<string> */
    public function ignoreMessages(): array
    {
        return $this->stringList('ignore_messages', []);
    }

    /** @return list<string> */
    public function redactKeys(): array
    {
        return $this->stringList('redact.keys', []);
    }

    /** @return list<string> */
    public function redactPatterns(): array
    {
        return $this->stringList('redact.patterns', []);
    }

    public function maxStringLength(): int
    {
        return max(80, (int) $this->get('redact.max_string_length', 1000));
    }

    public function release(): ?string
    {
        $value = $this->get('release');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function branch(): string
    {
        $value = trim((string) $this->get('branch', 'main'));

        return $value === '' ? 'main' : $value;
    }

    public function basePath(): ?string
    {
        $value = $this->get('base_path');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function cacheStore(): ?string
    {
        $value = $this->get('cache_store');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Literal secret values that must never leave the app: APP_KEY, every database / redis /
     * mail password and the package token.
     *
     * @return list<string>
     */
    public function secretValues(): array
    {
        $values = [$this->token()];

        $appKey = (string) $this->config->get('app.key', '');
        $values[] = $appKey;
        if (str_starts_with($appKey, 'base64:')) {
            $values[] = substr($appKey, 7);
        }

        foreach (['database.connections', 'database.redis', 'mail.mailers'] as $group) {
            $entries = $this->config->get($group, []);
            if (! is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (is_array($entry) && isset($entry['password']) && is_string($entry['password'])) {
                    $values[] = $entry['password'];
                }
            }
        }

        $values = array_filter($values, static fn (string $v): bool => strlen($v) >= 4);
        $values = array_values(array_unique($values));
        usort($values, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $values;
    }

    private function get(string $key, mixed $default = null): mixed
    {
        return $this->config->get('log-issues.'.$key, $default);
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private function stringList(string $key, array $default): array
    {
        $raw = $this->get($key, $default);
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (! is_array($raw)) {
            return $default;
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }
}
