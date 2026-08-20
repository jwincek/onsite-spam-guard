# Changelog

All notable changes to Onsite Spam Guard are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The user-facing changelog shipped to WordPress.org lives in the
`== Changelog ==` section of `readme.txt`; keep the two in sync.

## [Unreleased]

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

[Unreleased]: https://github.com/jwincek/onsite-spam-guard/compare/v1.1.3...HEAD
[1.1.3]: https://github.com/jwincek/onsite-spam-guard/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/jwincek/onsite-spam-guard/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/jwincek/onsite-spam-guard/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/jwincek/onsite-spam-guard/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/jwincek/onsite-spam-guard/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/jwincek/onsite-spam-guard/releases/tag/v1.0.0
