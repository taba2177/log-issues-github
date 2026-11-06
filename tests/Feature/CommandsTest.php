<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Taba\LogIssues\Pipeline\OccurrenceStore;
use Taba\LogIssues\Report\ReportBuilder;
use Taba\LogIssues\Scan\LogFileParser;
use Taba\LogIssues\Tests\Fixtures\Thrower;

// --- log-issues:test ----------------------------------------------------------------------

it('prints a sample issue with --dry-run and no token, without any HTTP call', function () {
    config()->set('log-issues.token', null);
    Http::fake();

    $this->artisan('log-issues:test', ['--dry-run' => true])
        ->expectsOutputToContain('token is not set')
        ->expectsOutputToContain('Sample issue (not sent)')
        ->expectsOutputToContain('Title: [testing] RuntimeException: log-issues test: sample error')
        ->expectsOutputToContain('Labels: bug, auto-reported, env:testing, severity:error')
        ->expectsOutputToContain('<!-- log-issues:fp='.sha1('custom|log-issues:test').' -->')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('redacts the sample issue', function () {
    config()->set('log-issues.token', null);

    $this->artisan('log-issues:test', ['--dry-run' => true])
        ->doesntExpectOutputToContain('test@example.com')
        ->assertSuccessful();
});

it('checks repository access during a dry run when a token is set', function () {
    fakeGitHub();

    $this->artisan('log-issues:test', ['--dry-run' => true])
        ->expectsOutputToContain('token can read acme/shop')
        ->expectsOutputToContain('private')
        ->assertSuccessful();

    expect(githubRequests('POST'))->toBe([]);
});

it('warns loudly when the repository is public', function () {
    fakeGitHub(['api.github.com/repos/acme/shop' => Http::response(['private' => false, 'has_issues' => true])]);

    $this->artisan('log-issues:test', ['--dry-run' => true])
        ->expectsOutputToContain('is a PUBLIC repository')
        ->assertSuccessful();
});

it('fails when the token cannot read the repository', function () {
    fakeGitHub(['api.github.com/repos/acme/shop' => Http::response(['message' => 'Bad credentials'], 401)]);

    $this->artisan('log-issues:test')
        ->expectsOutputToContain('The token cannot read acme/shop')
        ->assertFailed();

    expect(createdIssues())->toBe([]);
});

it('fails when issues are disabled on the repository', function () {
    fakeGitHub(['api.github.com/repos/acme/shop' => Http::response(['private' => true, 'has_issues' => false])]);

    $this->artisan('log-issues:test')->expectsOutputToContain('Issues are disabled')->assertFailed();
});

it('creates a real sample issue without --dry-run', function () {
    fakeGitHub();

    $this->artisan('log-issues:test')
        ->expectsOutputToContain('Created sample issue #7: https://github.com/acme/shop/issues/7')
        ->assertSuccessful();

    expect(createdIssues())->toHaveCount(1);
});

it('reports a failed sample issue creation', function () {
    fakeGitHub(['api.github.com/repos/acme/shop/issues' => Http::response(['message' => 'Validation Failed'], 422)]);

    $this->artisan('log-issues:test')->expectsOutputToContain('Could not create the sample issue')->assertFailed();
});

it('fails on missing token or invalid settings without --dry-run', function (string $key, mixed $value, string $message) {
    config()->set($key, $value);

    $this->artisan('log-issues:test')->expectsOutputToContain($message)->assertFailed();
})->with([
    'no token' => ['log-issues.token', '', 'token is not set'],
    'bad repo' => ['log-issues.repo', 'not a repo', 'is not in owner/name form'],
    'bad level' => ['log-issues.level', 'loud', 'is not a valid log level'],
    'bad on_closed' => ['log-issues.on_closed', 'explode', 'on_closed "explode" must be one of'],
    'bad mention_on' => ['log-issues.mention_on', 'always', 'mention_on "always" must be one of'],
    'bad sample_rate' => ['log-issues.sample_rate', 3, 'sample_rate must be between 0 and 1'],
]);

it('warns about environment, channel and circuit problems', function () {
    config()->set('log-issues.token', null);
    config()->set('log-issues.environments', ['production']);
    config()->set('log-issues.enabled', false);
    config()->set('logging.channels.stack.channels', ['single']);
    app(OccurrenceStore::class)->openCircuit(Carbon::now()->addMinutes(5));

    $this->artisan('log-issues:test', ['--dry-run' => true])
        ->expectsOutputToContain('enabled is false')
        ->expectsOutputToContain('environment "testing" is not in environments [production]')
        ->expectsOutputToContain('set LOG_STACK=single,github-issues')
        ->expectsOutputToContain('circuit breaker is open')
        ->assertSuccessful();
});

it('reports a missing channel and queue delivery', function () {
    config()->set('log-issues.token', null);
    config()->set('log-issues.queue', ['connection' => 'redis', 'name' => 'errors']);
    config()->set('logging.channels', ['stack' => ['driver' => 'stack', 'channels' => []]]);

    $this->artisan('log-issues:test', ['--dry-run' => true])
        ->expectsOutputToContain('log channel "github-issues" is not registered')
        ->expectsOutputToContain('queue redis:errors');
});

// --- log-issues:status --------------------------------------------------------------------

it('shows fingerprints, circuit state and the last error', function () {
    fakeGitHub();
    Log::error('x', ['exception' => Thrower::make()]);
    app(OccurrenceStore::class)->setLastError('GitHub comment failed: HTTP 500');
    app(OccurrenceStore::class)->openCircuit(Carbon::now()->addMinutes(3));

    // one expectation per output line: a matched line is consumed
    $this->artisan('log-issues:status')
        ->expectsOutputToContain('| #7    | 1     |')
        ->expectsOutputToContain('OPEN')
        ->expectsOutputToContain('GitHub comment failed: HTTP 500')
        ->expectsOutputToContain('1 / 10')
        ->assertSuccessful();
});

it('resets the circuit breaker', function () {
    app(OccurrenceStore::class)->openCircuit(Carbon::now()->addMinutes(3));

    $this->artisan('log-issues:status', ['--reset' => true])
        ->expectsOutputToContain('No errors recorded yet')
        ->expectsOutputToContain('closed')
        ->assertSuccessful();

    expect(app(OccurrenceStore::class)->circuitOpen())->toBeFalse();
});

// --- log-issues:scan ----------------------------------------------------------------------

function scanFixture(): string
{
    config()->set('log-issues.base_path', '/var/www/html');
    config()->set('log-issues.environments', ['production']);

    return dirname(__DIR__).'/Fixtures/laravel.log';
}

it('groups a log file by fingerprint in a dry run', function () {
    $path = scanFixture();
    Http::fake();

    $this->artisan('log-issues:scan', ['path' => $path, '--since' => 'all', '--dry-run' => true])
        ->expectsOutputToContain('7 entries scanned in 1 file(s), 2 distinct error(s) to report.')
        ->expectsOutputToContain('QueryException: SQLSTATE[23000]')
        ->expectsOutputToContain('Payment gateway returned')
        ->expectsOutputToContain('Dry run: nothing was sent')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(app(OccurrenceStore::class)->scanOffset($path))->toBe(0);
});

it('reports scanned errors and remembers the offset', function () {
    $path = scanFixture();
    fakeGitHub();

    $this->artisan('log-issues:scan', ['path' => $path, '--since' => 'all'])->assertSuccessful();

    expect(createdIssues())->toHaveCount(2)
        ->and(app(OccurrenceStore::class)->scanOffset($path))->toBe(filesize($path));

    $counts = array_column(app(OccurrenceStore::class)->all(), 'count');
    sort($counts);
    expect($counts)->toBe([2, 2]);

    $query = collect(createdIssues())->first(fn ($r) => str_contains($r['title'], 'QueryException'));
    expect($query['body'])->toContain('[`app/Services/OrderService.php:92`](https://github.com/acme/shop/blob/')
        ->toContain('| **Source** | log scan (laravel.log) |');

    $this->artisan('log-issues:scan', ['path' => $path, '--since' => 'all'])
        ->expectsOutputToContain('0 entries scanned')
        ->assertSuccessful();
});

it('applies --since', function () {
    $path = scanFixture();
    Carbon::setTestNow('2026-11-06 10:06:30');

    $this->artisan('log-issues:scan', ['path' => $path, '--since' => '5m', '--dry-run' => true])
        ->expectsOutputToContain('6 entries scanned in 1 file(s), 2 distinct error(s)')
        ->assertSuccessful();

    $this->artisan('log-issues:scan', ['path' => $path, '--since' => '2026-11-06 10:09:00', '--dry-run' => true])
        ->expectsOutputToContain('2 entries scanned in 1 file(s), 1 distinct error(s)')
        ->assertSuccessful();
});

it('rejects an invalid --since', function () {
    $this->artisan('log-issues:scan', ['path' => scanFixture(), '--since' => 'yesterday-ish'])
        ->expectsOutputToContain('Invalid --since value')
        ->assertFailed();
});

it('finds daily log files and directories', function () {
    $dir = sys_get_temp_dir().'/log-issues-'.uniqid();
    mkdir($dir.'/storage/logs', 0777, true);
    copy(scanFixture(), $dir.'/storage/logs/laravel-2026-11-06.log');
    app()->setBasePath($dir);

    $this->artisan('log-issues:scan', ['--since' => 'all', '--dry-run' => true])
        ->expectsOutputToContain('7 entries scanned in 1 file(s)')
        ->assertSuccessful();

    $this->artisan('log-issues:scan', ['path' => 'storage/logs', '--since' => '1w', '--dry-run' => true])
        ->expectsOutputToContain('in 1 file(s)')
        ->assertSuccessful();

    $this->artisan('log-issues:scan', ['path' => 'storage/logs/*.log', '--since' => '30d', '--dry-run' => true])
        ->assertSuccessful();

    $this->artisan('log-issues:scan', ['path' => 'missing.log'])
        ->expectsOutputToContain('No log files found')
        ->assertSuccessful();
});

it('produces the same fingerprint from a live record and from the log file Laravel writes', function () {
    $file = tempnam(sys_get_temp_dir(), 'laravel-log');
    $logger = new Logger('testing');
    $handler = new StreamHandler($file, Level::Debug);
    $handler->setFormatter(new LineFormatter(null, 'Y-m-d H:i:s', true, true, true)); // what Laravel uses
    $logger->pushHandler($handler);

    $exception = Thrower::make('Order 15 failed', new LogicException('inner'));
    $logger->error($exception->getMessage(), ['exception' => $exception, 'order' => 15]);

    $live = app(ReportBuilder::class)->fromRecord(new LogRecord(
        new DateTimeImmutable, 'testing', Level::Error, $exception->getMessage(), ['exception' => $exception],
    ));

    $entries = iterator_to_array((new LogFileParser)->parse($file), false);
    expect($entries)->toHaveCount(1);
    $entry = $entries[0];

    $scanned = app(ReportBuilder::class)->fromParsed(
        $entry->environment, $entry->level, $entry->exceptionClass, $entry->message,
        $entry->file, $entry->line, $entry->trace, $entry->previous, '2026-11-06T10:00:00+00:00',
    );

    expect($entry->exceptionClass)->toBe(RuntimeException::class)
        ->and($entry->previous[0]['class'])->toBe(LogicException::class)
        ->and($scanned->fingerprint)->toBe($live->fingerprint)
        ->and(count($scanned->frames))->toBe(count($live->frames));

    unlink($file);
});
