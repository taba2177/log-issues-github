<?php

declare(strict_types=1);

namespace Taba\LogIssues\Report;

/**
 * Everything the package knows about one error occurrence. Immutable; serializable for queues.
 */
final class Report
{
    /**
     * @param  list<Frame>  $frames  Application and vendor frames, innermost first.
     * @param  list<array{class: string, message: string}>  $previous  Chain of previous exceptions.
     * @param  array<string, mixed>  $context  Log context without the exception.
     * @param  array<string, string|int|bool|null>  $runtime  Route, method, command, job, versions…
     */
    public function __construct(
        public readonly string $fingerprint,
        public readonly string $environment,
        public readonly string $level,
        public readonly ?string $exceptionClass,
        public readonly string $message,
        public readonly array $frames,
        public readonly array $previous,
        public readonly array $context,
        public readonly array $runtime,
        public readonly string $occurredAt,
    ) {}

    /** First application frame, else the first frame at all. */
    public function location(): ?Frame
    {
        foreach ($this->frames as $frame) {
            if ($frame->application) {
                return $frame;
            }
        }

        return $this->frames[0] ?? null;
    }

    public function shortClass(): ?string
    {
        if ($this->exceptionClass === null) {
            return null;
        }
        $pos = strrpos($this->exceptionClass, '\\');

        return $pos === false ? $this->exceptionClass : substr($this->exceptionClass, $pos + 1);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return self::fromArray(array_merge($this->toArray(), $changes));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'environment' => $this->environment,
            'level' => $this->level,
            'exceptionClass' => $this->exceptionClass,
            'message' => $this->message,
            'frames' => array_map(static fn (Frame $f): array => $f->toArray(), $this->frames),
            'previous' => $this->previous,
            'context' => $this->context,
            'runtime' => $this->runtime,
            'occurredAt' => $this->occurredAt,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $frames = [];
        foreach ((array) ($data['frames'] ?? []) as $frame) {
            $frames[] = $frame instanceof Frame ? $frame : Frame::fromArray((array) $frame);
        }

        $previous = [];
        foreach ((array) ($data['previous'] ?? []) as $item) {
            $item = (array) $item;
            $previous[] = ['class' => (string) ($item['class'] ?? ''), 'message' => (string) ($item['message'] ?? '')];
        }

        /** @var array<string, mixed> $context */
        $context = (array) ($data['context'] ?? []);
        /** @var array<string, string|int|bool|null> $runtime */
        $runtime = (array) ($data['runtime'] ?? []);
        $class = $data['exceptionClass'] ?? null;

        return new self(
            (string) ($data['fingerprint'] ?? ''),
            (string) ($data['environment'] ?? ''),
            (string) ($data['level'] ?? 'error'),
            is_string($class) && $class !== '' ? $class : null,
            (string) ($data['message'] ?? ''),
            $frames,
            $previous,
            $context,
            $runtime,
            (string) ($data['occurredAt'] ?? ''),
        );
    }
}
