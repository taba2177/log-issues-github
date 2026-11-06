<?php

declare(strict_types=1);

use Taba\LogIssues\Report\Redactor;
use Taba\LogIssues\Report\Report;

function redactor(): Redactor
{
    return app(Redactor::class);
}

it('masks secret shapes in free text', function (string $input, string $mustNotContain, string $mustContain) {
    $out = redactor()->string($input);

    expect($out)->not->toContain($mustNotContain)->toContain($mustContain);
})->with([
    'email' => ['Mail to jane.doe@example.com failed', 'jane.doe@example.com', '[email]'],
    'international phone' => ['Call +966 55 123 4567 now', '123 4567', '[phone]'],
    'local phone' => ['SMS to 0551234567 failed', '0551234567', '[phone]'],
    'us phone' => ['Call (555) 123-4567', '123-4567', '[phone]'],
    'card (luhn)' => ['Card 4111 1111 1111 1111 declined', '4111 1111 1111 1111', '[card]'],
    'card no spaces' => ['Card 5555555555554444 declined', '5555555555554444', '[card]'],
    'iban' => ['Pay to SA0380000000608010167519 now', 'SA0380000000608010167519', '[iban]'],
    'iban spaced' => ['IBAN GB82 WEST 1234 5698 7654 32', 'WEST 1234', '[iban]'],
    'jwt' => ['jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U', 'eyJhbGciOiJIUzI1NiJ9', '[REDACTED]'],
    'bearer' => ['Authorization: Bearer abcdef1234567890xyz', 'abcdef1234567890xyz', 'Bearer [REDACTED]'],
    'url query' => ['GET https://pay.example.com/callback?token=abc&amount=5 failed', 'token=abc', 'https://pay.example.com/callback?[REDACTED]'],
    'key=value' => ['connect failed password=hunter2 host=db', 'hunter2', 'password=[REDACTED]'],
    'github token' => ['used ghp_abcdefghijklmnopqrstuvwxyz0123456789', 'ghp_abcdefghijklmnopqrstuvwxyz0123456789', '[REDACTED]'],
]);

it('keeps numbers that are not cards, phones or dates', function () {
    $text = 'Order 4111111111111112 at 2026-11-06 14:44:00 line 88 took 1500ms';

    expect(redactor()->string($text))->toBe($text);
});

it('masks configured secret values: app key, db password and the package token', function () {
    config()->set('database.connections.mysql.password', 'db-S3cret-pass');

    $appKey = config('app.key');
    $raw = substr($appKey, 7);
    $out = redactor()->string("key {$appKey} raw {$raw} db db-S3cret-pass tok github_pat_TESTTOKEN0123456789abcdef");

    expect($out)->not->toContain($raw)
        ->not->toContain('db-S3cret-pass')
        ->not->toContain('TESTTOKEN');
});

it('masks sensitive keys at any depth', function () {
    $out = redactor()->value([
        'order' => 15,
        'password' => 'p',
        'nested' => [
            'api_key' => 'k',
            'deeper' => ['Authorization' => 'Bearer x', 'X-Cookie' => 'c', 'client_secret' => 's', 'safe' => 'ok'],
            'userId' => 42,
            'Customer-Email' => 'a@b.co',
        ],
        'list' => [['token' => 't']],
    ]);

    expect($out['order'])->toBe(15)
        ->and($out['password'])->toBe('[REDACTED]')
        ->and($out['nested']['api_key'])->toBe('[REDACTED]')
        ->and($out['nested']['deeper']['Authorization'])->toBe('[REDACTED]')
        ->and($out['nested']['deeper']['X-Cookie'])->toBe('[REDACTED]')
        ->and($out['nested']['deeper']['client_secret'])->toBe('[REDACTED]')
        ->and($out['nested']['deeper']['safe'])->toBe('ok')
        ->and($out['nested']['userId'])->toBe('[REDACTED]')
        ->and($out['nested']['Customer-Email'])->toBe('[REDACTED]')
        ->and($out['list'][0]['token'])->toBe('[REDACTED]');
});

it('applies extra keys and patterns from config', function () {
    config()->set('log-issues.redact.keys', ['national_id']);
    config()->set('log-issues.redact.patterns', ['/ORD-\d+/', '/[invalid regex/']);

    expect(redactor()->value(['national_id' => '1'])['national_id'])->toBe('[REDACTED]')
        ->and(redactor()->string('order ORD-123 failed'))->toBe('order [REDACTED] failed');
});

it('truncates long strings and limits depth and size', function () {
    config()->set('log-issues.redact.max_string_length', 100);

    $long = redactor()->string(str_repeat('a', 500));
    $deep = redactor()->value(['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 1]]]]]]]);
    $wide = redactor()->value(range(1, 80));

    expect($long)->toStartWith(str_repeat('a', 100))->toContain('[truncated 400 chars]')
        ->and($deep['a']['b']['c']['d']['e']['f'])->toBe('[…]')
        ->and($wide)->toHaveKey('…');
});

it('redacts every outgoing part of a report', function () {
    $report = Report::fromArray([
        'fingerprint' => str_repeat('a', 40),
        'environment' => 'production',
        'level' => 'error',
        'exceptionClass' => 'RuntimeException',
        'message' => 'failed for jane@example.com',
        'frames' => [],
        'previous' => [['class' => 'PDOException', 'message' => 'password=secret1']],
        'context' => ['token' => 'abc', 'note' => 'call +44 20 7946 0958'],
        'runtime' => ['route' => 'orders.show', 'php' => '8.3'],
        'occurredAt' => '2026-11-06T14:44:00+00:00',
    ]);

    $out = redactor()->report($report);

    expect($out->message)->toBe('failed for [email]')
        ->and($out->previous[0]['message'])->toBe('password=[REDACTED]')
        ->and($out->context['token'])->toBe('[REDACTED]')
        ->and($out->context['note'])->toContain('[phone]')
        ->and($out->runtime['route'])->toBe('orders.show');
});
