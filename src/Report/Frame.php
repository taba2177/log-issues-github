<?php

declare(strict_types=1);

namespace Taba\LogIssues\Report;

final class Frame
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $function,
        public readonly bool $application,
    ) {}

    /** "relative/path.php:Class->method" — line-independent, used for fingerprints. */
    public function signature(): string
    {
        return $this->file.':'.$this->function;
    }

    /** @return array{file: string, line: int, function: string, application: bool} */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'function' => $this->function,
            'application' => $this->application,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['file'] ?? ''),
            (int) ($data['line'] ?? 0),
            (string) ($data['function'] ?? ''),
            (bool) ($data['application'] ?? false),
        );
    }
}
