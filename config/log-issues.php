<?php

declare(strict_types=1);
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$csv = static fn (?string $value, array $default): array => $value === null || trim($value) === ''
    ? $default
    : array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));

$queue = env('LOG_ISSUES_QUEUE', false);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | Nothing is ever sent unless this is true AND both `repo` and `token` are set.
    | Logging itself is switched on per app with LOG_STACK=single,github-issues.
    */
    'enabled' => (bool) env('LOG_ISSUES_ENABLED', true),

    // owner/name of the repository that receives the issues.
    'repo' => env('LOG_ISSUES_REPO'),

    // Fine-grained PAT: Issues read/write + Metadata read on that ONE repository.
    'token' => env('LOG_ISSUES_TOKEN'),

    // REST API base (GitHub Enterprise: https://ghe.example.com/api/v3) and web base for file links.
    'api_url' => env('LOG_ISSUES_API_URL', 'https://api.github.com'),
    'web_url' => env('LOG_ISSUES_WEB_URL', 'https://github.com'),

    // Minimum Monolog level that is reported (debug … emergency).
    'level' => env('LOG_ISSUES_LEVEL', 'error'),

    // Only report from these APP_ENV values. Use ['*'] for every environment.
    'environments' => $csv(env('LOG_ISSUES_ENVIRONMENTS'), ['production', 'staging']),

    // Labels added to every new issue, plus "env:<environment>" and (optionally) "severity:<level>".
    'labels' => $csv(env('LOG_ISSUES_LABELS'), ['bug', 'auto-reported']),
    'severity_label' => (bool) env('LOG_ISSUES_SEVERITY_LABEL', true),

    /*
    |--------------------------------------------------------------------------
    | AI agent pairing
    |--------------------------------------------------------------------------
    | mention:     "@claude" for the Claude Code GitHub Action, the agent's GitHub login, or "".
    | mention_on:  new (default) | milestone | never. Mentions are only ever written on issue
    |              creation (and milestone comments when "milestone"), never on every occurrence.
    | agent_brief: string template ({mention}, {repo}, {environment}, {fingerprint}, {title},
    |              {level}) or "view:<blade.view.name>" to render a Blade view with the same data.
    */
    'mention' => env('LOG_ISSUES_MENTION', ''),
    'mention_on' => env('LOG_ISSUES_MENTION_ON', 'new'),
    'agent_brief' => env('LOG_ISSUES_AGENT_BRIEF', '{mention} Please investigate this {environment} error. Find the root cause, add a failing test that reproduces it, fix it with the smallest safe change, and open a pull request that references this issue. Do not change `.env`, migrations that drop data, or payment code without asking in this issue first.'),

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    | queue: false → after the HTTP response (web) or synchronously with `timeout_seconds` (CLI).
    |        LOG_ISSUES_QUEUE=true uses the default connection; any other value is a connection name.
    */
    'queue' => $queue === false || $queue === null || $queue === '' ? false : [
        'connection' => $queue === true ? null : $queue,
        'name' => env('LOG_ISSUES_QUEUE_NAME'),
    ],
    'timeout_seconds' => (int) env('LOG_ISSUES_TIMEOUT', 5),
    'retries' => (int) env('LOG_ISSUES_RETRIES', 2),

    /*
    |--------------------------------------------------------------------------
    | Noise control
    |--------------------------------------------------------------------------
    */
    'max_new_issues_per_hour' => (int) env('LOG_ISSUES_MAX_NEW_PER_HOUR', 10),
    'comment_cooldown_minutes' => (int) env('LOG_ISSUES_COMMENT_COOLDOWN', 60),
    'milestones' => [10, 100, 1000, 10000, 100000],
    'on_closed' => env('LOG_ISSUES_ON_CLOSED', 'reopen'), // reopen | new | ignore
    'sample_rate' => (float) env('LOG_ISSUES_SAMPLE_RATE', 1.0),

    'ignore_exceptions' => [
        NotFoundHttpException::class,
        MethodNotAllowedHttpException::class,
        ModelNotFoundException::class,
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        TokenMismatchException::class,
        ThrottleRequestsException::class,
    ],

    // Regular expressions matched against the log message / exception message.
    'ignore_messages' => [],

    /*
    |--------------------------------------------------------------------------
    | Redaction (always on)
    |--------------------------------------------------------------------------
    | Built-in rules always apply; these lists ADD to them.
    | keys:     context keys (any depth, case-insensitive substring) whose values are masked.
    | patterns: extra regular expressions whose matches are masked.
    */
    'redact' => [
        'keys' => [],
        'patterns' => [],
        'max_string_length' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Source links
    |--------------------------------------------------------------------------
    | release: git SHA or version shown in the issue and used for file links. Falls back to
    |          APP_RELEASE, then to .git/HEAD when readable, then to `branch`.
    */
    'release' => env('LOG_ISSUES_RELEASE', env('APP_RELEASE')),
    'branch' => env('LOG_ISSUES_BRANCH', 'main'),

    // Directory that file paths are made relative to. null = base_path().
    'base_path' => null,

    // Cache store for counters, issue numbers and the circuit breaker. null = default store.
    'cache_store' => env('LOG_ISSUES_CACHE_STORE'),
];
