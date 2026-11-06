<?php

declare(strict_types=1);

use Taba\LogIssues\Scan\LogFileParser;

it('parses a real multi-line Laravel log', function () {
    $entries = iterator_to_array((new LogFileParser)->parse(__DIR__.'/../Fixtures/laravel.log'), false);

    expect($entries)->toHaveCount(7);

    [$first] = $entries;
    expect($first->datetime)->toBe('2026-11-06 10:00:00')
        ->and($first->environment)->toBe('production')
        ->and($first->level)->toBe('error')
        ->and($first->exceptionClass)->toBe('Illuminate\Database\QueryException')
        ->and($first->message)->toStartWith("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '15'")
        ->and($first->file)->toBe('/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php')
        ->and($first->line)->toBe(825)
        ->and($first->previous)->toBe([[
            'class' => 'PDOException',
            'message' => "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '15' for key 'orders.PRIMARY'",
        ]]);

    expect($first->trace[2])->toBe([
        'class' => 'Illuminate\Database\Connection', 'type' => '->', 'function' => 'insert',
        'file' => '/var/www/html/app/Services/OrderService.php', 'line' => 88,
    ])
        ->and($first->trace[4])->toBe(['class' => 'App\Http\Controllers\OrderController', 'type' => '->', 'function' => 'store'])
        ->and($first->trace[6]['function'])->toBe('{closure}')
        ->and($first->trace)->toHaveCount(8);
});

it('parses plain entries without an exception', function () {
    $entries = iterator_to_array((new LogFileParser)->parse(__DIR__.'/../Fixtures/laravel.log'), false);

    expect($entries[2]->level)->toBe('warning')
        ->and($entries[2]->message)->toBe('Slow query detected')
        ->and($entries[3]->message)->toBe('Payment gateway returned 502 for order 15')
        ->and($entries[3]->exceptionClass)->toBeNull()
        ->and($entries[6]->level)->toBe('critical')
        ->and($entries[6]->message)->toBe('Payment gateway returned 503 for order 99');
});

it('resumes from a byte offset and reports where it stopped', function () {
    $path = __DIR__.'/../Fixtures/laravel.log';
    $parser = new LogFileParser;

    $end = 0;
    iterator_to_array($parser->parse($path, 0, $end), false);
    expect($end)->toBe(filesize($path));

    $again = 0;
    expect(iterator_to_array($parser->parse($path, $end, $again), false))->toBe([])
        ->and($again)->toBe($end);
});

it('leaves a half-written last line for the next run', function () {
    $path = tempnam(sys_get_temp_dir(), 'log');
    file_put_contents($path, "[2026-11-06 10:00:00] production.ERROR: complete\n[2026-11-06 10:00:01] production.ERROR: partial");

    $end = 0;
    $entries = iterator_to_array((new LogFileParser)->parse($path, 0, $end), false);

    expect($entries)->toHaveCount(1)
        ->and($end)->toBe(strlen("[2026-11-06 10:00:00] production.ERROR: complete\n"));

    unlink($path);
});

it('returns nothing for a missing file', function () {
    expect(iterator_to_array((new LogFileParser)->parse('/does/not/exist.log'), false))->toBe([]);
});
