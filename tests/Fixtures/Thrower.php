<?php

declare(strict_types=1);

namespace Taba\LogIssues\Tests\Fixtures;

use RuntimeException;
use Throwable;

final class Thrower
{
    public static function make(string $message = 'Order 15 failed', ?Throwable $previous = null): RuntimeException
    {
        return new RuntimeException($message, 0, $previous);
    }

    public static function other(string $message = 'Order 15 failed'): RuntimeException
    {
        return new RuntimeException($message);
    }
}
