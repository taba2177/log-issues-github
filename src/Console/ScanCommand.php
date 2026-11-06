<?php

declare(strict_types=1);

namespace Taba\LogIssues\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Taba\LogIssues\GitHub\IssueFormatter;
use Taba\LogIssues\Pipeline\OccurrenceStore;
use Taba\LogIssues\Pipeline\Reporter;
use Taba\LogIssues\Report\Redactor;
use Taba\LogIssues\Report\Report;
use Taba\LogIssues\Report\ReportBuilder;
use Taba\LogIssues\Scan\LogFileParser;
use Taba\LogIssues\Support\Guard;
use Throwable;

final class ScanCommand extends Command
{
    protected $signature = 'log-issues:scan
        {path=storage/logs/laravel.log : Log file, directory or glob (relative to the app base path)}
        {--since=24h : Ignore entries older than this (30m, 24h, 7d or any date)}
        {--dry-run : Show what would be reported; contact nothing and keep the saved offset}';

    protected $description = 'Report errors from Laravel log files through the log-issues pipeline';

    public function handle(
        LogFileParser $parser,
        ReportBuilder $builder,
        Reporter $reporter,
        Redactor $redactor,
        IssueFormatter $formatter,
        OccurrenceStore $store,
    ): int {
        return Guard::run(function () use ($parser, $builder, $reporter, $redactor, $formatter, $store): int {
            $dryRun = (bool) $this->option('dry-run');

            $sinceOption = $this->option('since');
            $pathArgument = $this->argument('path');
            $path = is_string($pathArgument) ? $pathArgument : 'storage/logs/laravel.log';

            try {
                $since = $this->since(is_string($sinceOption) ? $sinceOption : '24h');
            } catch (Throwable) {
                $this->components->error('Invalid --since value. Use 30m, 24h, 7d or a date.');

                return self::FAILURE;
            }

            $files = $this->files($path);
            if ($files === []) {
                $this->components->warn('No log files found for '.$path);

                return self::SUCCESS;
            }

            /** @var array<string, array{report: Report, count: int, last: Carbon}> $groups */
            $groups = [];
            $entries = 0;

            foreach ($files as $file) {
                $size = (int) @filesize($file);
                $offset = $store->scanOffset($file);
                if ($offset > $size) {
                    $offset = 0; // rotated or truncated
                }

                $end = $offset;
                foreach ($parser->parse($file, $offset, $end) as $entry) {
                    try {
                        $at = Carbon::parse($entry->datetime);
                    } catch (Throwable) {
                        continue;
                    }
                    if ($since !== null && $at->lessThan($since)) {
                        continue;
                    }
                    $entries++;

                    $report = $builder->fromParsed(
                        $entry->environment,
                        $entry->level,
                        $entry->exceptionClass,
                        $entry->message,
                        $entry->file,
                        $entry->line,
                        $entry->trace,
                        $entry->previous,
                        $at->toAtomString(),
                        ['source' => 'log scan ('.basename($file).')'],
                    );

                    if (! $reporter->shouldReport($report)) {
                        continue;
                    }

                    $key = $report->fingerprint;
                    if (! isset($groups[$key])) {
                        $groups[$key] = ['report' => $report, 'count' => 0, 'last' => $at];
                    }
                    $groups[$key]['count']++;
                    if ($at->greaterThanOrEqualTo($groups[$key]['last'])) {
                        $groups[$key]['report'] = $report;
                        $groups[$key]['last'] = $at;
                    }
                }

                if (! $dryRun) {
                    $store->setScanOffset($file, $end);
                }
            }

            $this->components->info(sprintf('%d entr%s scanned in %d file(s), %d distinct error(s) to report.', $entries, $entries === 1 ? 'y' : 'ies', count($files), count($groups)));

            if ($groups === []) {
                return self::SUCCESS;
            }

            $rows = [];
            foreach ($groups as $fingerprint => $group) {
                $title = $formatter->title($redactor->report($group['report']));
                $result = $dryRun ? 'dry run' : $reporter->process($group['report'], $group['count'], $group['last']);
                $rows[] = [substr($fingerprint, 0, 12), $group['count'], $group['report']->level, $group['report']->environment, mb_strimwidth($title, 0, 80, '…'), $result];
            }

            $this->table(['Fingerprint', 'Count', 'Level', 'Env', 'Title', 'Result'], $rows);

            if ($dryRun) {
                $this->components->info('Dry run: nothing was sent and the scan offset was not saved.');
            }

            return self::SUCCESS;
        });
    }

    private function since(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '' || $value === '0' || strtolower($value) === 'all') {
            return null;
        }

        if (preg_match('/^(\d+)\s*([mhdw])$/i', $value, $m) === 1) {
            $amount = (int) $m[1];

            return match (strtolower($m[2])) {
                'm' => Carbon::now()->subMinutes($amount),
                'h' => Carbon::now()->subHours($amount),
                'd' => Carbon::now()->subDays($amount),
                default => Carbon::now()->subWeeks($amount),
            };
        }

        return Carbon::parse($value);
    }

    /** @return list<string> */
    private function files(string $path): array
    {
        $absolute = preg_match('#^(?:[A-Za-z]:)?[\\\\/]#', $path) === 1 ? $path : $this->laravel->basePath($path);

        if (is_dir($absolute)) {
            $files = glob(rtrim($absolute, '/\\').'/*.log') ?: [];
        } elseif (str_contains($absolute, '*')) {
            $files = glob($absolute) ?: [];
        } elseif (is_file($absolute)) {
            $files = [$absolute];
        } elseif (basename($absolute) === 'laravel.log') {
            // LOG_CHANNEL=daily writes laravel-YYYY-MM-DD.log instead
            $files = glob(dirname($absolute).'/laravel-*.log') ?: [];
        } else {
            $files = [];
        }

        $files = array_values(array_filter($files, 'is_file'));
        sort($files);

        return $files;
    }
}
