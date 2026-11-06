<?php

declare(strict_types=1);

use Taba\LogIssues\Report\Fingerprinter;
use Taba\LogIssues\Report\Frame;

function appFrame(string $file = 'app/Services/OrderService.php', int $line = 88, string $function = 'App\Services\OrderService->place'): Frame
{
    return new Frame($file, $line, $function, true);
}

it('normalizes variable parts of messages', function (string $message, string $expected) {
    expect(Fingerprinter::normalize($message))->toBe($expected);
})->with([
    'numbers' => ['User 123 not found', 'User {n} not found'],
    'uuid' => ['Order 9b2f6c1e-2d4b-4c4e-9a7e-1f2b3c4d5e6f failed', 'Order {uuid} failed'],
    'hex id' => ['Object 5f2b9a8c7d6e5f4a missing', 'Object {hex} missing'],
    'quoted' => ['Column "email" and \'name\' invalid', 'Column {str} and {str} invalid'],
    'email' => ['Cannot mail jane.doe@example.com', 'Cannot mail {email}'],
    'url' => ['GET https://api.example.com/v1/items?id=4 timed out', 'GET {url} timed out'],
    'whitespace' => ["Line one\n   line   two", 'Line one line two'],
    'decimals' => ['Took 12.5 seconds', 'Took {n} seconds'],
]);

it('gives the same fingerprint when only ids differ', function () {
    $f = new Fingerprinter;

    expect($f->fingerprint('RuntimeException', 'User 123 not found', [appFrame()]))
        ->toBe($f->fingerprint('RuntimeException', 'User 456 not found', [appFrame()]));
});

it('is stable across line shifts but not across functions or classes', function () {
    $f = new Fingerprinter;
    $base = $f->fingerprint('RuntimeException', 'boom', [appFrame(line: 10)]);

    expect($f->fingerprint('RuntimeException', 'boom', [appFrame(line: 99)]))->toBe($base)
        ->and($f->fingerprint('RuntimeException', 'boom', [appFrame(function: 'App\Services\OrderService->cancel')]))->not->toBe($base)
        ->and($f->fingerprint('LogicException', 'boom', [appFrame()]))->not->toBe($base);
});

it('anchors on the first application frame, skipping vendor frames', function () {
    $f = new Fingerprinter;
    $vendor = new Frame('vendor/laravel/framework/src/Illuminate/Database/Connection.php', 825, 'Illuminate\Database\Connection->runQueryCallback', false);
    $otherVendor = new Frame('vendor/laravel/framework/src/Illuminate/Database/Connection.php', 999, 'Illuminate\Database\Connection->run', false);

    expect($f->fingerprint('QueryException', 'x', [$vendor, appFrame()]))
        ->toBe($f->fingerprint('QueryException', 'x', [$otherVendor, appFrame()]));
});

it('falls back to the first frame when there is no application frame', function () {
    $f = new Fingerprinter;
    $a = new Frame('vendor/a.php', 1, 'A->a', false);
    $b = new Frame('vendor/b.php', 1, 'B->b', false);

    expect($f->fingerprint(null, 'x', [$a]))->not->toBe($f->fingerprint(null, 'x', [$b]))
        ->and($f->fingerprint(null, 'x', []))->toHaveLength(40);
});

it('lets a context key override the fingerprint', function () {
    $f = new Fingerprinter;

    expect($f->fingerprint('A', 'one', [appFrame()], 'checkout-timeout'))
        ->toBe($f->fingerprint('B', 'two', [], 'checkout-timeout'))
        ->toBe(sha1('custom|checkout-timeout'));
});
