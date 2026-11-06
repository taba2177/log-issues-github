<?php

declare(strict_types=1);

namespace Taba\LogIssues\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Taba\LogIssues\GitHub\GitHubClient;
use Taba\LogIssues\GitHub\IssueFormatter;
use Taba\LogIssues\LogIssuesServiceProvider;
use Taba\LogIssues\Pipeline\OccurrenceStore;
use Taba\LogIssues\Report\Redactor;
use Taba\LogIssues\Report\ReportBuilder;
use Taba\LogIssues\Settings;
use Taba\LogIssues\Support\Guard;
use Throwable;

final class TestCommand extends Command
{
    protected $signature = 'log-issues:test
        {--dry-run : Print the sample issue instead of creating it. Never writes to GitHub (still checks read access when a token is set).}';

    protected $description = 'Validate the log-issues configuration, check GitHub access and create (or print) a sample issue';

    public function handle(
        Settings $settings,
        Repository $config,
        GitHubClient $client,
        IssueFormatter $formatter,
        ReportBuilder $builder,
        Redactor $redactor,
        OccurrenceStore $store,
    ): int {
        return Guard::run(function () use ($settings, $config, $client, $formatter, $builder, $redactor, $store): int {
            $dryRun = (bool) $this->option('dry-run');
            $errors = 0;

            $this->components->info('log-issues configuration'.($dryRun ? ' (dry run)' : ''));
            $errors += $this->validate($settings, $config, $dryRun);

            if ($store->circuitOpen()) {
                $this->components->warn('The circuit breaker is open until '.$store->circuitOpenUntil().'. Run `php artisan log-issues:status --reset` to clear it.');
            }

            $errors += $this->checkRepository($settings, $client, $store);

            $report = $redactor->report($builder->fromRecord(new LogRecord(
                Carbon::now()->toDateTimeImmutable(),
                'log-issues',
                Level::Error,
                'log-issues test',
                [
                    'exception' => new RuntimeException('log-issues test: sample error raised by `php artisan log-issues:test` (order 1234 for test@example.com)'),
                    'fingerprint' => 'log-issues:test',
                ],
            )));
            $entry = ['count' => 1, 'first_seen' => $report->occurredAt, 'last_seen' => $report->occurredAt, 'count_at_last_comment' => 0];
            $title = $formatter->title($report);
            $labels = $formatter->labels($report);
            $body = $formatter->body($report, $entry);

            if ($dryRun) {
                $this->newLine();
                $this->components->info('Sample issue (not sent)');
                $this->line('<options=bold>Repository:</> '.($settings->repo() !== '' ? $settings->repo() : '(not set)'));
                $this->line('<options=bold>Title:</> '.$title);
                $this->line('<options=bold>Labels:</> '.implode(', ', $labels));
                $this->newLine();
                $this->line($body);

                return $errors > 0 ? self::FAILURE : self::SUCCESS;
            }

            if ($errors > 0) {
                $this->components->error("{$errors} problem(s) found — fix them, or use --dry-run to preview without a token.");

                return self::FAILURE;
            }

            try {
                $issue = $client->createIssue($title, $body, $labels);
                $this->components->info('Created sample issue #'.$issue['number'].': '.$issue['html_url']);
            } catch (Throwable $e) {
                $this->components->error('Could not create the sample issue: '.$redactor->string($e->getMessage()));

                return self::FAILURE;
            }

            return self::SUCCESS;
        });
    }

    private function validate(Settings $settings, Repository $config, bool $dryRun): int
    {
        $errors = 0;
        $fail = function (string $message) use (&$errors, $dryRun): void {
            $this->components->twoColumnDetail($message, $dryRun ? '<fg=yellow>WARN</>' : '<fg=red>FAIL</>');
            if (! $dryRun) {
                $errors++;
            }
        };
        $ok = fn (string $message, string $detail = '') => $this->components->twoColumnDetail($message, $detail !== '' ? $detail : '<fg=green>OK</>');
        $warn = fn (string $message) => $this->components->twoColumnDetail($message, '<fg=yellow>WARN</>');
        $error = function (string $message) use (&$errors): void {
            $this->components->twoColumnDetail($message, '<fg=red>FAIL</>');
            $errors++;
        };

        $settings->enabled() ? $ok('enabled') : $warn('enabled is false — nothing will be reported');

        $repo = $settings->repo();
        if ($repo === '') {
            $fail('repo is not set (LOG_ISSUES_REPO=owner/name)');
        } elseif (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) !== 1) {
            $error("repo \"{$repo}\" is not in owner/name form");
        } else {
            $ok('repo', $repo);
        }

        $settings->token() === '' ? $fail('token is not set (LOG_ISSUES_TOKEN)') : $ok('token', 'set (hidden)');

        $level = $config->get('log-issues.level', 'error');
        Settings::parseLevel($level) !== null
            ? $ok('level', is_scalar($level) ? (string) $level : 'error')
            : $error('level "'.(is_scalar($level) ? (string) $level : '?').'" is not a valid log level');

        $mentionOn = strtolower((string) $config->get('log-issues.mention_on', 'new'));
        in_array($mentionOn, Settings::MENTION_ON, true)
            ? $ok('mention / mention_on', ($settings->mention() !== '' ? $settings->mention() : '(none)').' / '.$mentionOn)
            : $error("mention_on \"{$mentionOn}\" must be one of ".implode(', ', Settings::MENTION_ON));

        $onClosed = strtolower((string) $config->get('log-issues.on_closed', 'reopen'));
        in_array($onClosed, Settings::ON_CLOSED, true)
            ? $ok('on_closed', $onClosed)
            : $error("on_closed \"{$onClosed}\" must be one of ".implode(', ', Settings::ON_CLOSED));

        $rate = $config->get('log-issues.sample_rate', 1.0);
        is_numeric($rate) && (float) $rate >= 0 && (float) $rate <= 1
            ? $ok('sample_rate', (string) $rate)
            : $error('sample_rate must be between 0 and 1');

        $environment = (string) $this->laravel->environment();
        $settings->environmentAllowed($environment)
            ? $ok('environment', $environment)
            : $warn("environment \"{$environment}\" is not in environments [".implode(', ', $settings->environments()).'] — errors here are not reported');

        $channel = LogIssuesServiceProvider::CHANNEL;
        if (! $config->has("logging.channels.{$channel}")) {
            $error("log channel \"{$channel}\" is not registered");
        } else {
            $default = (string) $config->get('logging.default');
            $defaultChannel = $config->get("logging.channels.{$default}");
            $stack = is_array($defaultChannel) && ($defaultChannel['driver'] ?? null) === 'stack'
                ? (array) ($defaultChannel['channels'] ?? [])
                : [$default];
            in_array($channel, $stack, true)
                ? $ok('log channel', "{$channel} (in \"{$default}\")")
                : $warn("log channel \"{$channel}\" is not in the default channel \"{$default}\" — set LOG_STACK=single,{$channel}");
        }

        $queue = $settings->queue();
        $ok('delivery', $queue === false ? 'after response (web) / sync (CLI)' : 'queue '.($queue['connection'] ?? 'default').($queue['name'] !== null ? ':'.$queue['name'] : ''));

        return $errors;
    }

    private function checkRepository(Settings $settings, GitHubClient $client, OccurrenceStore $store): int
    {
        if ($settings->repo() === '' || $settings->token() === '') {
            $this->components->twoColumnDetail('GitHub access', '<fg=yellow>SKIPPED</> (repo or token missing)');

            return 0;
        }

        try {
            $repository = $client->repository();
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('GitHub access', '<fg=red>FAIL</>');
            $this->components->error('The token cannot read '.$settings->repo().': '.$e->getMessage());

            return 1;
        }

        $this->components->twoColumnDetail('GitHub access', '<fg=green>OK</> (token can read '.$settings->repo().')');

        if (($repository['private'] ?? true) === false) {
            $this->newLine();
            $this->error(str_repeat('!', 78));
            $this->error('!!  WARNING: '.$settings->repo().' is a PUBLIC repository.');
            $this->error('!!  Error messages, file paths and stack traces will be visible to everyone.');
            $this->error('!!  Point LOG_ISSUES_REPO at a private repository.');
            $this->error(str_repeat('!', 78));
            $this->newLine();
        } else {
            $this->components->twoColumnDetail('Repository visibility', 'private');
        }

        if (($repository['has_issues'] ?? true) === false) {
            $this->components->error('Issues are disabled on '.$settings->repo().' — enable them in the repository settings.');

            return 1;
        }

        $store->recordSuccess();

        return 0;
    }
}
