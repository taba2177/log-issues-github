<?php

declare(strict_types=1);

namespace Taba\LogIssues\GitHub;

use RuntimeException;

final class GitHubException extends RuntimeException
{
    public static function fromStatus(string $operation, int $status, string $detail = ''): self
    {
        $hint = match ($status) {
            401 => 'token is invalid or expired',
            403 => 'token lacks permission or a rate limit was hit',
            404 => 'repository not found or not visible to the token',
            422 => 'GitHub rejected the payload',
            default => 'unexpected response',
        };

        return new self(trim("GitHub {$operation} failed: HTTP {$status} ({$hint}) {$detail}"), $status);
    }
}
