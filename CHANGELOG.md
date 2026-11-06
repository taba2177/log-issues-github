# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-11-06

### Added
- `github-issues` log channel, registered automatically; enable with `LOG_STACK=single,github-issues`.
- Monolog handler that never throws, never recurses and resolves its dependencies lazily.
- Stable fingerprints: exception class + normalized message + first application frame (`path:function`),
  overridable with a `fingerprint` context key.
- Mandatory redaction of e-mails, phone numbers, Luhn-valid card numbers, IBANs, JWTs, bearer/basic
  tokens, API keys, URL query strings, sensitive context keys at any depth, `APP_KEY`, database / redis /
  mail passwords and the package token. Request bodies, headers, cookies, sessions and user identity are
  never collected.
- Deduplication through the cache and through a hidden `<!-- log-issues:fp=… -->` issue marker.
- Noise control: milestone comments, comment cooldown, hourly new-issue cap, sampling, ignored
  exceptions/messages, environment filter, circuit breaker and GitHub rate-limit handling.
- Closed-issue policy: `reopen`, `new` or `ignore`.
- Configurable agent brief (string placeholders or Blade) and `mention_on` (`new`, `milestone`, `never`)
  for the Claude Code GitHub Action, DeepSeek dsh-superagent or any other agent.
- Delivery through a queue, after the HTTP response, or synchronously on the CLI with a short timeout.
- Commands: `log-issues:test {--dry-run}`, `log-issues:scan {path} {--since} {--dry-run}`,
  `log-issues:status {--reset}`.

[0.1.0]: https://github.com/taba2177/log-issues-github/releases/tag/v0.1.0
