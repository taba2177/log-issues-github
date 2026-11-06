# Build prompt — `laravel-log-issues` (Laravel errors → GitHub issues)

> Paste everything below the line into a Claude Code session started in an **empty folder**
> (the new package repo). It is written so the agent can build, test and publish v0.1.0 alone,
> asking you only for the things listed under "Ask me".

---

## Goal

Build a reusable, open-source Composer package **`taba2177/laravel-log-issues`** (namespace `Taba\LogIssues`)
that turns Laravel errors into **deduplicated GitHub issues**, so an AI coding agent (Claude Code GitHub
Action today, DeepSeek dsh-superagent or others later) can pick them up and open a pull request.

It must be safe to drop into **any** Laravel app. The first consumer is `taba2177/hostdev-store`
(Bagisto 2.4, Laravel 12.69, PHP 8.3, Monolog 3, Pest 3, deployed on Dokploy, logs in
`storage/logs/laravel.log`, `config/logging.php` uses `'stack'` with `LOG_STACK=single`).

**Golden rule: the package must never break, slow down or crash the host app.** Every failure inside
the package is swallowed and, at most, written to PHP's `error_log()`. Logging must never recurse.

## Compatibility

- PHP `^8.2`, Laravel `^11.0 || ^12.0` (add `^13.0` if it is released and Testbench supports it), Monolog `^3.0`.
- No dependencies beyond `illuminate/*`, `monolog/monolog` and Laravel's HTTP client (Guzzle).
- Tests: Pest 3 + Orchestra Testbench. CI: GitHub Actions matrix (PHP 8.2/8.3/8.4 × Laravel 11/12).

## How the host app enables it (target developer experience)

```bash
composer require taba2177/laravel-log-issues
php artisan vendor:publish --tag=log-issues-config   # optional
```

```dotenv
LOG_STACK=single,github-issues          # only this line changes logging; no edits to config/logging.php
LOG_ISSUES_REPO=taba2177/hostdev-store
LOG_ISSUES_TOKEN=github_pat_xxx         # fine-grained PAT: Issues read/write + Metadata read on ONE repo
LOG_ISSUES_MENTION=@claude              # or the agent's GitHub login, or empty
```

The service provider must **register the `github-issues` log channel itself** (merge into
`logging.channels` at boot, without overwriting an existing channel of the same name).

## Architecture

```
Log::error() / unhandled exception
  └─ GitHubIssueHandler (Monolog AbstractProcessingHandler, level >= config 'level', default error)
       ├─ guards: enabled? environment allowed? ignored class/message? recursion flag set? sampled?
       ├─ ReportBuilder → Report DTO (exception class, message, file:line, app frames, context, request info)
       ├─ Fingerprinter → stable sha1 (see below)
       ├─ Redactor → removes secrets / personal data from everything that leaves the app
       ├─ OccurrenceStore (Laravel cache) → counts, first/last seen, known issue number, cooldowns
       └─ dispatch ReportToGitHub job (queue if configured, else afterResponse in HTTP, else sync w/ 5s timeout)
            └─ GitHubClient (Laravel Http, retries w/ backoff, honours 403/429 rate-limit headers)
                 ├─ known issue?   → comment (throttled) / reopen if closed (configurable)
                 ├─ search by marker → found → remember number → comment
                 └─ not found      → create issue (respect max_new_issues_per_hour)
```

### Fingerprint (stable across deploys and line shifts)
`sha1(exception class + normalized message + first *application* frame "relative/path.php:function")`.
Normalize the message: replace numbers, UUIDs, hex ids, quoted strings, emails and URLs with placeholders,
so `User 123 not found` and `User 456 not found` are the same issue. For plain `Log::error('...')`
without an exception, use the normalized message + the caller frame. Allow a context key
`fingerprint` to override.

Each issue body contains a hidden marker: `<!-- log-issues:fp=<sha1> -->`. Search with
`GET /search/issues?q=repo:{repo}+is:issue+in:body+"log-issues:fp=<sha1>"` only when the cache does
not know the issue number (the search API is limited to 30 req/min — cache the result).

### Throttling and noise control
- Comment on an existing issue only at occurrence milestones (1, 10, 100, 1000, …) **or** at most once
  per `comment_cooldown_minutes` (default 60), aggregating "seen N times since last update".
- `max_new_issues_per_hour` (default 10): over the limit, keep counting locally, do not create.
- Circuit breaker: after 5 consecutive GitHub failures, pause all API calls for 15 minutes.
- Closed issue + same error again: `on_closed` = `reopen` (default) | `new` (new issue linking the old one) | `ignore`.
- Default ignored exceptions: `NotFoundHttpException`, `ModelNotFoundException`, `ValidationException`,
  `AuthenticationException`, `AuthorizationException`, `TokenMismatchException`,
  `ThrottleRequestsException`, `MethodNotAllowedHttpException`. Plus `ignore_messages` regex list,
  `environments` (default `['production', 'staging']`) and `sample_rate` (0–1).

### Redaction (mandatory, on by default, unit-tested)
Before anything is sent: mask emails, phone numbers, card-like numbers (Luhn), IBANs, JWTs,
`Bearer …`, `password|passwd|secret|token|api_key|authorization|cookie` keys at any depth, query
strings of URLs, and every value of `config('app.key')`, DB passwords and the package's own token.
Never include request bodies, headers, cookies, session data or the authenticated user's identity —
only `authenticated: yes/no`. Allow extra regexes and keys via config. Truncate long strings.

### Issue format
- **Title** (≤ 120 chars): `[production] QueryException: SQLSTATE[23000] Integrity constraint … (app/Services/OrderService.php:88)`
- **Labels**: config (`['bug', 'auto-reported']`) + `env:<environment>` + optional `severity:<level>`.
- **Body** (Markdown):
  1. Summary table: environment, level, first seen, last seen, occurrences, app version / git SHA
     (from config `release`, env `APP_RELEASE`, or `.git` HEAD if readable), PHP and Laravel versions,
     route name + HTTP method (no URL query), console command name if CLI, queue job class if in a job.
  2. Exception message (redacted) and the chain of previous exceptions.
  3. Stack trace in a `<details>` block: application frames first with repo-relative paths and GitHub
     links (`https://github.com/{repo}/blob/{sha|branch}/{path}#L{line}`), vendor frames collapsed.
  4. **Agent brief** (configurable Blade/string template), default:
     "{mention} Please investigate this production error. Find the root cause, add a failing test
     that reproduces it, fix it with the smallest safe change, and open a pull request that
     references this issue. Do not change `.env`, migrations that drop data, or payment code without
     asking in this issue first."
  5. The hidden fingerprint marker.
- `mention_on`: `new` (default) | `milestone` | `never`. Mentions must only ever appear on issue creation
  or milestone comments, never on every occurrence (agents bill per trigger).

## Configuration file `config/log-issues.php`
Every key overridable from env where sensible: `enabled`, `repo`, `token`, `api_url` (GitHub Enterprise
/ testing), `level`, `environments`, `labels`, `mention`, `mention_on`, `agent_brief`, `queue`
(`connection`, `name`, or `false`), `max_new_issues_per_hour`, `comment_cooldown_minutes`,
`milestones`, `on_closed`, `sample_rate`, `ignore_exceptions`, `ignore_messages`, `redact.keys`,
`redact.patterns`, `release`, `branch` (default `main`), `cache_store`, `timeout_seconds` (5).

## Artisan commands
- `log-issues:test {--dry-run}` — validates config, checks the token can read the repo, **warns loudly
  if the repo is public** (stack traces would be public), and creates (or prints, with `--dry-run`)
  a sample issue.
- `log-issues:scan {path=storage/logs/laravel.log} {--since=24h} {--dry-run}` — parses Laravel's log
  format (single and daily files, multi-line stack traces), groups entries by fingerprint and reports
  them through the same pipeline. Useful for the first rollout and for apps that cannot change
  `LOG_STACK`. Remember the last scanned byte offset in cache so it can run from the scheduler.
- `log-issues:status` — shows counts per fingerprint from the cache, circuit-breaker state, last error.

## Tests (Pest + Testbench, `Http::fake()`, no real network)
Cover at least: channel auto-registration; level and environment filtering; ignored exceptions;
fingerprint stability (numbers/ids in messages, line shifts); dedupe via cache and via search marker;
milestone and cooldown commenting; reopen/new/ignore on closed issues; new-issue hourly cap; circuit
breaker; redaction of every listed secret type (including nested context arrays); **no recursion**
when the handler itself fails; **no exception escapes** when GitHub returns 401/403/404/422/500 or
times out; queue vs afterResponse vs sync dispatch; `scan` parsing of a real multi-line log sample;
`test --dry-run` output. Aim for ≥ 90% coverage of `src/`.

## Repository deliverables
- `src/`, `config/`, `tests/`, `README.md` (install, env vars, how to create a fine-grained PAT, how to
  pair with **Claude Code GitHub Action** (`@claude` mention) and with **DeepSeek dsh-superagent**
  (mention the agent's login and add the token's account to its allowed list), privacy notes,
  troubleshooting), `CHANGELOG.md`, `LICENSE` (MIT), `.github/workflows/tests.yml`, `phpstan.neon`
  (level 6+) and `pint.json`.
- Code style: Laravel Pint; strict types; small final classes; constructor injection; no facades
  inside `src/` except where Laravel requires them in the service provider.

## Ask me (do not guess)
1. Package/vendor name — default `taba2177/laravel-log-issues`; confirm before creating the repo.
2. Whether the GitHub repo for the package is **private** (default) or public.
3. The fine-grained PAT for the first real test (I will put it in `.env` myself; never print it).

## Definition of done
1. `composer test`, `composer analyse` and `pint --test` pass locally and in CI.
2. Pushed to `github.com/taba2177/laravel-log-issues`, tagged `v0.1.0`.
3. `log-issues:test --dry-run` works in a fresh Laravel 12 app installed from the VCS repository.
4. A short "integrate into hostdev-store" note at the end of README:
   `composer.json` `repositories` entry for the private VCS repo, `composer require`, the 4 env vars,
   and `LOG_STACK=single,github-issues` in Dokploy → hostdev-store → Environment, then redeploy.

**Do not** modify hostdev-store in this task. Integration is a separate step after review.
