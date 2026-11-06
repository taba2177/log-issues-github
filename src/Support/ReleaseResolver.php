<?php

declare(strict_types=1);

namespace Taba\LogIssues\Support;

use Taba\LogIssues\Settings;

/**
 * Resolves the deployed version: config `release` / APP_RELEASE, else .git HEAD when readable.
 */
final class ReleaseResolver
{
    private bool $resolved = false;

    private ?string $gitSha = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly Paths $paths,
    ) {}

    public function release(): ?string
    {
        return $this->settings->release() ?? $this->gitSha();
    }

    /** Git ref used in source links: a commit SHA when known, otherwise the configured branch. */
    public function ref(): string
    {
        $release = $this->release();

        if ($release !== null && preg_match('/^[0-9a-f]{7,40}$/i', $release) === 1) {
            return $release;
        }

        return $this->settings->branch();
    }

    private function gitSha(): ?string
    {
        if ($this->resolved) {
            return $this->gitSha;
        }
        $this->resolved = true;

        try {
            $git = $this->paths->base().'/.git';
            $head = @file_get_contents($git.'/HEAD');
            if (! is_string($head)) {
                return null;
            }
            $head = trim($head);

            if (preg_match('/^[0-9a-f]{40}$/', $head) === 1) {
                return $this->gitSha = $head;
            }

            if (! str_starts_with($head, 'ref: ')) {
                return null;
            }
            $ref = substr($head, 5);

            $sha = @file_get_contents($git.'/'.$ref);
            if (is_string($sha) && preg_match('/^[0-9a-f]{40}$/', trim($sha)) === 1) {
                return $this->gitSha = trim($sha);
            }

            $packed = @file_get_contents($git.'/packed-refs');
            if (is_string($packed) && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', $packed, $m) === 1) {
                return $this->gitSha = $m[1];
            }
        } catch (\Throwable) {
            // unreadable .git — fall through
        }

        return null;
    }
}
