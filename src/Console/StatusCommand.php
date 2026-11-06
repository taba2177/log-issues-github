<?php

declare(strict_types=1);

namespace Taba\LogIssues\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Taba\LogIssues\Pipeline\OccurrenceStore;
use Taba\LogIssues\Settings;

final class StatusCommand extends Command
{
    protected $signature = 'log-issues:status
        {--reset : Close the circuit breaker and clear the last error}';

    protected $description = 'Show reported error fingerprints, the circuit breaker and the last GitHub error';

    public function handle(OccurrenceStore $store, Settings $settings, CacheFactory $cache): int
    {
        if ($this->option('reset')) {
            $repository = $cache->store($settings->cacheStore());
            $repository->forget(OccurrenceStore::PREFIX.'circuit:open_until');
            $repository->forget(OccurrenceStore::PREFIX.'circuit:failures');
            $repository->forget(OccurrenceStore::PREFIX.'last_error');
            $this->components->info('Circuit breaker closed and last error cleared.');
        }

        $entries = $store->all();
        usort($entries, static fn (array $a, array $b): int => strcmp($b['last_seen'], $a['last_seen']));

        if ($entries === []) {
            $this->components->info('No errors recorded yet.');
        } else {
            $this->table(
                ['Fingerprint', 'Issue', 'Count', 'First seen', 'Last seen', 'Title'],
                array_map(static fn (array $e): array => [
                    substr($e['fingerprint'], 0, 12),
                    $e['issue'] !== null ? '#'.$e['issue'] : ($e['deferred_until'] !== null ? 'deferred' : '-'),
                    $e['count'],
                    $e['first_seen'],
                    $e['last_seen'],
                    mb_strimwidth($e['title'], 0, 70, '…'),
                ], $entries),
            );
        }

        $openUntil = $store->circuitOpenUntil();
        $this->components->twoColumnDetail('Sending', $settings->canSend() ? '<fg=green>configured</>' : '<fg=yellow>not configured (enabled/repo/token)</>');
        $this->components->twoColumnDetail('Circuit breaker', $openUntil !== null ? "<fg=red>OPEN</> until {$openUntil}" : '<fg=green>closed</>');
        $this->components->twoColumnDetail('Consecutive failures', (string) $store->consecutiveFailures());
        $this->components->twoColumnDetail('New issues this hour', $store->newIssuesThisHour().' / '.$settings->maxNewIssuesPerHour());

        $error = $store->lastError();
        $this->components->twoColumnDetail('Last error', $error !== null ? $error['at'].' — '.$error['message'] : 'none');

        return self::SUCCESS;
    }
}
