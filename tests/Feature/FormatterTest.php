<?php

declare(strict_types=1);

use Taba\LogIssues\GitHub\IssueFormatter;
use Taba\LogIssues\Report\Frame;
use Taba\LogIssues\Report\Report;

function sampleReport(array $overrides = []): Report
{
    return Report::fromArray($overrides + [
        'fingerprint' => str_repeat('f', 40),
        'environment' => 'production',
        'level' => 'error',
        'exceptionClass' => 'Illuminate\Database\QueryException',
        'message' => "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '15' for key 'orders.PRIMARY'",
        'frames' => [
            new Frame('vendor/laravel/framework/src/Illuminate/Database/Connection.php', 825, 'Illuminate\Database\Connection->runQueryCallback', false),
            new Frame('app/Services/OrderService.php', 88, 'App\Services\OrderService->place', true),
            new Frame('app/Http/Controllers/OrderController.php', 31, 'App\Http\Controllers\OrderController->store', true),
        ],
        'previous' => [['class' => 'PDOException', 'message' => 'SQLSTATE[23000]']],
        'context' => [],
        'runtime' => ['method' => 'POST', 'route' => 'orders.store', 'job' => 'App\Jobs\SyncOrder', 'command' => 'queue:work', 'authenticated' => true, 'php' => '8.3.1', 'laravel' => '12.69.0', 'release' => 'v1.2.3', 'ref' => 'main'],
        'occurredAt' => '2026-11-06T10:00:00+00:00',
    ]);
}

function formatter(): IssueFormatter
{
    return app(IssueFormatter::class);
}

it('builds a short title with the first application frame', function () {
    $title = formatter()->title(sampleReport());

    expect($title)->toStartWith('[production] QueryException: SQLSTATE[23000]: Integrity constraint')
        ->toEndWith('… (app/Services/OrderService.php:88)')
        ->and(mb_strlen($title))->toBeLessThanOrEqual(120);
});

it('keeps titles within 120 characters even with huge messages and paths', function () {
    $title = formatter()->title(sampleReport([
        'message' => str_repeat('very long message ', 50),
        'frames' => [new Frame(str_repeat('deep/', 30).'File.php', 1, 'x', true)],
    ]));

    expect(mb_strlen($title))->toBeLessThanOrEqual(120);
});

it('renders the summary, chain, frames and marker', function () {
    $body = formatter()->body(sampleReport(), ['count' => 12, 'first_seen' => '2026-11-01T08:00:00+00:00', 'last_seen' => '2026-11-06T10:00:00+00:00']);

    expect($body)
        ->toContain('| **Occurrences** | 12 |')
        ->toContain('| **First seen** | 2026-11-01 08:00:00 UTC |')
        ->toContain('| **Route** | `POST orders.store` |')
        ->toContain('| **Command** | `queue:work` |')
        ->toContain('| **Queue job** | `App\Jobs\SyncOrder` |')
        ->toContain('| **Authenticated** | yes |')
        ->toContain('| **PHP / Laravel** | 8.3.1 / 12.69.0 |')
        ->toContain('- `PDOException`: SQLSTATE[23000]')
        ->toContain('<details open><summary>Application frames (2)</summary>')
        ->toContain('[`app/Services/OrderService.php:88`](https://github.com/acme/shop/blob/abc1234def5678/app/Services/OrderService.php#L88)')
        ->toContain('<details><summary>Vendor / framework frames (1)</summary>')
        ->toContain('<!-- log-issues:fp='.str_repeat('f', 40).' -->');
});

it('links to the configured branch when the release is not a commit', function () {
    config()->set('log-issues.release', 'v2.0.0');
    config()->set('log-issues.branch', 'production');

    expect(formatter()->body(sampleReport(), []))->toContain('/blob/production/app/Services/OrderService.php#L88');
});

it('supports GitHub Enterprise web URLs', function () {
    config()->set('log-issues.web_url', 'https://ghe.example.com/');

    expect(formatter()->body(sampleReport(), []))->toContain('https://ghe.example.com/acme/shop/blob/');
});

it('omits the mention when mention_on is never', function () {
    config()->set('log-issues.mention_on', 'never');

    expect(formatter()->body(sampleReport(), []))
        ->not->toContain('@claude')
        ->toContain('### Agent brief')
        ->toContain('Please investigate this production error');
});

it('uses a custom agent brief with placeholders', function () {
    config()->set('log-issues.mention', '@dsh-superagent');
    config()->set('log-issues.agent_brief', '{mention} fix {fingerprint} in {repo} ({environment}/{level})');

    expect(formatter()->brief(sampleReport()))->toBe('@dsh-superagent fix '.str_repeat('f', 40).' in acme/shop (production/error)');
});

it('renders a Blade agent brief', function () {
    config()->set('log-issues.agent_brief', '{{ $mention }} @if($environment === "production")urgent: @endif{{ $title }}');

    expect(formatter()->brief(sampleReport()))->toStartWith('@claude urgent: [production] QueryException');
});

it('escapes code fences inside messages', function () {
    $body = formatter()->body(sampleReport(['message' => "before\n```\nafter"]), []);

    // message fence + vendor frames fence only; the fence inside the message is neutralised
    expect(substr_count($body, '```'))->toBe(4)
        ->and($body)->toContain("before\n`\u{200B}``\nafter");
});

it('adds the severity label only when enabled', function () {
    config()->set('log-issues.severity_label', false);

    expect(formatter()->labels(sampleReport()))->toBe(['bug', 'auto-reported', 'env:production']);
});

it('handles reports without exception or frames', function () {
    $report = sampleReport(['exceptionClass' => null, 'frames' => [], 'previous' => [], 'message' => '', 'level' => 'critical', 'runtime' => ['source' => 'log scan (laravel.log)']]);

    expect(formatter()->title($report))->toBe('[production] CRITICAL: Log entry')
        ->and(formatter()->body($report, []))->toContain('_No stack trace available._')
        ->toContain('| **Source** | log scan (laravel.log) |')
        ->toContain('(empty message)');
});

it('truncates very large bodies', function () {
    $frames = [];
    foreach (range(1, 60) as $i) {
        $frames[] = new Frame('vendor/pkg/'.str_repeat('x', 900).$i.'.php', $i, 'Fn', false);
    }

    $body = formatter()->body(sampleReport(['frames' => $frames, 'context' => ['blob' => str_repeat('y', 9000)]]), []);

    expect(mb_strlen($body))->toBeLessThan(IssueFormatter::BODY_LIMIT + 500)
        ->and($body)->toContain('… 20 more');
});
