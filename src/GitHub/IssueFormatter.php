<?php

declare(strict_types=1);

namespace Taba\LogIssues\GitHub;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\View\Compilers\BladeCompiler;
use Taba\LogIssues\Report\Frame;
use Taba\LogIssues\Report\Report;
use Taba\LogIssues\Settings;
use Taba\LogIssues\Support\ReleaseResolver;
use Throwable;

/**
 * Renders issue titles, bodies, labels and comments (Markdown).
 */
final class IssueFormatter
{
    public const TITLE_LIMIT = 120;

    public const BODY_LIMIT = 60000;

    private const MAX_APP_FRAMES = 30;

    private const MAX_VENDOR_FRAMES = 40;

    public function __construct(
        private readonly Settings $settings,
        private readonly ReleaseResolver $release,
        private readonly Container $container,
    ) {}

    public static function marker(string $fingerprint): string
    {
        return '<!-- log-issues:fp='.$fingerprint.' -->';
    }

    public function title(Report $report): string
    {
        $prefix = '['.$report->environment.'] '.($report->shortClass() ?? strtoupper($report->level)).': ';
        $location = $report->location();
        $suffix = $location !== null ? ' ('.$location->file.':'.$location->line.')' : '';

        $message = trim((string) preg_replace('/\s+/', ' ', $report->message));
        if ($message === '') {
            $message = $report->exceptionClass ?? 'Log entry';
        }

        // Keep at least 30 characters for the message itself.
        if (mb_strlen($prefix.$suffix) > self::TITLE_LIMIT - 30) {
            $suffix = '';
        }

        $room = self::TITLE_LIMIT - mb_strlen($prefix) - mb_strlen($suffix);
        if (mb_strlen($message) > $room) {
            $message = rtrim(mb_substr($message, 0, max(1, $room - 1))).'…';
        }

        return $prefix.$message.$suffix;
    }

    /** @return list<string> */
    public function labels(Report $report): array
    {
        $labels = $this->settings->labels();
        $labels[] = 'env:'.$report->environment;
        if ($this->settings->severityLabel()) {
            $labels[] = 'severity:'.$report->level;
        }

        return array_values(array_unique(array_map(static fn (string $l): string => mb_substr($l, 0, 50), $labels)));
    }

    /**
     * @param  array<string, mixed>  $entry  OccurrenceStore entry (counts, first/last seen).
     */
    public function body(Report $report, array $entry, ?int $previousIssue = null): string
    {
        $sections = [];
        $sections[] = '## '.($report->shortClass() ?? ucfirst($report->level).' log entry').' in `'.$report->environment.'`';

        if ($previousIssue !== null) {
            $sections[] = "> [!NOTE]\n> This error came back after #{$previousIssue} was closed.";
        }

        $sections[] = $this->summary($report, $entry);
        $sections[] = $this->exception($report);
        $sections[] = $this->trace($report);

        if ($report->context !== []) {
            $json = (string) json_encode($report->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $sections[] = "<details><summary>Log context (redacted)</summary>\n\n".self::fence(mb_substr($json, 0, 4000), 'json')."\n</details>";
        }

        $brief = $this->brief($report);
        if ($brief !== '') {
            $sections[] = "### Agent brief\n\n".$brief;
        }

        $footer = '<sub>Reported by taba2177/log-issues-github · fingerprint <code>log-issues:fp='.$report->fingerprint.'</code></sub>';

        return $this->limit(implode("\n\n", $sections))."\n\n".$footer."\n\n".self::marker($report->fingerprint)."\n";
    }

    /** @param  array<string, mixed>  $entry */
    public function comment(Report $report, array $entry, bool $milestone): string
    {
        $count = (int) ($entry['count'] ?? 1);
        $since = $count - (int) ($entry['count_at_last_comment'] ?? 0);

        $lines = [];
        $lines[] = $milestone
            ? "**Milestone:** this error has now occurred **{$count}** times."
            : "Seen **{$since}** more time(s) since the last update (**{$count}** total).";
        $lines[] = '';
        $lines[] = '| Last seen | Release | Environment |';
        $lines[] = '|---|---|---|';
        $lines[] = '| '.self::date((string) ($entry['last_seen'] ?? $report->occurredAt)).' | '
            .self::code((string) ($report->runtime['release'] ?? 'unknown')).' | '.$report->environment.' |';

        $message = trim($report->message);
        if ($message !== '') {
            $lines[] = '';
            $lines[] = 'Latest message:';
            $lines[] = self::fence(mb_substr($message, 0, 1000));
        }

        if ($milestone && $this->settings->mentionOn() === 'milestone' && $this->settings->mention() !== '') {
            $lines[] = '';
            $lines[] = $this->settings->mention().' this error keeps happening; please take a look.';
        }

        $lines[] = '';
        $lines[] = self::marker($report->fingerprint);

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $entry */
    public function reopenComment(Report $report, array $entry): string
    {
        $count = (int) ($entry['count'] ?? 1);

        return "Reopened automatically: the error happened again after this issue was closed (**{$count}** occurrences in total, last seen "
            .self::date((string) ($entry['last_seen'] ?? $report->occurredAt)).', release '
            .self::code((string) ($report->runtime['release'] ?? 'unknown')).").\n\n".self::marker($report->fingerprint);
    }

    public function brief(Report $report): string
    {
        $template = $this->settings->agentBrief();
        // Mentions trigger (and bill) agents: only ever on issue creation, never when disabled.
        $mention = $this->settings->mentionOn() === 'never' ? '' : $this->settings->mention();
        $data = [
            'mention' => $mention,
            'repo' => $this->settings->repo(),
            'environment' => $report->environment,
            'fingerprint' => $report->fingerprint,
            'title' => $this->title($report),
            'level' => $report->level,
        ];

        // A Blade string ("{{ $mention }} …") is rendered with the same variables.
        if (str_contains($template, '{{') || str_contains($template, '{!!')) {
            try {
                if ($this->container->bound('view')) {
                    return trim(BladeCompiler::render($template, $data, true));
                }
            } catch (Throwable) {
                // fall back to the default template below
            }
            $template = '{mention} Please investigate this {environment} error and open a pull request that fixes it.';
        }

        $replace = [];
        foreach ($data as $key => $value) {
            $replace['{'.$key.'}'] = $value;
        }

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', strtr($template, $replace)));
    }

    /** @param  array<string, mixed>  $entry */
    private function summary(Report $report, array $entry): string
    {
        $runtime = $report->runtime;
        $rows = [
            'Environment' => $report->environment,
            'Level' => $report->level,
            'First seen' => self::date((string) ($entry['first_seen'] ?? $report->occurredAt)),
            'Last seen' => self::date((string) ($entry['last_seen'] ?? $report->occurredAt)),
            'Occurrences' => (string) ($entry['count'] ?? 1),
            'Release' => self::code((string) ($runtime['release'] ?? 'unknown')),
            'PHP / Laravel' => ($runtime['php'] ?? '?').' / '.($runtime['laravel'] ?? '?'),
        ];

        if (isset($runtime['route']) || isset($runtime['method'])) {
            $rows['Route'] = self::code(trim(($runtime['method'] ?? '').' '.($runtime['route'] ?? '')));
        }
        if (isset($runtime['command'])) {
            $rows['Command'] = self::code((string) $runtime['command']);
        }
        if (isset($runtime['job'])) {
            $rows['Queue job'] = self::code((string) $runtime['job']);
        }
        if (isset($runtime['source'])) {
            $rows['Source'] = (string) $runtime['source'];
        }
        if (array_key_exists('authenticated', $runtime)) {
            $rows['Authenticated'] = $runtime['authenticated'] === true ? 'yes' : 'no';
        }

        $out = "| | |\n|---|---|";
        foreach ($rows as $label => $value) {
            $out .= "\n| **{$label}** | ".str_replace('|', '\|', $value).' |';
        }

        return $out;
    }

    private function exception(Report $report): string
    {
        $location = $report->location();
        $where = $location !== null ? ' at '.$this->link($location) : '';
        $heading = $report->exceptionClass !== null
            ? '### Exception'."\n\n".self::code($report->exceptionClass).$where
            : '### Message'."\n\n".ucfirst($report->level).' logged'.$where;

        $out = $heading."\n\n".self::fence($report->message === '' ? '(empty message)' : $report->message);

        if ($report->previous !== []) {
            $out .= "\n\n**Caused by**\n";
            foreach ($report->previous as $previous) {
                $out .= "\n- ".self::code($previous['class']).': '.self::inline($previous['message']);
            }
        }

        return $out;
    }

    private function trace(Report $report): string
    {
        $app = array_values(array_filter($report->frames, static fn (Frame $f): bool => $f->application));
        $vendor = array_values(array_filter($report->frames, static fn (Frame $f): bool => ! $f->application));

        if ($app === [] && $vendor === []) {
            return '### Stack trace'."\n\n_No stack trace available._";
        }

        $out = '### Stack trace';

        if ($app !== []) {
            $out .= "\n\n<details open><summary>Application frames (".count($app).")</summary>\n";
            foreach (array_slice($app, 0, self::MAX_APP_FRAMES) as $i => $frame) {
                $out .= "\n".($i + 1).'. '.$this->link($frame).' — '.self::code($frame->function);
            }
            $out .= "\n\n</details>";
        }

        if ($vendor !== []) {
            $lines = [];
            foreach (array_slice($vendor, 0, self::MAX_VENDOR_FRAMES) as $frame) {
                $lines[] = $frame->file.'('.$frame->line.'): '.$frame->function;
            }
            if (count($vendor) > self::MAX_VENDOR_FRAMES) {
                $lines[] = '… '.(count($vendor) - self::MAX_VENDOR_FRAMES).' more';
            }
            $out .= "\n\n<details><summary>Vendor / framework frames (".count($vendor).")</summary>\n\n"
                .self::fence(implode("\n", $lines))."\n</details>";
        }

        return $out;
    }

    private function link(Frame $frame): string
    {
        $label = self::code($frame->file.':'.$frame->line);

        if (! $frame->application || $this->settings->repo() === '') {
            return $label;
        }

        $ref = $this->release->ref();
        $path = implode('/', array_map('rawurlencode', explode('/', $frame->file)));

        return '['.$label.']('.$this->settings->webUrl().'/'.$this->settings->repo().'/blob/'.rawurlencode($ref).'/'.$path.'#L'.$frame->line.')';
    }

    private function limit(string $body): string
    {
        if (mb_strlen($body) <= self::BODY_LIMIT) {
            return $body;
        }

        return mb_substr($body, 0, self::BODY_LIMIT)."\n\n… _(truncated)_";
    }

    private static function date(string $iso): string
    {
        try {
            return Carbon::parse($iso)->utc()->format('Y-m-d H:i:s').' UTC';
        } catch (Throwable) {
            return $iso;
        }
    }

    private static function code(string $text): string
    {
        $text = str_replace(["\r", "\n"], ' ', $text);

        return str_contains($text, '`') ? '`` '.$text.' ``' : '`'.$text.'`';
    }

    private static function inline(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $text)), 0, 300);
    }

    private static function fence(string $text, string $language = 'text'): string
    {
        $text = str_replace('```', "`\u{200B}``", $text);

        return '```'.$language."\n".$text."\n```";
    }
}
