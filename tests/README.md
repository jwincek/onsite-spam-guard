# Tests

Fast unit and integration tests for Onsite Spam Guard. They run **without a
WordPress install or a database** — `bootstrap.php` stubs the handful of WP
functions the code touches and reuses the plugin's own autoloader.

## Running

```bash
composer test            # all tests
vendor/bin/phpunit --filter TokenTest   # a single test class
```

CI runs the same suite on PHP 8.2, 8.3, and 8.4 (`.github/workflows/ci.yml`).

## Layout

| File | Covers |
| --- | --- |
| `TokenTest` | HMAC token signing round-trip and rejection of tampered/forged/empty tokens |
| `RequestTest` | visitor-IP resolution and the trusted-proxy rule |
| `HoneypotTest`, `TimeGateTest`, `NonceTest`, `LinkLimitTest`, `KeywordBlockTest`, `DuplicateTest`, `BehavioralTest` | one file per guard — blocking and passing paths |
| `DatabaseManagerTest` | the prepared filter-clause builder (`build_filter`) |
| `CommentsIntegrationTest` | a comment driven through the real `Guard_Runner` pipeline and routed to the spam queue |
| `AbilitiesApiTest` | the read-only abilities: log rows and stats mapped to the public output, no personal data, input clamping, the permission check |

## Smoke test on a real WordPress

The unit tests cannot show the plugin working on any particular WordPress, so
`smoke/run.sh` installs the built package into a real one and checks it there:
an upgrade from the previous release by replacing its files (no reactivation,
no `admin_init`, as an automatic update runs), uninstall, fresh activation, the
admin screens, the guard pipeline, the abilities, every integration with its
host plugin absent, and a debug log free of errors from the plugin.
`smoke/checks.php` holds the assertions for each stage.

CI's `wp-smoke` job runs it at both ends of the claimed range: the declared
minimum (WordPress 6.2 on PHP 8.2), where the Abilities API does not exist and
the stage asserts nothing registers or fails; and `Tested up to` (WordPress 7.1
on PHP 8.4), where both abilities must register, refuse visitors, pass core's
output-schema validation and carry no personal data.
To run it yourself you need a throwaway WordPress with `WP_DEBUG` and
`WP_DEBUG_LOG` on — **it uninstalls the plugin, dropping its table and
options**:

```bash
bin/build-dist.sh build
tests/smoke/run.sh /path/to/wordpress build/onsite-spam-guard
```

`WP_CLI=/path/wp-cli.phar` selects a WP-CLI other than `wp`, `DEBUG_LOG` points
at a log outside `wp-content/`, and `UPGRADE_FROM` changes the release stage 1
upgrades from. Keep that default at the last release before the newest schema
migration, so the upgrade stage exercises it.

## How the stubs work

`bootstrap.php` keeps in-memory stores that tests read and write directly:

- `$GLOBALS['simple_spam_shield_test_options']` — backs `get_option()` / `update_option()`.
- `$GLOBALS['simple_spam_shield_test_transients']` — backs the transient functions.
- `$GLOBALS['simple_spam_shield_test_caps']` — backs `current_user_can()`.

`wp_die()` is stubbed to **throw** (so the hard-block path can be asserted),
and `WP_Error` is a minimal stand-in. Set state in `setUp()`, e.g.:

```php
protected function setUp(): void {
    $GLOBALS['simple_spam_shield_test_options'] = [
        'simple_spam_shield_token_secret' => str_repeat( 'k', 64 ),
    ];
}
```

## Adding a test for a new guard

Guards are plain objects — construct one with its slug and config array, set
any options it reads, then assert the return value:

```php
$guard  = new My_Guard( 'my_guard', [ 'threshold' => 5 ] );
$result = $guard->check( [ 'content' => '…' ], 'comment' );

$this->assertTrue( $result );                       // passed
$this->assertInstanceOf( WP_Error::class, $result ); // blocked
```

Cover the blocking path, the passing path, and — if the guard depends on a
JS-injected field — the `'jetpack_form'` context, where it should skip
rather than hard-fail.

If the tested class calls a WP function not yet stubbed, add a guarded
`if ( ! function_exists( … ) )` stub to `bootstrap.php`.

This directory is excluded from the distributed plugin (see `.distignore`).
