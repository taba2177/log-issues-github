<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Taba\LogIssues\Support\RuntimeContext;
use Taba\LogIssues\Tests\Fixtures\LoggingJob;
use Taba\LogIssues\Tests\Fixtures\Thrower;

it('records the route name and method of a web request, without the URL query', function () {
    fakeGitHub();
    Route::post('/orders', function () {
        Log::error('checkout', ['exception' => Thrower::make()]);

        return response('ok');
    })->name('orders.store');

    $property = new ReflectionProperty(app(), 'isRunningInConsole');
    $property->setValue(app(), false);

    $this->post('/orders?coupon=SECRET10')->assertOk();

    expect(createdIssues())->toHaveCount(1)
        ->and(createdIssues()[0]['body'])
        ->toContain('| **Route** | `POST orders.store` |')
        ->not->toContain('SECRET10')
        ->not->toContain('| **Command** |');
});

it('records the queue job being processed', function () {
    fakeGitHub();

    dispatch(new LoggingJob);

    expect(createdIssues()[0]['body'])->toContain('| **Queue job** | `'.LoggingJob::class.'` |')
        ->and(app(RuntimeContext::class)->job())->toBeNull();
});
