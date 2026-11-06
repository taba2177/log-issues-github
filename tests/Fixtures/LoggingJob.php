<?php

declare(strict_types=1);

namespace Taba\LogIssues\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class LoggingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::error('job failed', ['exception' => Thrower::make('Sync of order 15 failed')]);
    }
}
