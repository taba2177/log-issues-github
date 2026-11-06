<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Taba\LogIssues\Jobs\ReportToGitHub;
use Taba\LogIssues\Pipeline\GitHubSync;
use Taba\LogIssues\Pipeline\ReportDispatcher;
use Taba\LogIssues\Report\Report;
use Taba\LogIssues\Tests\Fixtures\Thrower;

function pretendHttpRequest(): void
{
    $property = new ReflectionProperty(app(), 'isRunningInConsole');
    $property->setValue(app(), false);
}

it('runs synchronously on the command line', function () {
    fakeGitHub();

    Log::error('x', ['exception' => Thrower::make()]);

    expect(createdIssues())->toHaveCount(1);
});

it('waits until after the response in HTTP requests', function () {
    fakeGitHub();
    pretendHttpRequest();

    Log::error('x', ['exception' => Thrower::make()]);
    Http::assertNothingSent();

    app()->terminate();

    expect(createdIssues())->toHaveCount(1);
});

it('pushes a job to the configured queue', function () {
    config()->set('log-issues.queue', ['connection' => 'redis', 'name' => 'log-issues']);
    Queue::fake();
    fakeGitHub();

    Log::error('x', ['exception' => Thrower::make()]);

    Http::assertNothingSent();
    Queue::assertPushedOn('log-issues', ReportToGitHub::class, function (ReportToGitHub $job) {
        return $job->connection === 'redis'
            && $job->action === GitHubSync::ACTION_REPORT
            && str_contains($job->report['message'], 'Order 15 failed');
    });
});

it('reports the dispatch mode', function () {
    config()->set('log-issues.queue', ['connection' => null, 'name' => null]);
    Queue::fake();

    $mode = app(ReportDispatcher::class)->dispatch(new ReportToGitHub(['fingerprint' => 'x']));

    expect($mode)->toBe(ReportDispatcher::QUEUE);
});

it('runs a queued job end to end and never throws from it', function () {
    fakeGitHub();
    $report = ['fingerprint' => sha1('job'), 'environment' => 'testing', 'level' => 'error', 'message' => 'from the queue'];

    (new ReportToGitHub($report))->handle(app(GitHubSync::class));

    expect(createdIssues())->toHaveCount(1);
});

it('swallows GitHub failures inside the job', function () {
    Http::fake(['*' => Http::response([], 500)]);

    (new ReportToGitHub(['fingerprint' => sha1('job2'), 'environment' => 'testing']))->handle(app(GitHubSync::class));

    expect($this->safeLog)->not->toBeEmpty();
});

it('skips the job when sending is not possible', function () {
    config()->set('log-issues.token', '');

    expect(app(GitHubSync::class)->sync(Report::fromArray(['fingerprint' => 'x'])))->toBe('skipped');
});
