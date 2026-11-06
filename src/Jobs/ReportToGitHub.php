<?php

declare(strict_types=1);

namespace Taba\LogIssues\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Taba\LogIssues\Pipeline\GitHubSync;
use Taba\LogIssues\Report\Report;
use Taba\LogIssues\Support\Guard;
use Taba\LogIssues\Support\SafeLog;
use Throwable;

/**
 * Sends one (already redacted) report to GitHub. Runs queued, after the response, or inline.
 * It never throws, so a queue worker never logs a failure back into the package.
 */
final class ReportToGitHub implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    /** @param  array<string, mixed>  $report */
    public function __construct(
        public readonly array $report,
        public readonly string $action = GitHubSync::ACTION_REPORT,
    ) {}

    public function handle(GitHubSync $sync): void
    {
        try {
            Guard::run(fn (): string => $sync->sync(Report::fromArray($this->report), $this->action));
        } catch (Throwable $e) {
            SafeLog::write($e);
        }
    }
}
