<?php

declare(strict_types=1);

namespace Taba\LogIssues\Support;

/**
 * Remembers the queue job currently being processed by this worker (set from queue events).
 */
final class RuntimeContext
{
    private ?string $job = null;

    public function setJob(?string $job): void
    {
        $this->job = $job;
    }

    public function job(): ?string
    {
        return $this->job;
    }
}
