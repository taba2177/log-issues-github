<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Taba\LogIssues\Logging\GitHubIssueHandler;
use Taba\LogIssues\LogIssuesServiceProvider;

it('registers the github-issues channel by itself', function () {
    expect(config('logging.channels.github-issues'))->toMatchArray([
        'driver' => 'monolog',
        'handler' => GitHubIssueHandler::class,
        'level' => 'error',
    ]);

    $handlers = Log::channel('github-issues')->getLogger()->getHandlers();

    expect($handlers)->toHaveCount(1)
        ->and($handlers[0])->toBeInstanceOf(GitHubIssueHandler::class);
});

it('does not overwrite an existing channel with the same name', function () {
    config()->set('logging.channels.github-issues', ['driver' => 'single', 'path' => '/tmp/x.log']);

    (new LogIssuesServiceProvider(app()))->register();

    expect(config('logging.channels.github-issues.driver'))->toBe('single');
});

it('merges the package config and publishes it under the log-issues-config tag', function () {
    expect(config('log-issues.on_closed'))->toBe('reopen')
        ->and(config('log-issues.max_new_issues_per_hour'))->toBe(10)
        ->and(LogIssuesServiceProvider::pathsToPublish(LogIssuesServiceProvider::class, 'log-issues-config'))->toHaveCount(1);
});

it('registers the artisan commands', function () {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('log-issues:test', 'log-issues:scan', 'log-issues:status');
});

it('builds the channel level from config', function () {
    config()->set('logging.channels.github-issues.level', 'warning');

    $handler = Log::channel('github-issues')->getLogger()->getHandlers()[0];

    expect($handler->getLevel()->getName())->toBe('WARNING');
});
