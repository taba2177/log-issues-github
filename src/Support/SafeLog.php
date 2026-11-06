<?php

declare(strict_types=1);

namespace Taba\LogIssues\Support;

use Closure;

/**
 * The only place the package reports its own problems: PHP's error_log(), never Laravel's logger.
 */
final class SafeLog
{
    /** @var (Closure(string): void)|null */
    private static ?Closure $writer = null;

    /** @param  (Closure(string): void)|null  $writer  Override for tests; null restores error_log(). */
    public static function using(?Closure $writer): void
    {
        self::$writer = $writer;
    }

    public static function write(string|\Throwable $problem): void
    {
        try {
            $message = $problem instanceof \Throwable
                ? $problem::class.': '.$problem->getMessage()
                : $problem;
            $message = '[log-issues] '.mb_substr(str_replace(["\r", "\n"], ' ', $message), 0, 500);

            if (self::$writer !== null) {
                (self::$writer)($message);

                return;
            }

            @error_log($message);
        } catch (\Throwable) {
            // Nothing left to do: never let the package break the host app.
        }
    }
}
