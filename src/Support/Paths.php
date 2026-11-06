<?php

declare(strict_types=1);

namespace Taba\LogIssues\Support;

use Illuminate\Contracts\Foundation\Application;
use Taba\LogIssues\Settings;

/**
 * Turns absolute file paths into stable, repository-relative ones.
 */
final class Paths
{
    private readonly string $packageSrc;

    public function __construct(
        private readonly Application $app,
        private readonly Settings $settings,
    ) {
        $this->packageSrc = self::normalize(dirname(__DIR__)).'/';
    }

    public function base(): string
    {
        return rtrim(self::normalize($this->settings->basePath() ?? $this->app->basePath()), '/');
    }

    public function relative(string $file, ?string $base = null): string
    {
        $file = self::normalize($file);
        $base = rtrim(self::normalize($base ?? $this->base()), '/').'/';

        if ($base !== '/' && str_starts_with(strtolower($file), strtolower($base))) {
            return substr($file, strlen($base));
        }

        return $file;
    }

    /** An application frame lives under the base path and outside vendor/ and this package. */
    public function isApplicationFile(string $file, ?string $base = null): bool
    {
        $normalized = self::normalize($file);

        if ($this->isPackageFile($normalized)) {
            return false;
        }

        $relative = $this->relative($normalized, $base);

        if ($relative === $normalized) {
            return false; // outside the base path
        }

        return ! str_starts_with($relative, 'vendor/')
            && ! str_starts_with($relative, 'node_modules/')
            && ! str_starts_with($relative, 'storage/framework/')
            && ! str_starts_with($relative, 'bootstrap/cache/');
    }

    public function isPackageFile(string $file): bool
    {
        return str_starts_with(strtolower(self::normalize($file)), strtolower($this->packageSrc));
    }

    public static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
