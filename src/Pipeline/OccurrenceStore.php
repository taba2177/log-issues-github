<?php

declare(strict_types=1);

namespace Taba\LogIssues\Pipeline;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Taba\LogIssues\Settings;

/**
 * Local memory of the package, kept in Laravel's cache: occurrence counts, known issue numbers,
 * comment throttling, the hourly cap and the circuit breaker.
 *
 * @phpstan-type Entry array{
 *     fingerprint: string, title: string, level: string, environment: string,
 *     count: int, first_seen: string, last_seen: string,
 *     issue: int|null, issue_url: string|null,
 *     last_comment_at: string|null, count_at_last_comment: int,
 *     deferred_until: string|null
 * }
 */
final class OccurrenceStore
{
    public const PREFIX = 'log-issues:';

    public const FAILURE_THRESHOLD = 5;

    public const CIRCUIT_MINUTES = 15;

    private const TTL_DAYS = 30;

    private const INDEX_LIMIT = 500;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Settings $settings,
    ) {}

    /**
     * Counts $times new occurrences and returns the updated entry.
     *
     * @return Entry
     */
    public function record(string $fingerprint, string $title, string $level, string $environment, int $times = 1, ?Carbon $at = null): array
    {
        $now = ($at ?? Carbon::now())->toAtomString();
        $entry = $this->get($fingerprint) ?? [
            'fingerprint' => $fingerprint,
            'title' => $title,
            'level' => $level,
            'environment' => $environment,
            'count' => 0,
            'first_seen' => $now,
            'last_seen' => $now,
            'issue' => null,
            'issue_url' => null,
            'last_comment_at' => null,
            'count_at_last_comment' => 0,
            'deferred_until' => null,
        ];

        $entry['count'] += max(1, $times);
        $entry['last_seen'] = $now;
        $entry['title'] = $title;

        $this->put($entry);
        $this->index($fingerprint);

        return $entry;
    }

    /** @return Entry|null */
    public function get(string $fingerprint): ?array
    {
        $entry = $this->store()->get(self::PREFIX.'fp:'.$fingerprint);

        /** @var Entry|null */
        return is_array($entry) ? $entry : null;
    }

    public function setIssue(string $fingerprint, ?int $number, ?string $url = null): void
    {
        $this->update($fingerprint, ['issue' => $number, 'issue_url' => $number === null ? null : $url]);
    }

    public function markCommented(string $fingerprint, int $count, ?Carbon $at = null): void
    {
        $this->update($fingerprint, [
            'last_comment_at' => ($at ?? Carbon::now())->toAtomString(),
            'count_at_last_comment' => $count,
        ]);
    }

    public function defer(string $fingerprint, Carbon $until): void
    {
        $this->update($fingerprint, ['deferred_until' => $until->toAtomString()]);
    }

    /** Prevents several jobs for the same unknown fingerprint from racing to create an issue. */
    public function acquirePending(string $fingerprint): bool
    {
        return $this->store()->add(self::PREFIX.'pending:'.$fingerprint, 1, Carbon::now()->addMinutes(10));
    }

    public function releasePending(string $fingerprint): void
    {
        $this->store()->forget(self::PREFIX.'pending:'.$fingerprint);
    }

    public function newIssuesThisHour(): int
    {
        return (int) $this->store()->get($this->hourKey(), 0);
    }

    /** Atomically takes one slot of max_new_issues_per_hour. */
    public function reserveNewIssue(int $max): bool
    {
        $key = $this->hourKey();
        $this->store()->add($key, 0, Carbon::now()->addHours(2));
        $count = $this->store()->increment($key);

        if ($count === false || $count > $max) {
            $this->store()->decrement($key);

            return false;
        }

        return true;
    }

    public function circuitOpen(): bool
    {
        $until = $this->store()->get(self::PREFIX.'circuit:open_until');

        return is_string($until) && Carbon::parse($until)->isFuture();
    }

    public function circuitOpenUntil(): ?string
    {
        $until = $this->store()->get(self::PREFIX.'circuit:open_until');

        return is_string($until) && Carbon::parse($until)->isFuture() ? $until : null;
    }

    public function consecutiveFailures(): int
    {
        return (int) $this->store()->get(self::PREFIX.'circuit:failures', 0);
    }

    public function recordFailure(string $reason): void
    {
        $failures = $this->consecutiveFailures() + 1;
        $this->setLastError($reason);

        if ($failures >= self::FAILURE_THRESHOLD) {
            $this->openCircuit(Carbon::now()->addMinutes(self::CIRCUIT_MINUTES));
            $failures = 0;
        }

        $this->store()->put(self::PREFIX.'circuit:failures', $failures, Carbon::now()->addDay());
    }

    public function recordSuccess(): void
    {
        if ($this->consecutiveFailures() !== 0) {
            $this->store()->put(self::PREFIX.'circuit:failures', 0, Carbon::now()->addDay());
        }
    }

    public function openCircuit(Carbon $until): void
    {
        $this->store()->put(self::PREFIX.'circuit:open_until', $until->toAtomString(), $until);
    }

    public function setLastError(string $message): void
    {
        $this->store()->put(self::PREFIX.'last_error', [
            'at' => Carbon::now()->toAtomString(),
            'message' => mb_substr($message, 0, 300),
        ], Carbon::now()->addDays(self::TTL_DAYS));
    }

    /** @return array{at: string, message: string}|null */
    public function lastError(): ?array
    {
        $value = $this->store()->get(self::PREFIX.'last_error');

        /** @var array{at: string, message: string}|null */
        return is_array($value) ? $value : null;
    }

    /** @return list<Entry> */
    public function all(): array
    {
        $entries = [];
        foreach ($this->fingerprints() as $fingerprint) {
            $entry = $this->get($fingerprint);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function scanOffset(string $file): int
    {
        return (int) $this->store()->get(self::PREFIX.'scan:'.md5($file), 0);
    }

    public function setScanOffset(string $file, int $offset): void
    {
        $this->store()->forever(self::PREFIX.'scan:'.md5($file), $offset);
    }

    /** @param  array<string, mixed>  $changes */
    private function update(string $fingerprint, array $changes): void
    {
        $entry = $this->get($fingerprint);
        if ($entry === null) {
            return;
        }

        /** @var Entry $entry */
        $entry = array_merge($entry, $changes);
        $this->put($entry);
    }

    /** @param  Entry  $entry */
    private function put(array $entry): void
    {
        $this->store()->put(self::PREFIX.'fp:'.$entry['fingerprint'], $entry, Carbon::now()->addDays(self::TTL_DAYS));
    }

    /** @return list<string> */
    private function fingerprints(): array
    {
        $index = $this->store()->get(self::PREFIX.'index', []);

        return is_array($index) ? array_values(array_filter($index, 'is_string')) : [];
    }

    private function index(string $fingerprint): void
    {
        $index = $this->fingerprints();
        if (in_array($fingerprint, $index, true)) {
            return;
        }
        $index[] = $fingerprint;
        $index = array_slice($index, -self::INDEX_LIMIT);
        $this->store()->put(self::PREFIX.'index', $index, Carbon::now()->addDays(self::TTL_DAYS));
    }

    private function hourKey(): string
    {
        return self::PREFIX.'new:'.Carbon::now()->format('YmdH');
    }

    private function store(): Cache
    {
        return $this->cache->store($this->settings->cacheStore());
    }
}
