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

| Area | Files |
| --- | --- |
| The pipeline | `EvaluateAllGuardsTest` — every enabled guard is evaluated, the highest-weight objection is the verdict, and `commit()` runs only on acceptance · `GuardRegistrationTest` — guards registered by other plugins through `simple_spam_shield_guards`, and malformed ones refused · `MonitorModeTest` — monitor mode site-wide and per form · `MonitorReviewTest` — the review of a monitored form and "Enforce this form" |
| Guards, one file each | `HoneypotTest`, `HoneypotFieldNameTest` (the per-site field name), `TimeGateTest`, `NonceTest` (the signature guard), `LinkLimitTest`, `KeywordBlockTest`, `DuplicateTest`, `RateLimitTest`, `BehavioralTest` — blocking and passing paths · `ConfigurableWindowsTest` — the duplicate and rate-limit windows |
| Per-form settings | `PerContextConfigTest` — the contexts registry and per-form overrides · `ContextDefaultsTest` — thresholds a context brings with it, and where they sit in the chain |
| Integrations | `CommentsIntegrationTest` — a comment through the real pipeline to the spam queue · `JetpackFormsTest` · `ContactForm7IntegrationTest` · `JobManagerIntegrationTest` · `BuddyPressMessagesTest` · `RegistrationTest` (WordPress, WooCommerce and BuddyPress signup) · `AbilitiesApiTest` — the read-only abilities, including that no personal data leaves |
| Core pieces | `TokenTest` — the signed form token · `RequestTest` — visitor-IP resolution behind trusted proxies · `ProxyDiagnosticsTest` · `AllowlistTest` · `DatabaseManagerTest` — the prepared filter-clause builder · `ApiTest` — the public functions in `includes/api.php` · `SettingsLinkTest` |

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

`bootstrap.php` defines the WordPress functions the code touches, each guarded by
`function_exists()`, backed by in-memory stores that tests read and write
directly. Reset the ones a test uses in `setUp()`:

| Global (`$GLOBALS['simple_spam_shield_test_…']`) | Backs |
| --- | --- |
| `options` | `get_option()`, `update_option()`, `add_option()`, `delete_option()` |
| `transients`, `transient_expirations` | the transient functions; the expiration each `set_transient()` asked for |
| `caps` | `current_user_can()` for the current user |
| `user_caps`, `users`, `user_id` | `user_can()`, `get_userdata()`, `get_current_user_id()` |
| `filters`, `actions` | `add_filter()` / `apply_filters()` and `add_action()` / `do_action()`: callbacks are recorded and run |
| `doing_it_wrong` | each `_doing_it_wrong()` call, so a test can assert one was raised |
| `log_rows` | rows passed to `$wpdb->insert()` |
| `queries`, `results`, `var` | `$wpdb->prepare()` records each query with its arguments; `get_results()` and `get_var()` return whatever a test put in `results` and `var` |

`wp_die()` is stubbed to **throw** a `RuntimeException`, so the hard-block path
can be asserted. `WP_Error` mirrors core's interface (`add()`, `has_errors()`,
`get_error_codes()`, `get_error_messages()`, `get_error_data()` …), so code that
builds on an existing error behaves as it does in WordPress. For example:

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
