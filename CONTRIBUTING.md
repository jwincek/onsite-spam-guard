# Contributing to Onsite Spam Guard

Thanks for your interest in improving the plugin. This document covers the
local setup, the quality gates, and how to extend the guard pipeline.

## Requirements

- PHP 8.2+
- [Composer](https://getcomposer.org/)
- WordPress 6.2+ (only needed to run the plugin or Plugin Check, not for unit tests)

## Setup

```bash
composer install
```

This installs the development tooling only (PHP_CodeSniffer + WordPress
Coding Standards, and PHPUnit). The plugin itself ships with **no runtime
dependencies**, so nothing under `vendor/` is included in the distributed
package.

## Quality gates

All three run in CI (`.github/workflows/ci.yml`) on every push and pull
request, and should pass locally before you open a PR.

### Coding standards

```bash
composer lint        # check
composer lint:fix    # auto-fix what can be fixed
```

The ruleset is the full WordPress standard (`phpcs.xml.dist`) with **every
`WordPress.Security.*` and `WordPress.DB.PreparedSQL*` sniff enforced**. A
small set of purely stylistic sniffs is excluded to match the codebase's
deliberate modern style (short arrays, typed signatures); see the comments
in `phpcs.xml.dist` for the rationale. Direct queries against the plugin's
own custom log table are expected — keep them prepared and column-whitelisted.

### Unit tests

```bash
composer test
```

Tests live in `tests/` and run **without a WordPress install or a
database**. `tests/bootstrap.php` provides lightweight stubs for the handful
of WP functions the pure logic touches (options, transients, `WP_Error`,
sanitizers) and reuses the plugin's own autoloader. Keep tests fast and
dependency-free; if a unit needs heavy WordPress integration, prefer
refactoring the pure logic out so it can be tested in isolation (as with
`Token`, `Request`, and `Database_Manager::build_filter()`).

Every guard has a corresponding `tests/<Guard>Test.php`. New guards must
ship with tests.

### Translation template

```bash
bin/check-pot.sh
```

Verifies `languages/onsite-spam-guard.pot` still matches the strings in the
source. Only `msgid` lines are compared, because `POT-Creation-Date` changes on
every run. Regenerate with:

```bash
wp i18n make-pot . languages/onsite-spam-guard.pot --slug=onsite-spam-guard
```

### Plugin Check

The WordPress.org review tool. Run it against a distribution copy (dev
files excluded) so it sees only what ships:

```bash
# Requires the Plugin Check plugin installed in a local WordPress site.
rsync -a --exclude-from=<(grep -v '^#' .distignore) ./ /path/to/wp-content/plugins/onsite-spam-guard/
wp plugin check onsite-spam-guard
```

Only `WordPress.DB.DirectDatabaseQuery` warnings are expected (inherent to a
custom-table plugin); there should be no errors.

## Architecture

```
config/                JSON definitions (guard rules, default settings)
includes/core/         Infrastructure: Config, Guard_Runner, Database_Manager,
                       Assets, Admin, Request, Token
includes/guards/       One class per spam check (the "abilities" layer)
includes/integrations/ Thin consumers hooking WP Comments, WooCommerce, Jetpack
admin/                 WP_List_Table for the spam log viewer
assets/                Front-end honeypot CSS + guard JS
tests/                 PHPUnit unit tests
```

Guards are independent checks that implement `Guard_Interface` (most extend
`Abstract_Guard`). The `Guard_Runner` loads them from `config/guards.json`,
sorts by weight (highest first), and runs them as a pipeline — the first
failure short-circuits and blocks the submission. Integrations normalize
their form data into a common shape and delegate all checking to the runner,
so guards never need to know about comment arrays vs. Jetpack field data.

## Adding a new guard

1. **Create the class** in `includes/guards/class-<slug>.php`:

   ```php
   namespace Simple_Spam_Shield\Guards;

   final class My_Guard extends Abstract_Guard {
       public function check( array $data, string $context, bool $observe_only = false ): \WP_Error|true {
           // Return true to pass, or $this->fail( $message ) to block.
       }
   }
   ```

   **`$observe_only` matters if your guard changes state.** The runner keeps
   evaluating after a submission has already been blocked, so the log can record
   every guard that matched rather than only the first. Guards called that way
   must return their verdict *without* writing anything — no transients, no
   options, no queries that mutate. `Duplicate` and `Rate_Limit` are the worked
   examples: both put their write on the pass path behind
   `if ( ! $observe_only )`. The verdict must be identical either way. A guard
   with no side effects can ignore the flag.

   The file/class naming follows the autoloader: `My_Guard` →
   `class-my-guard.php`.

2. **Declare it** in `config/guards.json` with a `label`, `description`,
   `enabled_by_default`, `weight`, and any per-guard thresholds (read in the
   guard via `$this->config[...]`).

3. **Register it** in `Guard_Runner::definitions()`'s `$builtin_classes`
   (slug → class).

   The on/off toggle appears on the settings page automatically, because
   `Admin::register_settings()` and the pipeline both read
   `Guard_Runner::definitions()`. Add a numeric/text setting in
   `Admin::register_settings()` only if the guard needs a threshold.

4. **Add tests** in `tests/My_GuardTest.php`, covering both the blocking and
   passing paths plus the Jetpack-context behavior if the guard depends on
   JS-injected fields.

**Guards belonging to another plugin** do not go through steps 2 and 3. They are
registered from outside with the `simple_spam_shield_guards` filter, which
carries the class in the definition itself — see "Adding your own guard" in
`README.md`. Anything the filter returns that is not a `Guard_Interface`
implementation is refused with `_doing_it_wrong()`, and a filter that returns a
non-array leaves the built-in guards in place rather than dropping the site's
protection. Keep `definitions()` the single source of truth for what the
pipeline runs, so a registered guard is never a second-class citizen.

**Known limitation:** the runner only puts a guard in observe mode once an
*earlier* guard has decided the block, so `Duplicate` and `Rate_Limit` still
record submissions rejected by a lower-weight guard. Tracked in issue #26; a new
state-holding guard inherits the same caveat.

## Distribution

The shipped package is runtime-only. `.distignore` is the single source of
truth for what is excluded (dev tooling, tests, source control, the
GitHub-facing `README.md`); `readme.txt` is the user-facing readme. Build it
locally with:

```bash
composer build          # -> build/onsite-spam-guard/
```

Both the Plugin Check CI job and the WordPress.org deploy use this same script,
so there is no second exclude list to keep in sync.

## Naming: why internals do not match the slug

The plugin's slug and text domain are `onsite-spam-guard`, but its internals are
still `simple_spam_shield_*` option keys, `SIMPLE_SPAM_SHIELD_*` constants, a
`Simple_Spam_Shield\` namespace, and `simple_spam_shield_*` public API
functions. **This is deliberate. Please do not "fix" it.**

The 1.1.2 rename (from "Simple Spam Shield", at the request of the
WordPress.org review) was intentionally *minimum-depth*: the display name, text
domain, slug, directory and artwork changed; nothing persisted or externally
depended upon did.

Three reasons:

1. **Option keys and the log table are persisted.** Renaming them would make
   every existing install silently lose its settings and its log history, or
   require a migration routine that then has to be carried forever.
2. **The public API function names are a contract.** `simple_spam_shield_check()`
   and its siblings are called by other plugins — `wc-artisan-tools` alone calls
   them from seven places. Renaming them is a breaking change for consumers.
3. **Plugin Check does not require slug-matching prefixes.** This was verified
   empirically rather than assumed: a throwaway rename carrying the new slug and
   text domain but the old internal prefixes produced zero prefix and zero
   text-domain findings. The check wants a *distinctive* prefix, not one derived
   from the slug.

If this is ever revisited, a full internal rename needs: a migration routine for
the options and the table, a deprecation shim keeping the three public API
functions working, and a coordinated release with every dependent plugin.

## Releasing

GitHub is the source of truth; the WordPress.org SVN repository is a publish
target that is never edited by hand.

1. **Bump the version** everywhere it appears — the plugin header `Version`,
   the `SIMPLE_SPAM_SHIELD_VERSION` constant, `readme.txt` `Stable tag`, a new
   `## [x.y.z]` heading in `CHANGELOG.md`, and a matching `= x.y.z =` section
   in the `readme.txt` changelog.
2. **Regenerate the translation template** so its header carries the new
   version:
   ```bash
   wp i18n make-pot . languages/onsite-spam-guard.pot --slug=onsite-spam-guard
   ```
3. **Check consistency** (CI runs this on every push, and the release workflow
   runs it against the tag):
   ```bash
   composer check-versions
   ```
4. **Tag and push.** That is the only manual publish step:
   ```bash
   git tag v1.2.0 && git push origin v1.2.0
   ```

`.github/workflows/release.yml` then validates the tag against the plugin
version, re-runs lint and tests, builds the package, commits it to SVN
`trunk/` and `tags/<version>/`, syncs `.wordpress-org/` to the SVN `assets/`
directory, and attaches an installable zip to the GitHub Release.

The SVN deploy step is skipped until the `SVN_USERNAME` and `SVN_PASSWORD`
repository secrets are set, so tagging works safely before the plugin is
approved on WordPress.org.

**`Stable tag` is the release switch.** WordPress.org serves whatever
`tags/<Stable tag>/` contains, so tagging code without bumping `Stable tag`
silently keeps users on the old version. That is exactly what
`bin/check-versions.sh` exists to prevent.

## Reporting security issues

Please do not open public issues for security vulnerabilities. Report them
privately to the maintainer so a fix can be prepared before disclosure.
