<?php

declare(strict_types=1);

use Taba\LogIssues\Settings;
use Taba\LogIssues\Support\Paths;
use Taba\LogIssues\Support\ReleaseResolver;

function gitRepo(array $files): string
{
    $dir = sys_get_temp_dir().'/log-issues-git-'.uniqid();
    foreach ($files as $path => $content) {
        @mkdir(dirname($dir.'/.git/'.$path), 0777, true);
        file_put_contents($dir.'/.git/'.$path, $content);
    }
    config()->set('log-issues.release', null);
    config()->set('log-issues.base_path', $dir);

    return $dir;
}

$sha = str_repeat('a1b2c3d4e5', 4);

it('prefers the configured release', function () {
    config()->set('log-issues.release', 'v1.0.0');

    expect(app(ReleaseResolver::class)->release())->toBe('v1.0.0')
        ->and(app(ReleaseResolver::class)->ref())->toBe('main');
});

it('reads the commit from .git refs', function () use ($sha) {
    gitRepo(['HEAD' => "ref: refs/heads/main\n", 'refs/heads/main' => $sha."\n"]);

    expect(app(ReleaseResolver::class)->release())->toBe($sha)
        ->and(app(ReleaseResolver::class)->ref())->toBe($sha);
});

it('reads packed refs and detached heads', function () use ($sha) {
    gitRepo(['HEAD' => "ref: refs/heads/prod\n", 'packed-refs' => "# pack-refs\n{$sha} refs/heads/prod\n"]);
    expect((new ReleaseResolver(app(Settings::class), app(Paths::class)))->release())->toBe($sha);

    gitRepo(['HEAD' => $sha]);
    expect((new ReleaseResolver(app(Settings::class), app(Paths::class)))->release())->toBe($sha);
});

it('returns null without git metadata', function () {
    gitRepo(['HEAD' => 'garbage']);
    expect(app(ReleaseResolver::class)->release())->toBeNull();

    config()->set('log-issues.base_path', sys_get_temp_dir().'/nope-'.uniqid());
    expect((new ReleaseResolver(app(Settings::class), app(Paths::class)))->release())->toBeNull();
});
