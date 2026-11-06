<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Taba\LogIssues\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Fakes a GitHub API where no issue exists yet and creation succeeds.
 *
 * @param  array<string, mixed>  $overrides  url pattern => response
 */
function fakeGitHub(array $overrides = []): void
{
    Http::fake($overrides + [
        'api.github.com/search/issues*' => Http::response(['total_count' => 0, 'items' => []]),
        'api.github.com/repos/acme/shop/issues' => Http::response(['number' => 7, 'state' => 'open', 'html_url' => 'https://github.com/acme/shop/issues/7'], 201),
        'api.github.com/repos/acme/shop/issues/*/comments' => Http::response(['id' => 1], 201),
        'api.github.com/repos/acme/shop/issues/*' => Http::response(['number' => 7, 'state' => 'open', 'html_url' => 'https://github.com/acme/shop/issues/7']),
        'api.github.com/repos/acme/shop' => Http::response(['full_name' => 'acme/shop', 'private' => true, 'has_issues' => true]),
    ]);
}

/** @return list<Request> */
function githubRequests(?string $method = null, ?string $pathContains = null): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $r) => ($method === null || $r->method() === $method)
            && ($pathContains === null || str_contains($r->url(), $pathContains)))
        ->values()
        ->all();
}

function createdIssues(): array
{
    return array_values(array_filter(
        githubRequests('POST'),
        fn (Request $r) => preg_match('#/repos/acme/shop/issues$#', $r->url()) === 1,
    ));
}

function comments(): array
{
    return githubRequests('POST', '/comments');
}
