# CLAUDE.md

Guidance for Claude Code (claude.ai/code) and any other coding agent working in this repository.
Where this file conflicts with the repo's own docs (`readme.txt`, `playwright/support/README.md`),
the repo wins.

## Project

Sucuri Security — Auditing, Malware Scanner and Hardening: a WordPress plugin published on
wordpress.org under the slug `sucuri-scanner`. It provides file integrity monitoring, remote malware
scanning (SiteCheck API), Sucuri firewall/WAF integration, security hardening, two-factor
authentication, audit logging, and post-hack recovery tools.

**This plugin runs on roughly a million WordPress installations.** Read that as an operating
constraint, not a brag: a regression here takes sites offline, locks admins out of their own
dashboards, or silently stops the security monitoring people installed it for. The distribution
channel is auto-update, so a bad release reaches those sites within hours and cannot be recalled.
Every rule in this file about compatibility, testing, and review exists because of that number.

- **Stack:** PHP (no framework, no runtime autoloader, no Composer classes shipped) + vanilla
  JS/CSS in `inc/`. Data lives in WordPress options and in flat PHP datastore files. TypeScript is
  dev-only, for the Playwright E2E suite.
- **PHP support:** PHP **7.4** is the floor, declared in `composer.json` (`"php": ">=7.4"`) and in
  `readme.txt` (`Requires PHP: 7.4`). E2E CI runs 7.4 and 8.0; unit CI runs 7.4 and 8.5. Code
  must parse and run on 7.4 — no typed properties with union types, no `match`, no enums, no
  constructor promotion, no first-class callable syntax, no nullsafe chains beyond `?->` (which is
  8.0+, so also out). Prefer the conservative construct.
- **WordPress support:** `readme.txt` declares `Requires at least: 6.0`, tested to 7.0. Do not reach
  for a WP API newer than what the plugin already uses without checking when it landed and
  guarding with `function_exists()`.
- **Layout:**
  - `sucuri.php` — single entry point: constants, `require_once` chain, hook registration.
  - `src/*.lib.php` — core classes, one per file, `SucuriScan<Thing>`, almost entirely static.
  - `src/settings-*.php`, `src/lastlogins*.php` — per-admin-page controllers.
  - `src/pagehandler.php` — the single AJAX dispatch point.
  - `src/globals.php` — all `add_action` / `add_filter` wiring.
  - `inc/tpl/*.tpl`, `inc/css`, `inc/js`, `inc/images`, `inc/fonts` — templates and assets.
  - `lang/` — `.pot` / translations. `.wordpress-org/` — store assets (banners, screenshots).
  - `tests/` — PHPUnit suite, fixtures, and the E2E shell seeds/runners.
  - `playwright/` — E2E specs, fixtures, and helpers (TypeScript).
- **Install:** `composer install` (PHP dev tools) and `npm ci` (Playwright, wp-env).
- **Run locally:** `npm run start` boots wp-env at `http://localhost:8888` (dev) /
  `http://localhost:8889` (tests). `npm run stop` tears it down. Requires Docker.
- **Unit test:** `make unit-test` (= `./vendor/bin/phpunit`). Single file:
  `./vendor/bin/phpunit tests/TwoFactorTest.php`. Single method: `--filter testMethodName`.
- **E2E test:** `make e2e` (whole suite, preserves environment), `make e2e-features`,
  `make e2e-mutations`, `make e2e-reset` (destructive DB reset + canonical seed),
  `make e2e-setup` (refresh users/2FA/`storageState` only).
  Targeted: `npm run test:e2e -- --project=features --grep='header'`.
- **Typecheck (E2E only):** `npm run typecheck` (`tsc --noEmit`).
- **Lint / static analysis:** phpcs + WPCS are installed as dev dependencies but **there is no
  `phpcs.xml` ruleset in the repo and no wired-up lint script**, so there is no authoritative
  automated style gate for PHP. Match the surrounding file's style by hand and say so when you
  report a task done — do not claim "lint passes".
- **Format:** `prettier` is a dev dependency with no script and no config; TypeScript/JS formatting
  is by convention. There is no PHP formatter. Because no formatter is authoritative here, do not
  reflow, realign, or reorder anything you did not otherwise have to touch — a whitespace-only diff
  in a security plugin costs a reviewer real time for zero value.
- **Translations:** `make update-translations` (`wp i18n make-pot . lang/sucuri-scanner.pot`).

### Conventions

Things a newcomer gets wrong here:

- **Direct-access guard.** Every PHP file under `src/` starts with
  `if (!defined('SUCURISCAN_INIT') || SUCURISCAN_INIT !== true) { header('HTTP/1.1 403 Forbidden'); exit(1); }`.
  A new file without that guard is directly requestable over HTTP. Copy the header block verbatim
  from a sibling file.
- **Static classes, not objects.** `SucuriScan*` classes are namespaces: `public static function`
  throughout. A couple (`SucuriScanFileInfo`, `SucuriScanCache`) are instantiated; assume static
  unless you see otherwise.
- **Long array syntax.** The codebase uses `array()`, not `[]`. Keep it — this is a 7.4-floor
  codebase written to an old WordPress standard, and mixed syntax makes diffs noisy.
- **Options are addressed by short name.** `SucuriScanOption::getOption(':headers_cors')` /
  `updateOption(':foo', $value)`. The leading `:` expands to `sucuriscan_<name>` internally. Never
  call `get_option('sucuriscan_...')` directly — you will bypass the secret-option path and the
  defaults table.
- **Secret options are encrypted.** `getSecretOptionMap()` in `src/option.lib.php` lists options
  stored via a separate AES-256-GCM path (currently the WAF API key). Adding a credential-shaped
  option means adding it to that map, not storing it plainly.
- **Datastore files are PHP, not data.** `SucuriScan::dataStorePath()` resolves to
  `wp-content/uploads/sucuri/` (overridable via the `SUCURI_DATA_STORAGE` constant, which is how
  tests redirect it). Every file written there begins with `<?php` + `// key=value;` comment lines
  + `exit(0);` so that a direct HTTP request returns nothing. Never write a plain `.json`/`.txt`
  datastore into that directory.
- **Templates escape by default.** `SucuriScanTemplate` substitutes `%%SUCURI.key%%` **escaped**
  (via `SucuriScan::escape()`) and `%%%SUCURI.key%%%` **raw**. Reach for the triple-percent form
  only for markup you built yourself, and say why in a comment.
- **Input comes through `SucuriScanRequest`.** `SucuriScanRequest::post($key, $pattern)` /
  `::get()` / `::getOrPost()` take an optional regex the value must match, and return `false`
  otherwise. Pass a pattern whenever the shape is known (`'^[a-z0-9_]+$'`, etc.) rather than
  validating after the fact.
- **Naming:** classes `SucuriScan<Thing>` in `src/<thing>.lib.php`; procedural page/AJAX functions
  `sucuriscan_<snake_case>`; templates `*.html.tpl` (page/section) and `*.snippet.tpl` (repeatable
  fragment); constants `SUCURISCAN_*`.
- **Units in names.** Lifetimes and timeouts are seconds and are named as such in
  `sucuri.php` (`SUCURISCAN_AUDITLOGS_LIFETIME`, `SUCURISCAN_MAX_REQUEST_TIMEOUT`). Keep new
  time/size values explicit at the point of definition.

## Workflow

- Plan before implementing. For any task touching more than one file, write the plan to `PLAN.md` —
  files to change, order of changes, what stays untouched — and stop for approval. Implement only
  after the plan is approved. `PLAN.md` is a working file: it must be gitignored or deleted before
  commit, never shipped (see **Release hygiene**).
- Change only what the task requires. If you notice unrelated problems, list them when you finish
  instead of fixing them.
- Do not mix unrelated concerns in one pass — infrastructure and application code, refactoring and
  behavior change, test-suite work and plugin work. Finish one, then start the other.
- Run the checks that apply before saying a task is done: `make unit-test` always;
  `npm run typecheck` for any `playwright/` change; the relevant E2E project for anything touching
  admin pages, AJAX, hardening, headers, or auth. Report failures instead of working around them.
  Never claim a check passed without running it.
- If you changed behavior, add or update a test that would fail without your change. A bug fix
  without a regression test is not finished.
- If a requirement is ambiguous, ask. Do not pick an interpretation and build on it.
- If you are stuck after two real attempts, stop and explain what you tried and what you think is
  wrong. Do not keep going with progressively looser guesses.

### Adding things — the checklists people forget

- **New `src/*.lib.php`:** add the `require_once` to `sucuri.php` *in dependency order*, and mirror
  it in `tests/autoload.php` if unit tests need it. Add the `SUCURISCAN_INIT` guard.
- **New AJAX action:** add the `form_action` → callable entry in `sucuriscan_ajax_handlers()`
  (`src/pagehandler.php`). Dispatch already enforces `checkPageVisibility()` + `checkNonce()`;
  your handler still checks its own capability (see **Security**).
- **New option:** add it to the defaults in `src/option.lib.php`; add it to the secret map if it
  holds a credential; decide and document what happens on upgrade for sites that don't have it yet.
- **New hook:** wire it in `src/globals.php`, not inline in a library class.
- **New user-facing string:** wrap in the plugin's i18n helpers with the `sucuri-scanner` text
  domain, and run `make update-translations`.

## Backward compatibility

The plugin is installed on ~1M sites that auto-update, running an enormous spread of PHP versions,
WordPress versions, hosting configurations, and existing plugin data written by every past version.

- **Never break an upgrade path.** Options, datastore files, cron schedules, and encrypted secrets
  written by an older version must keep working after an update — read the old shape, migrate it
  forward, and leave the migration in place. `getSecretOption()` already models this: it falls back
  to the legacy plaintext DB value and migrates it. Do the same.
- **Never remove or rename a public-ish surface without a deprecation path.** WP-CLI commands
  (`src/cli.lib.php`), hooks the plugin fires, option names, the `sucuriscan_*` AJAX `form_action`
  strings, and constants in `sucuri.php` are consumed by third parties, support scripts, and
  documentation. Internal helpers inside a class may change freely.
- **Fail open on the site, not closed.** A plugin error must never white-screen `wp-admin` or block
  a login. Guard optional PHP extensions (`openssl`, `curl`), optional binaries (`diff`), and
  filesystem writes; degrade with a notice instead of a fatal.
- **Assume the environment is hostile to your assumptions:** read-only filesystems, missing
  `wp-config.php` write access, `DISABLE_WP_CRON`, multisite, non-standard `WP_CONTENT_DIR`,
  reverse proxies rewriting `REMOTE_ADDR`. There are already tests for several of these
  (`RemoteAddrResolutionTest`, `IntegrityFilePathTest`) — check for a sibling test before assuming
  a case is unhandled.
- **Any change to hardening, 2FA, login, password reset, or the WAF key path can lock users out of
  their own site.** For those, the E2E `mutations` project is not optional and a rollback story
  should be stated in the PR.

## Release hygiene — what ships to wordpress.org

The wordpress.org package is built by `10up/action-wordpress-plugin-deploy` from a published GitHub
release, and the **only** thing keeping development files out of it is the `export-ignore` list in
`.gitattributes`. There is no allowlist. Anything committed at the repo root that is not listed
there ships to a million sites.

- **Any new top-level file or directory that is not plugin runtime must be added to
  `.gitattributes` as `export-ignore` in the same commit that introduces it.** This includes agent
  and tooling files — `CLAUDE.md`, `AGENTS.md`, `PLAN.md`, `MEMORY.md`, `.claude/`, `.cursor/`,
  `cypress/`, `.editorconfig`, scratch scripts, screenshots.
- Prefer `.gitignore` for anything that never belongs in git at all; use `export-ignore` for files
  that belong in the repo but not in the plugin.
- **Verify before releasing.** `make git-archive` produces the same tree the deploy action ships:
  `git archive HEAD | tar -t` and read the list. Shipping a test suite is a bloat problem; shipping
  a seed script, a `.env`, or a stored credential is a security incident.
- Version bumps touch four places and must agree: the `Version:` header in `sucuri.php`, the
  `SUCURISCAN_VERSION` constant in `sucuri.php`, `Stable tag:` in `readme.txt`, and the `readme.txt`
  changelog entry. Update `lang/sucuri-scanner.pot` when strings changed.

## Git and pull requests

Write for a reader who cares what changed for the user, not how it was built. The diff already says
how.

- If starting new work while on `main`, create a branch before committing. Never commit directly to
  `main`.
- Branch names: `feat/`, `fix/`, `chore/`, or `security/` + short kebab description
  (e.g. `feat/backup-codes`, `fix/api-messages-string`).
- Never force-push, rebase published commits, or run `git reset --hard`. Never stage blindly with
  `git add -A`; add the files you changed. Never commit `.env`, credentials, API keys, WordPress
  salts, or anything from `playwright/.auth`.
- **Never commit agent working files, and never add a new `.md` to the repo as ordinary work.**
  `PLAN.md`, `MEMORY.md`, scratch notes, audit write-ups and generated reports are working files:
  they stay untracked and out of every commit. The only markdown that may appear in a commit is a
  file git already tracks — and only when changing it was the point of the task. If work seems to
  call for a new document, propose it and let a human decide; documentation that lands unreviewed
  tends to rot into the misleading kind, and a stray `.md` at the repo root ships to a million
  sites unless someone remembers to `export-ignore` it (see **Release hygiene**).
- Commit locally and stop. The human pushes and opens PRs.
- Never merge a pull request. That is always a human's call.

### Commit messages

- A short subject line describing the change in product terms. If several things changed, follow it
  with a bullet list — still product-oriented.
- Write what the user can now do, or what stopped being broken.
  - Yes: `Fix fatal error when API response "messages" is a string`
  - No: `Add normalizeMessages() to SucuriScanAPI and update callers`
- For internal work with no user-facing effect, say what it enables or prevents:
  `Keep Playwright dev files out of the wordpress.org release`.

### Pull requests

- **Title:** lean and product-oriented. Same test as a commit subject.
- **Body:** what changed and why, in plain language. Link the `PLAN.md` for the task if there is one.
  State the upgrade impact explicitly: what happens to a site that updates from the previous version
  with existing settings and datastore files.
- **Testing section:** written for someone QAing without knowledge of the code. Where to click, what
  to enter, what they should see. Note any setup needed (seeded users, 2FA secret, WAF sandbox) and
  any case that can't be checked through the UI.
- **Closing paragraph on AI use:** one short, specific paragraph — what the agent drafted, what a
  human directed or rewrote, and anything a reviewer should look at with extra care. Write it fresh
  each time; do not paste the same sentence into every PR.

### Review gate before opening a PR

Ask the human to run an adversarial review, sized to the change. These are user-triggered commands —
an agent cannot launch them and must not pretend to have run one.

- **Always, for any PHP change:** `/code-review` on the working diff.
- **Required, not optional, when the change touches** authentication, two-factor, password reset,
  capability checks, nonce handling, the AJAX dispatcher, option/secret storage, encryption,
  hardening rules, `.htaccess`/`wp-config.php` writes, file integrity paths, or anything parsing a
  remote API response: `/security-review`, plus `/code-review ultra` for a multi-agent pass.
- **Also warranted for** changes to the bootstrap/require order, uninstall/deactivation, cron
  scheduling, or anything that runs on every admin page load — the blast radius is every install,
  not just the feature.
- State in the PR body which reviews were run and what they flagged.

## Architecture and design

### Bootstrap and load order

`sucuri.php` is the single entry point WordPress reads. It:

1. Defines `SUCURISCAN_INIT` and the global constants (paths, cache lifetimes, API URLs/versions).
   Every other file in `src/` guards on `SUCURISCAN_INIT` and 403s on direct access.
2. `require_once`s all `src/*.lib.php` classes in dependency order, then `src/pagehandler.php`, then
   the per-page controllers (`src/lastlogins*.php`, `src/settings*.php`), then `src/globals.php`
   (hook wiring), then conditionally `src/cli.lib.php` when `WP_CLI` is defined.
3. Registers action/filter hooks, deactivation/uninstall hooks, and security headers.

`tests/autoload.php` mirrors a subset of that require order against stubbed WP constants and
functions. A new `src/*.lib.php` with cross-file dependencies usually needs a `require` in both.

### Code organization

- `src/*.lib.php` — one class per file, `SucuriScan<Thing>` (`SucuriScanOption`, `SucuriScanEvent`,
  `SucuriScanRequest`, `SucuriScanHardening`, `SucuriScanFirewall`, `SucuriScanIntegrity`,
  `SucuriScanSiteCheck`, `SucuriScanPermissions`, …), behavior exposed as `public static`.
- `src/pagehandler.php` — `sucuriscan_ajax_handlers()` maps a `form_action` string to a callable;
  `sucuriscan_ajax()` runs `SucuriScanInterface::checkPageVisibility()` then
  `SucuriScanInterface::checkNonce()` and dispatches, returning
  `{ok: false, error: 'invalid ajax action'}` otherwise.
- `src/option.lib.php` — central settings store (short names, defaults, secret options).
- `src/event.lib.php` — audit/event reporting, local and to the remote Sucuri API.
- `src/permissions.lib.php` — every capability check the plugin makes, named intent-first
  (`canManagePlugin`, `canManageTwoFactorPolicy`, `canResetTwoFactorFor($user_id)`).
- `inc/tpl/*.tpl` — rendered by `SucuriScanTemplate` via pseudo-variable substitution, not a real
  template engine.
- `playwright/` — E2E suite; see `playwright/support/README.md` for the authoring conventions,
  environment prerequisites, and the list of deliberate coverage gaps.

### Design rules

- Choose the simplest implementation that fully meets the current requirement. This codebase has no
  DI container, no autoloader, and no abstraction layer to hide behind; speculative indirection here
  is pure cost.
- Grow in layers. Start from the smallest version that works end to end and build each capability on
  something that already works. Never trade a working plugin for unfinished complexity.
- Make long-term decisions deliberately about option names, datastore file formats, and the AJAX
  action surface — those are effectively permanent once shipped (see **Backward compatibility**).
  Behind those boundaries, prefer the simple implementation you can replace cheaply.
- Keep the layers separate: request parsing (`SucuriScanRequest`), authorization
  (`SucuriScanPermissions`), domain logic (the `*.lib.php` classes), persistence
  (`SucuriScanOption` / `SucuriScanCache`), and presentation (`SucuriScanTemplate` + `inc/tpl`).
  Library classes should not reach into `$_POST` or echo markup.

## Dependencies

- **The shipped plugin has zero runtime dependencies.** Everything in `composer.json` and
  `package.json` is `require-dev` or dev tooling, and `vendor/` and `node_modules/` are
  `export-ignore`d. Do not introduce a runtime Composer dependency or a bundled JS library without
  an explicit decision from the maintainers — it changes the plugin's distribution and its
  wordpress.org review posture.
- Lean on WordPress core APIs before writing your own. Check core for the capability (HTTP,
  filesystem, cron, nonces, escaping, sanitization) before hand-rolling.
- Propose new dev dependencies before adding them. State what it replaces, why the existing
  packages can't do it, and its license. GPL-compatible only — this plugin is GPLv2-or-later.

## Security and data handling

This is a security plugin. A vulnerability here is worse than a vulnerability in an ordinary
plugin: it sits in `wp-admin`, holds firewall API keys and 2FA secrets, and its users installed it
specifically because they were worried.

- **Every state-changing request checks a nonce and a capability.** Nonce via
  `SucuriScanInterface::checkNonce()` (or a scoped `wp_verify_nonce()` for per-user profile
  actions); capability via a `SucuriScanPermissions::can*()` method. The AJAX dispatcher's nonce
  check is not a substitute for the handler's capability check — a subscriber can hold a valid
  nonce. A new endpoint that skips either is a bug, not a style problem.
- **Authorization is per target, not just per actor.** Anything acting on another user (2FA reset,
  password reset, user listing) goes through the scoped helper
  (`SucuriScanPermissions::canResetTwoFactorFor($target_user_id)`), never a bare
  `current_user_can('manage_options')` at the call site.
- **Validate on the way in, escape on the way out.** `SucuriScanRequest::post($key, $pattern)` with
  a regex for input; `SucuriScan::escape()` / the `%%SUCURI.x%%` template form for output. Trust
  neither because the other exists.
- **Parameterized queries only.** There are exactly two direct `$wpdb` queries in the codebase
  (`src/option.lib.php:1902` and `sucuri.php:325`), both interpolating only constants and table
  names. Any new query must use `$wpdb->prepare()`; prefer the options API over SQL entirely.
- **Never log or echo secrets** — WAF API keys, TOTP secrets and their QR payloads, WordPress salts,
  password-reset tokens, session cookies. Redact before logging, not after. This applies to audit
  log entries, admin notices, error messages, and debug output alike.
- **Path handling is attacker-facing.** Integrity scanning, hardening, and post-hack tools take file
  paths that reach `unlink`, `file_put_contents`, and `.htaccess` writes. Normalize and confine to
  the WordPress root; there is existing coverage in `IntegrityFilePathTest` and
  `HardeningAllowlistRegexTest` — extend it rather than adding an unguarded path.
- **Never copy production data into tests, fixtures, or seeds.** Generate it. Never point a test or
  a seed script at a real site.
- **Never run destructive commands against a database with real data** — the wp-env *tests* instance
  is the only thing `make e2e-reset` may wipe, and even then only the tests DB.

## External services and integrations

The plugin talks to the SiteCheck/Sucuri API (`SUCURISCAN_API_URL`), the WAF API
(`SUCURISCAN_CLOUDPROXY_API`), and wordpress.org for plugin/theme metadata.

- **A remote failure must never break the site.** If SiteCheck is down, the dashboard still renders;
  if the audit-log upload fails, the local event is still recorded. Timeouts are bounded by
  `SUCURISCAN_MAX_REQUEST_TIMEOUT` (5s) — keep every outbound call bounded and decide, in code, what
  happens when the provider is slow or down.
- **Treat every remote response as untrusted input.** It crosses a trust boundary regardless of who
  operates the endpoint; a past fatal came from assuming an API field was an array when it was a
  string (`ApiNormalizeMessagesTest`). Check types before indexing, never `eval`/`unserialize` a
  response, and escape anything rendered.
- **Tests never call live third-party services.** Unit tests stub with Brain\Monkey; E2E stubs
  `admin-ajax.php` with `page.route` and uses JSON fixtures in `playwright/data/`. Live-WAF testing
  is a deliberate, documented coverage gap — exercising a real key mutates a shared external Sucuri
  account with no reliable cleanup. Do not add tests that talk to the live WAF.
- **No browser artifacts in E2E.** Traces, screenshots, videos, and HTML reports can capture
  credentials, TOTP secrets, and salts; CI sets `PLAYWRIGHT_NO_COPY_PROMPT=1` for the same reason.
  Keep it that way.

## Testing

### PHP unit tests (PHPUnit 9 + Brain\Monkey)

Tests run with `processIsolation="true"` (`phpunit.xml`) because the plugin defines global constants
once at bootstrap. `tests/autoload.php` defines the WP constants (`tests/constants.php`), stubs a
minimal set of WordPress functions, and requires the same `src/*.lib.php` files the plugin does.
When a test needs an unstubbed WP function, add a `Functions\when(...)` mock in the test, or a
global stub in `tests/autoload.php` if many tests need it. Fixtures live in `tests/fixtures/`;
`SUCURI_DATA_STORAGE` redirects the datastore path there.

Several classes are covered by multiple files from different angles (`IntegrityTest` +
`IntegrityFilePathTest`, `HardeningTest` + `HardeningAllowlistRegexTest` + `HardeningXMLRPCTest`) —
check for a sibling before assuming something is untested.

### E2E (Playwright + wp-env)

Requires Docker. `specs/features/` is non-destructive and touches a disjoint slice of state;
`specs/mutations/` wipes options, changes auth/2FA/passwords/keys, or toggles the plugin. Both
depend only on the `setup` project, which saves the admin `storageState`. `workers: 1` and
`fullyParallel: false` — one shared mutable WordPress instance. Never run two Playwright processes
against the same wp-env; a workspace lock serializes accidental overlap.
Full authoring guide, helper reference, and environment prerequisites:
`playwright/support/README.md`.

### Test naming

Test names state the behavior being verified. A failing assertion should say what broke without
opening the test file.

## Readability

Code is read far more often than it is written — and this code is read by security researchers and
by whoever is debugging a compromised site at 2am.

- Comments explain why, not what. If a comment restates the line below it, delete the comment or fix
  the name.
- Comment the surprising: why a check is ordered the way it is, why a fallback exists, which
  environments a guard is protecting against, why a raw template variable is safe here.
- One function, one task. If a block needs a comment to label it, extract it.
- Put units in names wherever ambiguity is possible: `lifetime_seconds`, `timeout_seconds`,
  `size_bytes`.
- Delete unused code. Never comment it out or leave it behind a dead flag — dead code in a security
  plugin gets read as a live attack surface by the next auditor.
