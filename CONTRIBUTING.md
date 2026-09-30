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
Coding Standards, PHPStan, and PHPUnit). The plugin itself ships with **no runtime
dependencies**, so nothing under `vendor/` is included in the distributed
package.

## Quality gates

All of these run in CI (`.github/workflows/ci.yml`) on every push and pull
request, and should pass locally before you open a PR. CI also checks that the
version is the same everywhere it is recorded (`composer check-versions`; see
Releasing).

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

### Static analysis

```bash
composer analyse
```

PHPStan at level 5, with WordPress stubs, over `includes/`, `admin/`, the main
plugin file and `uninstall.php`. A value that comes back from a filter is
another plugin's code: annotate it `/** @var mixed */` and check it, rather
than trusting a docblock that says it is an array.

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
`Token`, `Request`, and `Database_Manager::build_filter()`). What only a real
WordPress can show is the smoke test's job, below. `tests/README.md` maps the
test files and explains the stubs.

Every guard has a corresponding `tests/<Guard>Test.php`. New guards must
ship with tests.

### Smoke test on real WordPress

```bash
composer build
tests/smoke/run.sh /path/to/throwaway/wordpress build/onsite-spam-guard
```

Installs the built package into a real WordPress and checks, stage by stage: an
upgrade from the previous release (files replaced, no reactivation, no
`admin_init` — as an automatic update runs), uninstall, fresh activation, the
admin screens, the guard pipeline, the abilities, every integration with its
host plugin absent, and a debug log free of errors from the plugin. **It
uninstalls the plugin, so use a WordPress you can throw away**, with `WP_DEBUG`
and `WP_DEBUG_LOG` on.

CI runs it at both ends of the range the plugin claims — WordPress 6.2 on PHP
8.2 (`Requires at least`, `Requires PHP`) and WordPress 7.1 on PHP 8.4
(`Tested up to`) — and a step fails if either stops matching the header or
readme. When one of those moves, move the matrix in `ci.yml` with it.
`tests/README.md` lists the variables the script takes.

### Translation template

```bash
bin/check-pot.sh
```

Verifies `languages/onsite-spam-guard.pot` still matches the strings in the
source. Only `msgid` lines are compared, because `POT-Creation-Date` changes on
every run. Regenerate with:

```bash
wp i18n make-pot . languages/onsite-spam-guard.pot --slug=onsite-spam-guard --exclude=build
```

### Plugin Check

The WordPress.org review tool. Run it against the built package, so it sees only
what ships (it needs the Plugin Check plugin active on a local WordPress):

```bash
composer build
wp plugin check "$PWD/build/onsite-spam-guard" --slug=onsite-spam-guard --ignore-warnings
```

- **Pass an absolute path.** Given a relative one, it has exited successfully
  without checking anything.
- **Pass `--slug`.** Plugin Check expects the text domain to match the
  directory name, which for a built copy elsewhere is not the plugin's.

Only `WordPress.DB.DirectDatabaseQuery` warnings are expected (inherent to a
custom-table plugin); there should be no errors. CI runs the same check against
WordPress 7.1, including its WordPress-function compatibility check, which
fails on a call to a function newer than `Requires at least` unless a
`function_exists()` guard in the same file protects it.

## Architecture

```
config/                JSON definitions (guard rules, default settings)
includes/api.php       Public functions other plugins call (simple_spam_shield_check() …)
includes/core/         Infrastructure: Config, Guard_Runner, Database_Manager, Contexts,
                       Monitor_Review, Proxy_Diagnostics, Request, Token, Assets, Admin
includes/guards/       One class per spam check
includes/integrations/ One thin consumer per protected form (comments, WooCommerce,
                       Jetpack, Contact Form 7, WP Job Manager, BuddyPress messages,
                       registration), plus the Abilities API
admin/                 WP_List_Table for the spam log viewer
assets/                Front-end honeypot CSS and guard JS; settings-page CSS and JS
tests/                 PHPUnit unit tests; tests/smoke/ runs against real WordPress
bin/                   Build, consistency checks, screenshots (not shipped)
```

Guards are independent checks that implement `Guard_Interface` (most extend
`Abstract_Guard`). The `Guard_Runner` loads them from `config/guards.json`,
sorts by weight (highest first), and runs them as a pipeline: every enabled
guard evaluates the submission, and the highest-weight failure is the verdict.
Integrations normalize their form data into a common shape and delegate all
checking to the runner, so guards never need to know about comment arrays vs.
Jetpack field data.

## Adding a new guard

1. **Create the class** in `includes/guards/class-<slug>.php`:

   ```php
   namespace Simple_Spam_Shield\Guards;

   final class My_Guard extends Abstract_Guard {
       public function check( array $data, string $context ): \WP_Error|true {
           // Return true to pass, or $this->fail( $message ) to block.
       }
   }
   ```

   **A guard is evaluated whatever the outcome.** The runner keeps going after a
   submission has been blocked, so the log can record every guard that matched
   rather than only the first. `check()` must return the same verdict either
   way.

   **State goes in `commit()`, not `check()`.** The runner calls `commit()` on
   every enabled guard only once no guard has objected, so a guard never records
   a submission that something else rejected. `Abstract_Guard::commit()` is an
   empty default; override it only if your guard remembers something.

   The two built-in state-holding guards sit on opposite sides of this on
   purpose, and the reasoning is worth understanding before adding a third:

   | Guard | Question it asks | Records |
   | --- | --- | --- |
   | `Duplicate` | have I already *taken* this content? | in `commit()` — a rejected submission was never taken |
   | `Rate_Limit` | is this sender *hammering* the form? | in `check()` — a rejected attempt is still an attempt |

   Getting `Rate_Limit` the other way round means a bot can flood the form
   forever without ever consuming budget, while a legitimate visitor who trips a
   guard once does consume it — the limiter ends up throttling only real users.

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

   If sites should be able to set that threshold **per form**, read it through
   `$this->threshold()` and add it to `Contexts::THRESHOLDS` with its bounds and
   default (the default must match `config/guards.json`; `ContextDefaultsTest`
   fails if they drift). That makes it available on the Per-form tab and as a
   context default.

4. **Add tests** in `tests/MyGuardTest.php`, covering both the blocking and
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

## Screenshots

The WordPress.org screenshots in `.wordpress-org/` are generated, not taken by
hand:

```sh
npm install                       # once
npx playwright install chromium   # once
npm run screenshots
```

The script drives Playwright's own Chromium rather than an installed Chrome, so
the renderer is pinned to the Playwright version in `package.json` and does not
drift when a browser auto-updates. `npm` is only ever needed for this — the
shipped plugin is PHP only, and `.distignore` keeps `bin/`, `package.json` and
`node_modules` out of the package.

It logs in by minting an auth cookie through wp-cli, so it never needs anyone's
password. It seeds fictional blocked submissions for the log viewer and applies
a few settings so the shots show features in use, then deletes exactly those
rows and restores those settings. Finally it compares a fingerprint of the
plugin's options, transients and log rows with one taken before the run, and
fails if anything differs.

The Per-form shot shows the WP Job Manager and Contact Form 7 sections, so both
plugins must be active on the site generating the shots; the script fails
rather than publish a shot without them.

If wp-cli on your machine needs extra arguments to reach the database, pass them
through:

```sh
OSG_WP_ARGS="--require=/tmp/dbhost.php" npm run screenshots
```

Other variables: `OSG_URL` (default `http://vchs-test.local`), `OSG_OUT`,
`OSG_TIMEOUT`.

**Numbering is load-bearing.** `screenshot-N.png` pairs with the Nth line of the
`== Screenshots ==` block in `readme.txt`. A gap or a mismatch fails silently on
WordPress.org — captions attach to the wrong image. The `SHOTS` array in
`bin/screenshots.mjs` is the source of that order; keep the two in step.

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
   and its siblings are called by other plugins — ProducerKit, for one, uses
   `simple_spam_shield_check()` and `simple_spam_shield_field_markup()` in its
   forms. Renaming them is a breaking change for consumers.
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
   `## [x.y.z] - YYYY-MM-DD` heading in `CHANGELOG.md` (dated the day you tag,
   with a compare link at the bottom), and a matching `= x.y.z =` section in the
   `readme.txt` changelog plus an `== Upgrade Notice ==` entry of at most 300
   characters.
2. **Regenerate the translation template** so its header carries the new
   version:
   ```bash
   wp i18n make-pot . languages/onsite-spam-guard.pot --slug=onsite-spam-guard --exclude=build
   ```
3. **Regenerate the screenshots** if the admin screens changed
   (`npm run screenshots`, see above), and look at every image before
   committing. Update the `== Screenshots ==` captions to match.
4. **Check consistency** (CI runs this on every push, and the release workflow
   runs it against the tag):
   ```bash
   composer check-versions
   ```
5. **Tag and push.** That is the only manual publish step:
   ```bash
   git tag -a v1.2.0 -m "Release 1.2.0" && git push origin v1.2.0
   ```

   `.github/workflows/release.yml` then validates the tag against the plugin
   version, re-runs lint and tests, builds the package, commits it to SVN
   `trunk/` and `tags/<version>/`, syncs `.wordpress-org/` to the SVN `assets/`
   directory, and attaches an installable zip to the GitHub Release.
6. **Confirm it is live.** The WordPress.org API should report the new version
   and its download should exist:
   ```bash
   curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=onsite-spam-guard" | grep -o '"version":"[^"]*"'
   curl -sI https://downloads.wordpress.org/plugin/onsite-spam-guard.1.2.0.zip | head -1
   ```
   This usually takes a few minutes after the deploy. 1.6.0 took about two and a
   half hours, during a slowdown affecting every plugin in the directory. If the
   SVN tag is there, wait rather than re-tagging.

The SVN deploy step runs only when the `SVN_USERNAME` and `SVN_PASSWORD`
repository secrets are set; without them a tag still builds the package and
attaches it to the GitHub Release.

**`Stable tag` is the release switch.** WordPress.org serves whatever
`tags/<Stable tag>/` contains, so tagging code without bumping `Stable tag`
silently keeps users on the old version. That is exactly what
`bin/check-versions.sh` exists to prevent.

## Reporting security issues

Please do not open public issues for security vulnerabilities. Report them
privately to the maintainer so a fix can be prepared before disclosure.
