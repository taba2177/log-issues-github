<?php

declare(strict_types=1);

namespace Taba\LogIssues\Pipeline;

use Illuminate\Support\Carbon;
use Monolog\LogRecord;
use Taba\LogIssues\GitHub\GitHubException;
use Taba\LogIssues\GitHub\IssueFormatter;
use Taba\LogIssues\Jobs\ReportToGitHub;
use Taba\LogIssues\Report\Redactor;
use Taba\LogIssues\Report\Report;
use Taba\LogIssues\Report\ReportBuilder;
use Taba\LogIssues\Settings;
use Throwable;

/**
 * The pipeline: guards → build → fingerprint → redact → count → (maybe) dispatch to GitHub.
 */
final class Reporter
{
    /** @var (\Closure(): float)|null */
    private ?\Closure $random = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly ReportBuilder $builder,
        private readonly Redactor $redactor,
        private readonly OccurrenceStore $store,
        private readonly IssueFormatter $formatter,
        private readonly ReportDispatcher $dispatcher,
    ) {}

    /** Test seam for sample_rate. */
    public function useRandom(?\Closure $random): void
    {
        $this->random = $random;
    }

    /**
     * Entry point for the Monolog handler.
     *
     * @return string|null What happened: null when filtered out, else the occurrence decision.
     */
    public function handleRecord(LogRecord $record): ?string
    {
        if (! $this->settings->enabled()) {
            return null;
        }

        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable && $this->isOwnException($exception)) {
            return null;
        }

        $report = $this->builder->fromRecord($record);

        if (! $this->shouldReport($report) || ! $this->sampled()) {
            return null;
        }

        return $this->process($report);
    }

    /**
     * Shared filters for live records and scanned log entries.
     */
    public function shouldReport(Report $report): bool
    {
        if (! $this->settings->enabled() || ! $this->settings->environmentAllowed($report->environment)) {
            return false;
        }

        $level = Settings::parseLevel($report->level);
        if ($level === null || $level->isLowerThan($this->settings->level())) {
            return false;
        }

        if ($report->exceptionClass !== null) {
            foreach ($this->settings->ignoreExceptions() as $ignored) {
                if ($report->exceptionClass === $ignored || is_a($report->exceptionClass, $ignored, true)) {
                    return false;
                }
            }
        }

        foreach ($this->settings->ignoreMessages() as $pattern) {
            if (@preg_match($pattern, $report->message) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Counts the occurrence(s) and dispatches a GitHub action when one is due.
     *
     * @return string counted|deferred|circuit_open|dispatched:<action>:<mode>
     */
    public function process(Report $report, int $times = 1, ?Carbon $at = null): string
    {
        $report = $this->redactor->report($report);
        $fingerprint = $report->fingerprint;
        $title = $this->formatter->title($report);

        $before = $this->store->get($fingerprint);
        $entry = $this->store->record($fingerprint, $title, $report->level, $report->environment, $times, $at);

        if (! $this->settings->canSend()) {
            return 'counted';
        }

        if ($this->store->circuitOpen()) {
            return 'circuit_open';
        }

        if ($entry['issue'] === null) {
            if ($entry['deferred_until'] !== null && Carbon::parse($entry['deferred_until'])->isFuture()) {
                return 'deferred';
            }
            if ($this->store->newIssuesThisHour() >= $this->settings->maxNewIssuesPerHour()) {
                $this->store->defer($fingerprint, Carbon::now()->addHour()->startOfHour());

                return 'deferred';
            }
            if (! $this->store->acquirePending($fingerprint)) {
                return 'counted';
            }

            return $this->dispatch($report, GitHubSync::ACTION_REPORT);
        }

        $previousCount = $before['count'] ?? 0;
        $milestone = $this->crossedMilestone($previousCount, $entry['count']);
        $cooldownOver = $entry['last_comment_at'] === null
            || Carbon::parse($entry['last_comment_at'])->addMinutes($this->settings->commentCooldownMinutes())->lessThanOrEqualTo(Carbon::now());

        if (($milestone || $cooldownOver) && $entry['count'] > $entry['count_at_last_comment']) {
            // Optimistic: stops a burst from dispatching the same comment many times.
            $this->store->markCommented($fingerprint, $entry['count_at_last_comment']);

            return $this->dispatch($report, $milestone ? GitHubSync::ACTION_MILESTONE : GitHubSync::ACTION_UPDATE);
        }

        return 'counted';
    }

    private function dispatch(Report $report, string $action): string
    {
        $mode = $this->dispatcher->dispatch(new ReportToGitHub($report->toArray(), $action));

        return 'dispatched:'.$action.':'.$mode;
    }

    private function crossedMilestone(int $before, int $after): bool
    {
        foreach ($this->settings->milestones() as $milestone) {
            if ($before < $milestone && $after >= $milestone) {
                return true;
            }
        }

        return false;
    }

    private function sampled(): bool
    {
        $rate = $this->settings->sampleRate();
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        $random = $this->random !== null ? ($this->random)() : mt_rand() / mt_getrandmax();

        return $random < $rate;
    }

    /** Errors raised by the package itself must never become issues (no feedback loops). */
    private function isOwnException(Throwable $e): bool
    {
        if ($e instanceof GitHubException) {
            return true;
        }

        $file = str_replace('\\', '/', $e->getFile());
        $src = str_replace('\\', '/', dirname(__DIR__)).'/';

        return str_starts_with($file, $src);
    }
}
