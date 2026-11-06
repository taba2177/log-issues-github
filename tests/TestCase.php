<?php

declare(strict_types=1);

namespace Taba\LogIssues\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Taba\LogIssues\LogIssuesServiceProvider;
use Taba\LogIssues\Support\SafeLog;

abstract class TestCase extends Orchestra
{
    /** @var list<string> */
    public array $safeLog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->safeLog = [];
        SafeLog::using(function (string $message): void {
            $this->safeLog[] = $message;
        });

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        SafeLog::using(null);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [LogIssuesServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $config->set('cache.default', 'array');
        $config->set('queue.default', 'sync');

        $config->set('logging.default', 'stack');
        $config->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['github-issues']]);

        $config->set('log-issues.repo', 'acme/shop');
        $config->set('log-issues.token', 'github_pat_TESTTOKEN0123456789abcdef');
        $config->set('log-issues.environments', ['testing', 'production']);
        $config->set('log-issues.mention', '@claude');
        $config->set('log-issues.retries', 0);
        $config->set('log-issues.release', 'abc1234def5678');
        $config->set('log-issues.base_path', dirname(__DIR__));
    }
}
