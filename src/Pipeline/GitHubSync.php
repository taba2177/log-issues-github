<?php

declare(strict_types=1);

namespace Taba\LogIssues\Pipeline;

use Illuminate\Support\Carbon;
use Taba\LogIssues\GitHub\GitHubClient;
use Taba\LogIssues\GitHub\IssueFormatter;
use Taba\LogIssues\Report\Report;
use Taba\LogIssues\Settings;
use Taba\LogIssues\Support\SafeLog;
use Throwable;

/**
 * Talks to GitHub for one report: find or create the issue, comment, reopen. Never throws.
 */
final class GitHubSync
{
    public const ACTION_REPORT = 'report';

    public const ACTION_UPDATE = 'update';

    public const ACTION_MILESTONE = 'milestone';

    public function __construct(
        private readonly Settings $settings,
        private readonly OccurrenceStore $store,
        private readonly GitHubClient $client,
        private readonly IssueFormatter $formatter,
    ) {}

    /**
     * @return string created|commented|reopened|ignored|capped|skipped|failed
     */
    public function sync(Report $report, string $action = self::ACTION_REPORT): string
    {
        $fingerprint = $report->fingerprint;

        try {
            if (! $this->settings->canSend() || $this->store->circuitOpen()) {
                return 'skipped';
            }

            $entry = $this->store->get($fingerprint) ?? $this->store->record(
                $fingerprint, $this->formatter->title($report), $report->level, $report->environment,
            );

            $number = $entry['issue'];
            $state = null;

            if ($number === null) {
                $found = $this->client->findIssueByMarker($fingerprint);
                if ($found === null) {
                    return $this->create($report, $entry);
                }
                $number = $found['number'];
                $state = $found['state'];
                $this->store->setIssue($fingerprint, $number, $found['html_url']);
            }

            if ($state === null) {
                $issue = $this->client->issue($number);
                if ($issue === null) {
                    $this->store->setIssue($fingerprint, null);

                    return $this->create($report, $entry);
                }
                $state = $issue['state'];
            }

            if ($state === 'closed') {
                return $this->closed($report, $entry, $number);
            }

            $this->client->comment($number, $this->formatter->comment($report, $entry, $action === self::ACTION_MILESTONE));
            $this->store->markCommented($fingerprint, $entry['count']);

            return 'commented';
        } catch (Throwable $e) {
            $this->fail($e);

            return 'failed';
        } finally {
            try {
                $this->store->releasePending($fingerprint);
            } catch (Throwable) {
                // cache unavailable — the lock expires on its own
            }
        }
    }

    /** @param  array<string, mixed>  $entry */
    private function closed(Report $report, array $entry, int $number): string
    {
        switch ($this->settings->onClosed()) {
            case 'ignore':
                $this->store->markCommented($report->fingerprint, (int) $entry['count']);

                return 'ignored';
            case 'new':
                return $this->create($report, $entry, $number);
            default:
                $this->client->reopen($number);
                $this->client->comment($number, $this->formatter->reopenComment($report, $entry));
                $this->store->markCommented($report->fingerprint, (int) $entry['count']);

                return 'reopened';
        }
    }

    /** @param  array<string, mixed>  $entry */
    private function create(Report $report, array $entry, ?int $previousIssue = null): string
    {
        if (! $this->store->reserveNewIssue($this->settings->maxNewIssuesPerHour())) {
            $this->store->defer($report->fingerprint, Carbon::now()->addHour()->startOfHour());

            return 'capped';
        }

        $issue = $this->client->createIssue(
            $this->formatter->title($report),
            $this->formatter->body($report, $entry, $previousIssue),
            $this->formatter->labels($report),
        );

        $this->store->setIssue($report->fingerprint, $issue['number'], $issue['html_url']);
        $this->store->markCommented($report->fingerprint, (int) $entry['count']);

        return 'created';
    }

    private function fail(Throwable $e): void
    {
        try {
            $this->store->setLastError($e->getMessage());
        } catch (Throwable) {
            // ignore
        }
        SafeLog::write($e);
    }
}
