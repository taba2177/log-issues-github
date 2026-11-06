<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Taba\LogIssues\GitHub\GitHubException;
use Taba\LogIssues\Pipeline\OccurrenceStore;
use Taba\LogIssues\Pipeline\Reporter;
use Taba\LogIssues\Tests\Fixtures\Thrower;

function logError(string $message = 'Order 15 failed', array $context = []): void
{
    Log::error($message, ['exception' => Thrower::make($message)] + $context);
}

function store(): OccurrenceStore
{
    return app(OccurrenceStore::class);
}

it('creates one GitHub issue for an error', function () {
    fakeGitHub();

    logError('Order 15 failed', ['password' => 'hunter2', 'order' => 15]);

    expect(createdIssues())->toHaveCount(1);
    /** @var Request $request */
    $request = createdIssues()[0];

    expect($request['title'])->toBe('[testing] RuntimeException: Order 15 failed (tests/Fixtures/Thrower.php:14)')
        ->and($request['labels'])->toBe(['bug', 'auto-reported', 'env:testing', 'severity:error'])
        ->and($request['body'])
        ->toContain('<!-- log-issues:fp=')
        ->toContain('@claude Please investigate this testing error')
        ->toContain('https://github.com/acme/shop/blob/abc1234def5678/tests/Fixtures/Thrower.php#L14')
        ->toContain('| **Authenticated** | no |')
        ->toContain('"order": 15')
        ->not->toContain('hunter2')
        ->and($request->header('Authorization')[0])->toBe('Bearer github_pat_TESTTOKEN0123456789abcdef')
        ->and($request->header('X-GitHub-Api-Version')[0])->toBe('2022-11-28');

    $search = githubRequests('GET', '/search/issues')[0];
    expect(urldecode($search->url()))->toContain('repo:acme/shop is:issue in:body "log-issues:fp=');
});

it('ignores records below the configured level', function () {
    fakeGitHub();

    Log::warning('just a warning');
    Log::info('fyi');

    Http::assertNothingSent();
});

it('reports warnings when the level is lowered', function () {
    config()->set('log-issues.level', 'warning');
    config()->set('logging.channels.github-issues.level', 'warning');
    fakeGitHub();

    Log::warning('Disk almost full on node 3');

    expect(createdIssues())->toHaveCount(1)
        ->and(createdIssues()[0]['title'])->toStartWith('[testing] WARNING: Disk almost full on node 3 (tests/Feature/PipelineTest.php:');
});

it('only reports allowed environments', function () {
    config()->set('log-issues.environments', ['production']);
    fakeGitHub();

    logError();

    Http::assertNothingSent();
});

it('reports every environment with *', function () {
    config()->set('log-issues.environments', ['*']);
    fakeGitHub();

    logError();

    expect(createdIssues())->toHaveCount(1);
});

it('ignores the default noisy exceptions', function (Throwable $e) {
    fakeGitHub();

    Log::error($e->getMessage(), ['exception' => $e]);

    Http::assertNothingSent();
})->with([
    'not found' => fn () => new NotFoundHttpException('nope'),
    'model not found' => fn () => new ModelNotFoundException('missing'),
    'unauthenticated' => fn () => new AuthenticationException,
]);

it('ignores messages matching ignore_messages', function () {
    config()->set('log-issues.ignore_messages', ['/^Order \d+ failed$/', '/[broken/']);
    fakeGitHub();

    logError('Order 99 failed');

    Http::assertNothingSent();
});

it('does nothing when disabled, sampled out or not configured', function (string $key, mixed $value) {
    config()->set($key, $value);
    fakeGitHub();

    logError();

    Http::assertNothingSent();
})->with([
    'disabled' => ['log-issues.enabled', false],
    'sample rate 0' => ['log-issues.sample_rate', 0.0],
    'no token' => ['log-issues.token', null],
    'no repo' => ['log-issues.repo', ''],
]);

it('counts locally even without a token', function () {
    config()->set('log-issues.token', null);

    logError();
    logError();

    expect(store()->all())->toHaveCount(1)
        ->and(store()->all()[0]['count'])->toBe(2);
});

it('samples with sample_rate', function () {
    config()->set('log-issues.sample_rate', 0.5);
    fakeGitHub();
    $reporter = app(Reporter::class);

    $reporter->useRandom(fn () => 0.9);
    logError();
    Http::assertNothingSent();

    $reporter->useRandom(fn () => 0.1);
    logError();
    expect(createdIssues())->toHaveCount(1);
});

it('deduplicates through the cache', function () {
    fakeGitHub();

    logError('Order 15 failed');
    logError('Order 16 failed');
    logError('Order 17 failed');

    expect(createdIssues())->toHaveCount(1)
        ->and(githubRequests('GET', '/search/issues'))->toHaveCount(1)
        ->and(comments())->toHaveCount(0)
        ->and(store()->all()[0])->toMatchArray(['count' => 3, 'issue' => 7]);
});

it('deduplicates through the search marker when the cache is empty', function () {
    $fingerprint = sha1('custom|checkout');
    fakeGitHub([
        'api.github.com/search/issues*' => Http::response(['total_count' => 2, 'items' => [
            ['number' => 41, 'state' => 'open', 'html_url' => 'u41', 'body' => 'mentions log-issues:fp=other'],
            ['number' => 42, 'state' => 'open', 'html_url' => 'u42', 'body' => "x\n<!-- log-issues:fp={$fingerprint} -->"],
        ]]),
    ]);

    logError('Checkout failed', ['fingerprint' => 'checkout']);

    expect(createdIssues())->toHaveCount(0)
        ->and(comments())->toHaveCount(1)
        ->and(comments()[0]->url())->toEndWith('/repos/acme/shop/issues/42/comments')
        ->and(store()->get($fingerprint)['issue'])->toBe(42);
});

it('creates an issue when the search only finds look-alikes', function () {
    fakeGitHub([
        'api.github.com/search/issues*' => Http::response(['total_count' => 1, 'items' => [
            ['number' => 41, 'state' => 'open', 'html_url' => 'u41', 'body' => 'log-issues:fp=somethingelse'],
        ]]),
    ]);

    logError();

    expect(createdIssues())->toHaveCount(1);
});

it('comments at occurrence milestones', function () {
    config()->set('log-issues.mention_on', 'milestone');
    fakeGitHub();

    foreach (range(1, 9) as $i) {
        logError("Order {$i} failed");
    }
    expect(comments())->toHaveCount(0);

    logError('Order 10 failed');

    expect(comments())->toHaveCount(1)
        ->and(comments()[0]['body'])->toContain('**Milestone:** this error has now occurred **10** times')
        ->toContain('@claude this error keeps happening');
});

it('never mentions on milestone comments by default', function () {
    fakeGitHub();

    foreach (range(1, 10) as $i) {
        logError("Order {$i} failed");
    }

    expect(comments())->toHaveCount(1)
        ->and(comments()[0]['body'])->not->toContain('@claude');
});

it('comments at most once per cooldown with an aggregate count', function () {
    Carbon::setTestNow('2026-11-06 10:00:00');
    fakeGitHub();

    logError();                                   // creates
    Carbon::setTestNow('2026-11-06 10:30:00');
    logError();                                   // within cooldown
    expect(comments())->toHaveCount(0);

    Carbon::setTestNow('2026-11-06 11:01:00');
    logError();                                   // cooldown over

    expect(comments())->toHaveCount(1)
        ->and(comments()[0]['body'])->toContain('Seen **2** more time(s) since the last update (**3** total)')
        ->not->toContain('@claude');

    logError();
    expect(comments())->toHaveCount(1);
});

it('handles a closed issue according to on_closed', function (string $mode, int $creates, int $comments, int $patches) {
    config()->set('log-issues.on_closed', $mode);
    Carbon::setTestNow('2026-11-06 10:00:00');
    fakeGitHub([
        'api.github.com/repos/acme/shop/issues/7' => Http::response(['number' => 7, 'state' => 'closed', 'html_url' => 'u7']),
    ]);

    logError();
    Carbon::setTestNow('2026-11-06 12:00:00');
    logError();

    expect(createdIssues())->toHaveCount($creates)
        ->and(comments())->toHaveCount($comments)
        ->and(githubRequests('PATCH'))->toHaveCount($patches);

    if ($mode === 'reopen') {
        expect(githubRequests('PATCH')[0]['state'])->toBe('open')
            ->and(comments()[0]['body'])->toContain('Reopened automatically');
    }
    if ($mode === 'new') {
        expect(createdIssues()[1]['body'])->toContain('came back after #7 was closed');
    }
})->with([
    'reopen' => ['reopen', 1, 1, 1],
    'new' => ['new', 2, 0, 0],
    'ignore' => ['ignore', 1, 0, 0],
]);

it('recreates the issue when the known one was deleted', function () {
    Carbon::setTestNow('2026-11-06 10:00:00');
    fakeGitHub(['api.github.com/repos/acme/shop/issues/7' => Http::response(['message' => 'Not Found'], 404)]);

    logError();
    Carbon::setTestNow('2026-11-06 12:00:00');
    logError();

    expect(createdIssues())->toHaveCount(2);
});

it('caps new issues per hour and keeps counting', function () {
    config()->set('log-issues.max_new_issues_per_hour', 2);
    Carbon::setTestNow('2026-11-06 10:00:00');
    fakeGitHub();

    Log::error('a', ['exception' => new RuntimeException('first')]);
    Log::error('b', ['exception' => new LogicException('second')]);
    Log::error('c', ['exception' => new DomainException('third')]);
    Log::error('c', ['exception' => new DomainException('third')]);

    expect(createdIssues())->toHaveCount(2);
    $deferred = array_values(array_filter(store()->all(), fn ($e) => $e['issue'] === null));
    expect($deferred)->toHaveCount(1)
        ->and($deferred[0]['count'])->toBe(2)
        ->and($deferred[0]['deferred_until'])->not->toBeNull();

    Carbon::setTestNow('2026-11-06 11:00:01');
    Log::error('c', ['exception' => new DomainException('third')]);

    expect(createdIssues())->toHaveCount(3);
});

it('opens the circuit breaker after 5 consecutive failures', function () {
    Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $i => $letter) {
        Log::error($letter, ['exception' => Thrower::make("Failure {$letter}"), 'fingerprint' => $letter]);
    }

    expect(Http::recorded())->toHaveCount(5)
        ->and(store()->circuitOpen())->toBeTrue()
        ->and(store()->lastError()['message'])->toContain('HTTP 500');

    Carbon::setTestNow(Carbon::now()->addMinutes(16));
    expect(store()->circuitOpen())->toBeFalse();
});

it('pauses when GitHub reports an exhausted rate limit', function () {
    Carbon::setTestNow('2026-11-06 10:00:00');
    Http::fake(['*' => Http::response(['message' => 'API rate limit exceeded'], 403, [
        'X-RateLimit-Remaining' => '0',
        'X-RateLimit-Reset' => (string) Carbon::parse('2026-11-06 10:20:00')->timestamp,
    ])]);

    logError();

    expect(store()->circuitOpenUntil())->toBe(Carbon::parse('2026-11-06 10:20:00')->toAtomString());
});

it('honours Retry-After', function () {
    Carbon::setTestNow('2026-11-06 10:00:00');
    Http::fake(['*' => Http::response(['message' => 'secondary rate limit'], 429, ['Retry-After' => '90'])]);

    logError();

    expect(store()->circuitOpenUntil())->toBe(Carbon::parse('2026-11-06 10:01:30')->toAtomString());
});

it('never lets an exception escape when GitHub fails', function (int $status) {
    Http::fake(['*' => Http::response(['message' => 'nope'], $status)]);

    logError();

    expect($this->safeLog)->not->toBeEmpty()
        ->and(store()->lastError()['message'])->toContain("HTTP {$status}");
})->with([401, 403, 404, 422, 500]);

it('never lets an exception escape on timeouts', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    logError();

    expect($this->safeLog[0])->toContain('timed out');
});

it('retries server errors with backoff', function () {
    config()->set('log-issues.retries', 1);
    Http::fakeSequence('api.github.com/search/issues*')
        ->push(['message' => 'oops'], 502)
        ->push(['total_count' => 0, 'items' => []]);
    fakeGitHub();

    logError();

    expect(githubRequests('GET', '/search/issues'))->toHaveCount(2)
        ->and(createdIssues())->toHaveCount(1);
});

it('does not recurse when something logs while the package is working', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        Log::error('logged from inside the HTTP client', ['exception' => new RuntimeException('inner')]);

        return Http::response(['total_count' => 0, 'items' => []]);
    });

    logError();

    expect($calls)->toBe(2); // one search + one create, no nested reports
});

it('swallows failures of the handler itself', function () {
    app()->bind(Reporter::class, fn () => throw new RuntimeException('reporter exploded'));

    logError();

    expect($this->safeLog[0])->toContain('reporter exploded');
});

it('ignores errors raised by the package itself', function () {
    fakeGitHub();

    Log::error('loop', ['exception' => new GitHubException('GitHub create issue failed')]);

    Http::assertNothingSent();
});

it('fingerprints plain log calls by their caller', function () {
    fakeGitHub();

    Log::error('Payment gateway returned 502 for order 15');
    Log::error('Payment gateway returned 503 for order 16');

    expect(createdIssues())->toHaveCount(1)
        ->and(store()->all()[0]['count'])->toBe(2);
});

it('includes the previous exception chain', function () {
    fakeGitHub();

    Log::error('x', ['exception' => Thrower::make('Outer', new LogicException('Inner cause for jane@example.com'))]);

    expect(createdIssues()[0]['body'])->toContain('**Caused by**')
        ->toContain('`LogicException`: Inner cause for [email]');
});
