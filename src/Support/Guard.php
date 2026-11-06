<?php

declare(strict_types=1);

namespace Taba\LogIssues\Support;

/**
 * Process-wide re-entrancy flag. While it is active, the log handler ignores every record, so
 * nothing the package does (HTTP calls, cache writes, a failing job) can ever log back into it.
 */
final class Guard
{
    private static bool $active = false;

    public static function active(): bool
    {
        return self::$active;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        $previous = self::$active;
        self::$active = true;

        try {
            return $callback();
        } finally {
            self::$active = $previous;
        }
    }
}
