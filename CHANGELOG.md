# Changelog

All notable changes to Onsite Spam Guard are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The user-facing changelog shipped to WordPress.org lives in the
`== Changelog ==` section of `readme.txt`; keep the two in sync.

## [Unreleased]

## [Unreleased]

### Added
- `simple_spam_shield_duplicate_window_seconds` and
  `simple_spam_shield_rate_limit_window_seconds` settings on the Guards tab.
  Both windows were fixed at 60 seconds in `config/guards.json` with no UI, so
  the only way to move them was editing a file inside the plugin that an update
  overwrites. A rate limit of "20 per hour" is now expressible, and a busy
  comment thread can take a shorter duplicate window rather than disabling the
  guard. Defaults are unchanged at 60 seconds, and the JSON value remains the
  fallback when the option is unset.

### Changed
- The rate-limit maximum is labelled "max submissions" with the window stated
  separately. "per minute" was baked into the label while the window was fixed.
- Number settings are clamped to their own min/max when saved. They were
  sanitised with `absint()`, which ignores the field's range entirely and
  returns the *absolute* value, so -5 was stored as 5 rather than the floor.

### Fixed
- A window of 0 can no longer produce a permanent block. `set_transient()`
  treats 0 as "no expiration" (`wp-includes/option.php`), so a zero window
  would have made a duplicate block last forever and a throttled sender
  throttled forever. Both guards now floor the window at 1 second when reading
  it, which covers a value written by anything other than the settings form —
  the save-time clamp alone would not.

## [1.3.0] - 2026-08-24

### Added
- Other plugins can register their own guard through the new
  `simple_spam_shield_guards` filter. A registered guard participates fully:
  sorted by weight, short-circuiting on failure, recorded in the log, and given
  its own on/off toggle on the Guards settings tab, because the pipeline and the
  settings screen now read the same filtered definitions. The filter can also
  adjust or remove a built-in guard. A class that does not implement
  `Guard_Interface`, or a filter that returns something other than an array, is
  refused and the built-in guards are kept rather than leaving the site
  unprotected.
- New `simple_spam_shield_blocked` action, fired once a submission has been
  blocked and logged, carrying the deciding guard, the context, every guard that
  matched, and the normalized submission data.
- The spam log now records **every** guard that matched a blocked submission,
  not just the first one to fire. The guard that decided the outcome is still
  shown on its own line in the log viewer, with any additional matches listed
  beneath it, so the guard filter and the 7-day summary are unchanged.
- `Guard_Interface` splits evaluation from recording. `check( $data, $context )`
  evaluates and is called whatever the outcome, so the log can record every
  guard that matched; the runner keeps its short-circuit for the *verdict*, so
  the highest-weight failure still decides what the visitor sees. The new
  `commit( $data, $context )` runs on every enabled guard only once no guard has
  objected, and is where state describing an accepted submission belongs.
  `Abstract_Guard::commit()` is an empty default, so guards holding no state are
  unaffected.

### Changed
- `Duplicate` now records on commit instead of during its check, fixing a bug
  where a submission rejected by any lower-weight guard was still entered in the
  duplicate cache. A visitor who fixed whatever tripped them and resubmitted was
  then refused a second time as a duplicate of their own blocked attempt, naming
  the wrong reason. Only `Honeypot` outranks `Duplicate`, so this affected
  rejections from six of the eight guards.
- `Rate_Limit` now counts every attempt, including rejected ones — the opposite
  correction, for the opposite reason. It sits below `Honeypot`, so it was
  previously never counting the traffic it exists to throttle: a bot could flood
  a form indefinitely without consuming any budget, while a legitimate visitor
  who tripped a guard once did consume it. The limiter was effectively
  throttling only real users.

  Both of these are bugs in released code, not regressions introduced during
  this cycle. In 1.2.1 the runner returned on the first failure, so `Rate_Limit`
  never ran once a higher-weight guard had blocked, and `Duplicate` recorded
  unconditionally inside its check. Sites running 1.2.0 or 1.2.1 with either
  guard enabled are affected.
- Database schema 1.1 -> 1.2 adds a `guards_matched` column, applied by
  `dbDelta` on upgrade. Existing rows are preserved and simply carry an empty
  value.

## [1.2.1] - 2026-08-21

### Fixed
- Allowlist CIDR matching ignored IPv6 entirely. `ip_in_cidr()` used
  `ip2long()` and 32-bit arithmetic, so a range like `2001:db8::/32` silently
  never matched — no warning, no log entry, no effect — in the very control
  that exists to rescue legitimate visitors from false positives. It now
  compares the packed binary forms from `inet_pton()`, handling both address
  families through one path, and rejects mismatched families and malformed
  prefixes rather than over-matching. The allowlist previously had no test
  coverage at all; it now has 11 cases exercised through the public path.
- The uninstall routine removed duplicate-detection transients but not the
  rate-limit transients added in 1.2.0, leaving rows in `wp_options` after
  deletion. It now matches on the shared prefix, so families added later are
  covered too. The `LIKE` patterns also now escape `_`, which is a
  single-character wildcard in SQL and made the old patterns looser than they
  appeared.
- The honeypot guard read its field name from `config/guards.json`, but the
  rendered markup, the front-end script and all eight integration reads
  hardcoded it. Changing the configured value made the guard look for a field
  nothing produced, silently disabling the highest-weight guard. The name is
  now a constant; see #23 for wiring it through properly, which would allow a
  per-site randomised name.

## [1.2.0] - 2026-08-21

### Added
- Rate-limit guard that throttles repeated submissions from the same sender
  (logged-in user, or IP when anonymous) within a short window. Off by default;
  suited to authenticated flows such as private messages.
- Option to also apply WordPress's Disallowed Comment Keys (Settings →
  Discussion) to every protected form via `wp_check_comment_disallowed_list()`,
  extending that one core blocklist beyond comments to reviews, Jetpack forms,
  and public-API integrations.

### Changed
- CI now verifies the translation template still matches the strings in the
  source (`bin/check-pot.sh`). The version check could only see the template's
  version, so a release could previously ship a `.pot` missing newly added
  strings.
- Documented in `CONTRIBUTING.md` why the internal `simple_spam_shield_*`
  prefixes deliberately do not match the `onsite-spam-guard` slug.

### Removed
- The unused `simple_spam_shield_block_log` option from the uninstall cleanup
  list; nothing has written it since logging moved to a custom table.

## [1.1.3] - 2026-08-19

### Changed
- Tested up to WordPress 7.1. No code changes: every core API the plugin
  uses is present and undeprecated in 7.1, `preprocess_comment` and
  `pre_comment_approved` are unchanged, and the test suite, coding
  standards and Plugin Check all pass against it.

## [1.1.2] - 2026-08-03

### Changed
- Renamed to **Onsite Spam Guard** (slug `onsite-spam-guard`) following the
  WordPress.org review: "Simple Spam Shield" was judged too generic and
  overlapping with existing directory entries (`solverguard-spam-shield`,
  `gotechark-advanced-spam-shield-for-contact-form-7`), and "Simple" is
  explicitly called out as a generic word that does not resolve similarity.
  The new name leads with a distinctive term that also states the
  differentiator: everything runs on the site, with no external service.
- The rename is deliberately **minimum-depth**: display name, text domain,
  slug, folder, and artwork change; the `Simple_Spam_Shield\` namespace,
  `SIMPLE_SPAM_SHIELD_*` constants, `simple_spam_shield_*` option keys, the
  log table name, and the public API function names are unchanged. Verified
  with Plugin Check that non-slug-matching prefixes raise no findings, so
  existing installs keep their settings and logs with no migration.

### Security
- The spam-log bulk-delete handler now verifies `current_user_can()` directly
  in addition to the nonce, rather than relying on the page-level capability
  check of its caller.

## [1.1.1] - 2026-07-12

### Fixed
- Jetpack contact form submissions could be flagged as spam on their own
  metadata. Jetpack passes the `jetpack_contact_form_is_spam` filter the array
  from `prepare_for_akismet()`, which carries the visitor's input *alongside*
  site/server metadata — `blog` (home URL), `referrer`, `permalink`,
  `REQUEST_URI`, and every `HTTP_*` header. `normalize()` concatenated every
  string value into the inspected content, so four URLs the visitor never typed
  could exceed a link limit of three, and keywords could match the user-agent
  string. It now allow-lists only the visitor's own input: `comment_content`,
  `comment_author_url`, and each `contact_form_field_*` value.
- The sender's name and email were read from `name`/`email` keys that Jetpack
  never sets; they come from `comment_author` / `comment_author_email`. They
  were therefore always empty, so the keyword guard never searched them and the
  duplicate guard's hash was weaker.

## [1.1.0] - 2026-06-25

### Added
- Settings are grouped into tabs (General, Guards, Allowlist, Logging) to cut
  down on scrolling. Progressive enhancement: without JavaScript every section
  is shown, so nothing becomes unreachable.
- New "Delete all plugin data when this plugin is deleted" setting (on by
  default) so you can keep your settings and logs across a reinstall.

### Fixed
- Uninstall now removes the plugin's table, options, transients, and scheduled
  task on **every site of a multisite network**, rather than only the site the
  plugin was deleted from.

## [1.0.1] - 2026-06-25

### Changed
- `simple_spam_shield_check()` now accepts the hidden honeypot/token/behavioral
  fields explicitly in its `$fields` argument (falling back to `$_POST`), so
  REST/AJAX endpoints with a JSON body — where `$_POST` is empty — can pass
  them from the request.
- The time-gate and signature guards skip (rather than block) when no signed
  token is supplied for a custom context, so a content-only integration is not
  falsely rejected. The built-in comment and review forms still hard-fail on a
  missing token.

## [1.0.0] - 2026-06-24

Initial release.

### Added
- Guard pipeline that runs independent spam checks in weighted order and
  short-circuits on the first block: honeypot, duplicate detection, time
  gate, signature (HMAC-signed token), link limit, keyword block, and
  optional behavioral analysis.
- Integrations for WordPress comments, WooCommerce product reviews, and
  Jetpack contact form blocks, each normalizing its data into a shared
  shape consumed by the guard pipeline.
- Server-signed form token driving both the time gate (tamper-proof issue
  time) and the signature guard (proof the form came from this site).
  Because the HMAC does not expire, protection stays valid under full-page
  caching without producing false positives.
- Allowlist supporting exact IPs, CIDR ranges, email addresses, and email
  domains, with an optional trusted-proxy mode for IP detection (off by
  default; direct connection IP is used otherwise).
- Blocked comments and reviews routed to the spam queue by default
  (recoverable), with an option to reject them outright with an error.
- Database-backed logging with a paginated admin viewer: filter by guard
  and context, a user-agent column, and a cached 7-day "blocked / most
  active guard" summary.
- Configurable log retention with a daily auto-purge (default 30 days;
  0 keeps entries indefinitely).
- Suggested privacy-policy content describing what is logged, and a clean
  uninstall routine that removes the table, options, scheduled task, and
  transients.
- Public integration API (`includes/api.php`) so other plugins can protect
  their own forms: `simple_spam_shield_check()`,
  `simple_spam_shield_protect_selector()`, and `simple_spam_shield_field_markup()`.

[Unreleased]: https://github.com/jwincek/onsite-spam-guard/compare/v1.3.0...HEAD
[1.3.0]: https://github.com/jwincek/onsite-spam-guard/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/jwincek/onsite-spam-guard/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/jwincek/onsite-spam-guard/compare/v1.1.3...v1.2.0
[1.1.3]: https://github.com/jwincek/onsite-spam-guard/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/jwincek/onsite-spam-guard/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/jwincek/onsite-spam-guard/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/jwincek/onsite-spam-guard/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/jwincek/onsite-spam-guard/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/jwincek/onsite-spam-guard/releases/tag/v1.0.0
