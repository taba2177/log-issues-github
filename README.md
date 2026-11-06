# log-issues-github

Turn Laravel errors into **deduplicated, redacted GitHub issues** that an AI coding agent
(the Claude Code GitHub Action, DeepSeek dsh-superagent, or anything that reacts to an
`@mention`) can pick up and fix with a pull request.

```
Log::error() / unhandled exception
  └─ github-issues log channel (Monolog handler: never throws, never recurses)
       ├─ filters: level, environment, ignored exceptions/messages, sampling
       ├─ report → fingerprint → redaction
       ├─ local counters in your cache (dedupe, cooldowns, hourly cap, circuit breaker)
       └─ GitHub: create issue · comment at milestones · reopen when it comes back
```

- **Safe to drop into any app.** Every failure inside the package is swallowed (at most written to
  PHP's `error_log()`), HTTP calls have a 5 s timeout, and creating the channel cannot fail.
- **One issue per bug.** `User 123 not found` and `User 456 not found` thrown from the same function
  are the same issue, across deploys and line shifts.
- **No secrets leave the app.** See [Privacy](#privacy).
- **Agents are billed per trigger**, so the mention is written once — when the issue is created.

Requirements: PHP 8.2+, Laravel 11, 12 or 13, Monolog 3. MIT licensed, no services besides GitHub.

---

## Install

```bash
composer require taba2177/log-issues-github
php artisan vendor:publish --tag=log-issues-config   # optional
```

The repository is private: see [Install from the private repository](#install-from-the-private-repository).

## Enable (only `.env` changes)

```dotenv
LOG_STACK=single,github-issues            # the only line that changes logging
LOG_ISSUES_REPO=owner/repo                # where issues are created
LOG_ISSUES_TOKEN=github_pat_xxx           # fine-grained PAT, see below
LOG_ISSUES_MENTION=@claude                # or the agent's GitHub login, or empty
```

The package registers the `github-issues` channel itself — no edits to `config/logging.php`
(an existing channel with that name is left alone). Laravel's default `stack` channel reads
`LOG_STACK`, so errors keep going to `storage/logs/laravel.log` **and** to GitHub.

By default only `production` and `staging` report (`LOG_ISSUES_ENVIRONMENTS`). Check everything with:

```bash
php artisan log-issues:test --dry-run    # validates config, checks read access, prints a sample issue
php artisan log-issues:test              # same, then creates a real sample issue
```

If the target repository is **public** the command prints a loud warning: stack traces and file
paths would be visible to everyone. Use a private repository.

### Create the fine-grained token

1. GitHub → **Settings → Developer settings → Personal access tokens → Fine-grained tokens →
   Generate new token**.
2. **Resource owner:** the account or organisation that owns the repository.
3. **Repository access:** *Only select repositories* → pick the **one** repository.
4. **Permissions → Repository permissions:** **Issues: Read and write**. *Metadata: Read-only* is
   added automatically. Nothing else.
5. Pick an expiry, generate, and paste it into the server's environment as `LOG_ISSUES_TOKEN`.
   Never commit it. `log-issues:test` never prints it.

When the token expires, GitHub answers `401`; the package keeps counting locally, opens its
circuit breaker for 15 minutes at a time and shows the error in `php artisan log-issues:status`.

## Pair with an AI agent

Every new issue ends with an **Agent brief** (configurable) that starts with the mention:

> @claude Please investigate this production error. Find the root cause, add a failing test that
> reproduces it, fix it with the smallest safe change, and open a pull request that references this
> issue. Do not change `.env`, migrations that drop data, or payment code without asking in this
> issue first.

`LOG_ISSUES_MENTION_ON` controls when the mention is written:

| value | mention written on |
|---|---|
| `new` (default) | issue creation only |
| `milestone` | issue creation and milestone comments (10, 100, 1000 … occurrences) |
| `never` | nowhere (the brief stays, without the mention) |

Regular "seen N more times" comments and the automatic reopen comment never mention anyone.
Use `LOG_ISSUES_ON_CLOSED=new` if a regression should create a fresh issue (and trigger the agent again).

### Claude Code GitHub Action (`@claude`)

1. In the target repository install the Claude GitHub app and add the workflow — the quickest way
   is `/install-github-app` inside Claude Code. With a Claude subscription, create a token with
   `claude setup-token` and store it as the repository secret `CLAUDE_CODE_OAUTH_TOKEN`.
2. Make sure the workflow also runs on **new issues**, not only comments:

   ```yaml
   # .github/workflows/claude.yml
   name: Claude
   on:
     issues:
       types: [opened]
     issue_comment:
       types: [created]
   jobs:
     claude:
       if: |
         (github.event_name == 'issues' && contains(github.event.issue.body, '@claude')) ||
         (github.event_name == 'issue_comment' && contains(github.event.comment.body, '@claude'))
       runs-on: ubuntu-latest
       permissions:
         contents: write
         pull-requests: write
         issues: write
         id-token: write
       steps:
         - uses: actions/checkout@v4
         - uses: anthropics/claude-code-action@v1
           with:
             claude_code_oauth_token: ${{ secrets.CLAUDE_CODE_OAUTH_TOKEN }}
   ```

3. `LOG_ISSUES_MENTION=@claude`.

Issues are created with your PAT (not `GITHUB_TOKEN`), so they do trigger workflows, and the
action sees an author with write access.

### DeepSeek dsh-superagent (or any other agent)

1. `LOG_ISSUES_MENTION=@<agent-github-login>` — the login the agent listens for.
2. Add the account that owns `LOG_ISSUES_TOKEN` to the agent's **allowed users / trusted authors**
   list, otherwise it ignores issues opened by that account.
3. Adjust the brief to the agent's conventions if needed:

   ```dotenv
   LOG_ISSUES_AGENT_BRIEF="{mention} /fix — root-cause {title} in {repo}, add a regression test, open a PR."
   ```

   Placeholders: `{mention}`, `{repo}`, `{environment}`, `{level}`, `{fingerprint}`, `{title}`.
   A Blade string also works (in the published config):
   `'agent_brief' => '{{ $mention }} @if($environment === "production")URGENT: @endif please fix {{ $title }}'`.

## What an issue looks like

- **Title** (≤ 120 chars): `[production] QueryException: SQLSTATE[23000]: Integrity constraint … (app/Services/OrderService.php:88)`
- **Labels:** `bug`, `auto-reported`, `env:production`, `severity:error`
- **Body:** summary table (environment, level, first/last seen, occurrences, release, PHP/Laravel,
  route + method, console command, queue job, authenticated yes/no), the redacted message and the
  chain of previous exceptions, application frames with links to
  `https://github.com/{repo}/blob/{sha|branch}/{path}#L{line}`, collapsed vendor frames, the redacted
  log context, the agent brief and a hidden marker `<!-- log-issues:fp=<sha1> -->`.

The link ref is `LOG_ISSUES_RELEASE` / `APP_RELEASE` when it is a commit SHA, else the SHA in
`.git/HEAD` when readable, else `LOG_ISSUES_BRANCH` (default `main`). In Docker builds without
`.git`, set `APP_RELEASE` to the commit SHA at build time to get exact links.

## How deduplication and noise control work

| mechanism | default | env |
|---|---|---|
| Fingerprint | class + normalized message + first app frame `path:function` | context key `fingerprint` overrides |
| Known issue | cache, then GitHub search for the marker (search API: 30 req/min) | `LOG_ISSUES_CACHE_STORE` |
| Comments | at 10, 100, 1000 … occurrences, else at most once per cooldown | `LOG_ISSUES_COMMENT_COOLDOWN=60` |
| New issues | at most N per hour; over the cap errors are only counted | `LOG_ISSUES_MAX_NEW_PER_HOUR=10` |
| Closed issue comes back | `reopen` · `new` (links the old one) · `ignore` | `LOG_ISSUES_ON_CLOSED=reopen` |
| Circuit breaker | 5 consecutive GitHub failures → pause 15 min; `403/429` rate limits honour `Retry-After` / `X-RateLimit-Reset` | — |
| Sampling | report a share of matching records | `LOG_ISSUES_SAMPLE_RATE=1.0` |
| Ignored | `NotFoundHttpException`, `ModelNotFoundException`, `ValidationException`, `AuthenticationException`, `AuthorizationException`, `TokenMismatchException`, `ThrottleRequestsException`, `MethodNotAllowedHttpException` + `ignore_messages` regexes | published config |

Counters live in your cache for 30 days. A cache flush only costs one search request per error.

## Delivery

| situation | how the report is sent |
|---|---|
| `LOG_ISSUES_QUEUE=true` (default connection) or `=redis` (a connection) | queued `ReportToGitHub` job (`LOG_ISSUES_QUEUE_NAME` for the queue) |
| web request, no queue | after the response is sent (`terminating`) |
| CLI / worker, no queue | synchronously with a 5 s timeout (`LOG_ISSUES_TIMEOUT`) |

The job never throws, so a worker never logs a failure back into the package.

## Commands

```bash
php artisan log-issues:test [--dry-run]
php artisan log-issues:scan [path=storage/logs/laravel.log] [--since=24h] [--dry-run]
php artisan log-issues:status [--reset]
```

- **scan** parses Laravel log files (single and daily `laravel-YYYY-MM-DD.log`, multi-line stack
  traces, previous exceptions), groups entries by fingerprint and reports them through the same
  pipeline — useful for the first rollout and for apps that cannot change `LOG_STACK`. `path` may be
  a file, a directory or a glob. The byte offset per file is remembered, so it can run from the
  scheduler:

  ```php
  // routes/console.php
  Schedule::command('log-issues:scan --since=2h')->hourly()->withoutOverlapping();
  ```

- **status** lists fingerprints with counts, first/last seen and issue numbers, the circuit
  breaker, the hourly cap and the last GitHub error. `--reset` closes the circuit breaker.

## Configuration

All keys live in `config/log-issues.php`; most have an env variable:

| key | env | default |
|---|---|---|
| `enabled` | `LOG_ISSUES_ENABLED` | `true` |
| `repo` / `token` | `LOG_ISSUES_REPO` / `LOG_ISSUES_TOKEN` | — |
| `api_url` / `web_url` | `LOG_ISSUES_API_URL` / `LOG_ISSUES_WEB_URL` | `https://api.github.com` / `https://github.com` (GitHub Enterprise) |
| `level` | `LOG_ISSUES_LEVEL` | `error` |
| `environments` | `LOG_ISSUES_ENVIRONMENTS` (comma list, `*` = all) | `production,staging` |
| `labels` / `severity_label` | `LOG_ISSUES_LABELS` / `LOG_ISSUES_SEVERITY_LABEL` | `bug,auto-reported` / `true` |
| `mention` / `mention_on` / `agent_brief` | `LOG_ISSUES_MENTION` / `LOG_ISSUES_MENTION_ON` / `LOG_ISSUES_AGENT_BRIEF` | `''` / `new` / see above |
| `queue` | `LOG_ISSUES_QUEUE`, `LOG_ISSUES_QUEUE_NAME` | `false` |
| `timeout_seconds` / `retries` | `LOG_ISSUES_TIMEOUT` / `LOG_ISSUES_RETRIES` | `5` / `2` |
| `max_new_issues_per_hour` | `LOG_ISSUES_MAX_NEW_PER_HOUR` | `10` |
| `comment_cooldown_minutes` | `LOG_ISSUES_COMMENT_COOLDOWN` | `60` |
| `milestones` | — | `[10, 100, 1000, 10000, 100000]` |
| `on_closed` | `LOG_ISSUES_ON_CLOSED` | `reopen` |
| `sample_rate` | `LOG_ISSUES_SAMPLE_RATE` | `1.0` |
| `ignore_exceptions` / `ignore_messages` | — | see above / `[]` |
| `redact.keys` / `redact.patterns` / `redact.max_string_length` | — | `[]` / `[]` / `1000` |
| `release` / `branch` | `LOG_ISSUES_RELEASE` (or `APP_RELEASE`) / `LOG_ISSUES_BRANCH` | — / `main` |
| `base_path` | — | `base_path()` |
| `cache_store` | `LOG_ISSUES_CACHE_STORE` | default store |

## Privacy

Redaction is always on and runs before anything is queued or sent:

- **Never collected:** request bodies, headers, cookies, session data, the authenticated user's
  identity (only `authenticated: yes/no`, without loading a user), URLs with their query strings.
- **Masked in messages, previous exceptions and context:** e-mail addresses, phone numbers,
  Luhn-valid card numbers, IBANs, JWTs, `Bearer`/`Basic` credentials, GitHub/OpenAI/Slack/AWS key
  shapes, `password=…`-style pairs, URL query strings, the literal values of `APP_KEY`, every
  database / redis / mail password and the package's own token.
- **Masked by key at any depth of the context:** `password`, `passwd`, `pwd`, `secret`, `token`,
  `api_key`, `authorization`, `cookie`, `session`, `credential`, `private_key`, `user_id`/`userId`,
  `email`, `phone`, `credit_card`, `card_number`, `cvv`, `iban`, `ssn` (+ `redact.keys`).
- Long strings are truncated, deep or wide arrays are cut, objects become `[object Class]`.

Add your own rules with `redact.keys` and `redact.patterns`. Still: report into a **private**
repository and review what a sample issue contains (`log-issues:test --dry-run`).

## Troubleshooting

| symptom | check |
|---|---|
| Nothing happens | `php artisan log-issues:test --dry-run`: is `github-issues` in the default stack (`LOG_STACK`)? Is `APP_ENV` in `LOG_ISSUES_ENVIRONMENTS`? Is the level ≥ `LOG_ISSUES_LEVEL`? |
| Changed `.env` but nothing changed | the config is cached: `php artisan config:clear` (or redeploy) |
| `HTTP 401` in `log-issues:status` | token expired or revoked |
| `HTTP 403` / `404` | token not granted *Issues: Read and write* on this exact repository, or wrong `LOG_ISSUES_REPO` |
| `HTTP 422` | labels or body rejected; check the token can create labels (write access) |
| "circuit breaker open" | GitHub failed 5 times in a row or rate-limited us; wait or `log-issues:status --reset` |
| Duplicate issues after a cache flush | GitHub search indexes new issues with a short delay; duplicates are rare and stop once indexed |
| Agent does not react | is the mention in the issue body? Does the agent's workflow run on `issues: opened`? Is the token's account allowed? |
| Package errors | look in PHP's error log for lines starting with `[log-issues]` |

## Development

```bash
composer test        # Pest + Testbench, Http::fake() only, no network
composer analyse     # PHPStan (Larastan) level 8
vendor/bin/pint --test
```

## Integrate into hostdev-store

1. In `hostdev-store/composer.json` add the private VCS repository:

   ```json
   "repositories": [
       { "type": "vcs", "url": "https://github.com/taba2177/log-issues-github" }
   ]
   ```

2. Require it (Composer needs read access to the private package repository —
   `composer config --global github-oauth.github.com <token>` locally; in the Dokploy build, a
   `COMPOSER_AUTH={"github-oauth":{"github.com":"<token>"}}` build-time environment variable with a
   token that has *Contents: read* on `taba2177/log-issues-github`):

   ```bash
   composer require taba2177/log-issues-github:^0.1
   ```

3. In **Dokploy → hostdev-store → Environment** add:

   ```dotenv
   LOG_STACK=single,github-issues
   LOG_ISSUES_REPO=taba2177/hostdev-store
   LOG_ISSUES_TOKEN=github_pat_xxx
   LOG_ISSUES_MENTION=@claude
   ```

4. Redeploy, then run `php artisan log-issues:test --dry-run` in the container, followed by
   `php artisan log-issues:test` once to see a real sample issue. Optionally backfill with
   `php artisan log-issues:scan --since=24h --dry-run` first.

## License

MIT — see [LICENSE](LICENSE).
