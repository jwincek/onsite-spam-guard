# Onsite Spam Guard

Config-driven spam prevention for WordPress Comments, WooCommerce Product Reviews, and Jetpack Contact Form blocks — no external services, no API keys, no CAPTCHA.

## Architecture

This plugin follows a **config-driven, layered architecture**, pioneered by [shelter-pet-sync](https://github.com/jwincek/shelter-pet-sync), adapted for spam prevention and extended:

```
config/                  → JSON definitions (guard rules, default settings)
includes/core/           → Infrastructure (Config loader, Guard Runner, Database Manager, Assets, Admin)
includes/guards/         → Individual spam checks (analogous to "abilities")
includes/integrations/   → Thin hooks into WP Comments, WooCommerce, Jetpack
admin/                   → WP_List_Table for the spam logs admin page
assets/css/              → Honeypot field styling
assets/js/               → Front-end guard injection + behavioral signals
uninstall.php            → Clean deletion of all plugin data
```

**Guards** are the equivalent of the reference plugin's **abilities** — thin, testable operations with clear inputs/outputs. Each guard implements `Guard_Interface` and is registered from `config/guards.json`. The `Guard_Runner` loads them, sorts by weight, and runs them as a pipeline. The first failure short-circuits and blocks the submission.

**Integrations** are thin consumers that hook into WordPress, WooCommerce, and Jetpack lifecycle events, normalize the incoming data into a common format, and delegate all spam-checking to the shared guard pipeline.

## Requirements

- WordPress 6.2+
- PHP 8.2+
- Optional: WooCommerce (for review protection)
- Optional: Jetpack (for contact form protection)

## Installation

1. Download or clone into `wp-content/plugins/onsite-spam-guard/`.
2. Activate in **Plugins → Installed Plugins**.
3. Configure at **Spam Guard → Settings**.
4. View blocked submissions at **Spam Guard → Spam Logs**.

## Spam Guards

| Guard | Weight | Default | Description |
|---|---|---|---|
| **Honeypot** | 100 | On | Hidden field that bots fill in but humans never see |
| **Duplicate detection** | 95 | On | Rejects identical submissions within a 60-second window using transient-based hashing |
| **Time gate** | 90 | On | Rejects submissions completed faster than a human could type (configurable, default 3s), using a server-signed issue time |
| **Rate limit** | 85 | Off | Throttles repeated submissions from the same sender within a rolling window; keyed on the logged-in user ID when present, the connection IP otherwise |
| **Signature** | 80 | On | Requires a valid server-signed token (HMAC) proving the form was served by this site; does not expire, so it is cache-safe |
| **Link limit** | 70 | On | Flags submissions containing too many URLs (configurable, default 3) |
| **Keyword block** | 60 | On | Rejects submissions matching blocked keywords or phrases |
| **Behavioral analysis** | 55 | Off | Scores mouse movements, clicks, and time-on-page to detect bot-like interaction patterns |

Guards run in descending weight order. All can be toggled individually from the admin settings page. Definitions (weights, defaults, thresholds) live in `config/guards.json`.

## How It Works

### Front-end injection

`guard.js` automatically finds comment forms, WooCommerce review forms, and Jetpack contact form blocks on the page via CSS selectors. It injects hidden fields for the honeypot, the signed form token, and behavioral data into each form. A `MutationObserver` (debounced) catches dynamically-loaded forms (e.g. AJAX-loaded WooCommerce reviews). The token is an HMAC-signed `<issued_at>.<signature>` string minted server-side (`includes/core/class-token.php`); the time gate reads the signed issue time and the signature guard verifies authenticity. Behavioral data (mouse movement count, click count, time on page) is collected continuously and serialized into a JSON hidden field at submit time.

### Server-side pipeline

When a form is submitted, the relevant integration class (Comments, WooCommerce, or Jetpack) normalizes the data and passes it to `Guard_Runner::run()`. The runner checks the allowlist first — if the submitter's IP or email matches, all guards are bypassed. Otherwise, each enabled guard runs in weight order until one fails or all pass.

### Two-phase Jetpack integration

Jetpack contact forms require special handling because Jetpack's form processor strips unrecognized POST fields before our spam filter fires. The integration solves this with a two-phase pipeline:

- **Phase 1** (`template_redirect`, priority 1) — Fires before Jetpack's `process_form_submission` (priority 10). At this point `$_POST` still contains our JS-injected fields. The integration runs the JS-dependent guards (honeypot, signature, time gate, behavioral) against raw POST data. If any fail, a rejection flag is stored on the class — no `wp_die()`, no short-circuit.

- **Phase 2** (`jetpack_contact_form_is_spam` filter) — Fires during Jetpack's own processing. If Phase 1 flagged the submission, this filter returns `true` immediately and Jetpack handles the rejection through its native UX. If Phase 1 passed, the content-based guards (keyword block, link limit, duplicate detection) run against Jetpack's structured `$form_data`. The JS-dependent guards skip automatically in Phase 2 via context-aware logic, avoiding duplicate checks.

This design gives full guard coverage on Jetpack forms while Jetpack stays in complete control of the submission lifecycle and rejection UX.

### Allowlist

Submissions from allowlisted IPs or emails bypass all guards entirely. The allowlist supports exact IPs, CIDR ranges (e.g. `10.0.0.0/8`), exact email addresses, and email domain patterns (e.g. `@trusted.org`). IP detection uses the direct connection IP (`REMOTE_ADDR`) by default; the spoofable `X-Forwarded-For` header is honored only when the **Trust proxy headers** option is enabled (for sites behind a trusted reverse proxy), so a visitor cannot forge a header to spoof an allowlisted IP.

### Sharing WordPress's blocklist

The Keyword block guard checks its own list of words and phrases. It can optionally also run each
submission through **`wp_check_comment_disallowed_list()`** — WordPress's own *Disallowed Comment
Keys* from Settings → Discussion — via the *Also apply WordPress's Disallowed Comment Keys* option
on the Guards tab (off by default).

Core only applies that list to comments. Running it through the guard pipeline extends the same
single, already-maintained blocklist to WooCommerce reviews, Jetpack contact forms, and any form
integrated through the public API. Jetpack Forms uses the same core function for its own
submissions; this applies it more broadly, and without the Akismet dependency Jetpack pairs it with.

### Logging

Blocked submissions are logged to a custom database table (`wp_simple_spam_shield_spam_logs`) with guard name, context, reason, content excerpt, IP, and user agent. The **Spam Guard → Spam Logs** admin page provides a paginated, sortable `WP_List_Table` that can be filtered by guard and by context, shows a user-agent column, and offers individual and bulk delete. A cached 7-day summary ("blocked / most active guard") sits above the list. Logging can be disabled from the settings page, and a configurable retention window (default 30 days) prunes old rows daily via WP-Cron.

### Settings

Settings live under **Spam Guard → Settings**, organized into tabs — General, Guards, Allowlist, and Logging — rendered as a single form so one Save persists everything. It degrades gracefully: without JavaScript the tab bar is hidden and every section is shown.

### Clean uninstall

When the plugin is deleted (not just deactivated), `uninstall.php` drops the custom table, removes all `simple_spam_shield_*` options, clears the scheduled purge, and purges transients — on **every site of a multisite network**. This is gated by the **Delete all plugin data when this plugin is deleted** setting (on by default), so you can keep your settings and logs across a reinstall.

## Protecting another plugin's forms

Other plugins can run their own form submissions through Onsite Spam Guard's guards via a small, stable public API (`includes/api.php`). Integrate through these prefixed functions — not the internal classes — and wrap calls in `function_exists()` so your plugin degrades gracefully when Onsite Spam Guard is inactive.

**1. Check a submission (server side).** Pass the human-meaningful fields; the hidden honeypot/token/behavioral fields are read from `$_POST` automatically. Returns `true` or a `WP_Error`.

```php
if ( function_exists( 'simple_spam_shield_check' ) ) {
    $result = simple_spam_shield_check(
        array( 'content' => $message, 'author' => $name, 'email' => $email ),
        'acme_contact_form' // a label for your form; shows up in the spam log
    );
    if ( is_wp_error( $result ) ) {
        // Reject, mark as spam, etc.
        wp_die( esc_html( $result->get_error_message() ) );
    }
}
```

For a **REST or AJAX endpoint with a JSON body**, `$_POST` is empty, so pass the hidden fields explicitly from the request:

```php
simple_spam_shield_check( array(
    'content'                        => $request->get_param( 'message' ),
    'author'                         => $request->get_param( 'name' ),
    'email'                          => $request->get_param( 'email' ),
    'simple_spam_shield_website_url' => $request->get_param( 'simple_spam_shield_website_url' ),
    'simple_spam_shield_form_loaded' => $request->get_param( 'simple_spam_shield_form_loaded' ),
), 'acme_rest_form' );
```

The time-gate and signature guards skip (rather than block) when no token is supplied for a custom context, so a **content-only** integration that passes just `content`/`author`/`email` still works — it gets the keyword, link-limit, and duplicate guards without any front-end token plumbing.

**2. Add the hidden fields to your form** so the JS-dependent guards (honeypot, time gate, signature, behavioral) have data. Either register your form's selector — the front-end script then injects the fields for you:

```php
add_action( 'wp_enqueue_scripts', function () {
    if ( function_exists( 'simple_spam_shield_protect_selector' ) ) {
        simple_spam_shield_protect_selector( '#acme-contact-form' );
    }
} );
```

…or render the fields inline in your form template:

```php
if ( function_exists( 'simple_spam_shield_field_markup' ) ) {
    echo simple_spam_shield_field_markup(); // already escaped
}
```

Without step 2, only the content-based guards (keyword, link limit, duplicate) apply.

### Adding your own guard

Register a class implementing `Guard_Interface` through the
`simple_spam_shield_guards` filter, and it joins the pipeline as a first-class
guard — sorted by weight, short-circuiting on failure, logged, and given its own
on/off toggle on the Guards settings tab automatically.

```php
add_filter( 'simple_spam_shield_guards', function ( array $guards ) {
    $guards['acme_blocklist'] = [
        'class'              => \Acme\Blocklist_Guard::class,
        'label'              => 'Acme blocklist',
        'weight'             => 65,   // higher runs first
        'enabled_by_default' => true,
    ];
    return $guards;
} );
```

Register it when your plugin file loads, so the filter is in place before the
pipeline is built on `plugins_loaded`.

Your guard is evaluated even when an earlier guard has already blocked, so the
log can record every guard that matched. `check()` must therefore return the
same verdict whatever the outcome. Anything you want to remember about a
submission goes in `commit()`, which the runner calls only once no guard has
objected:

```php
public function check( array $data, string $context ): \WP_Error|true {
    // Evaluate only. Called whatever the outcome, so no side effects that
    // assume the submission was accepted.
}

public function commit( array $data, string $context ): void {
    // The submission cleared every guard. Record it here.
}
```

`Abstract_Guard::commit()` is an empty default, so a guard holding no state can
simply omit it.

The distinction is the difference between two questions. `Duplicate` asks "have
I already taken this exact content?", so it records on commit — a submission
some other guard rejected was never taken, and recording it would refuse the
visitor who fixed the problem and resubmitted. `Rate_Limit` asks "is this sender
hammering the form?", so it counts inside `check()`, including rejected
attempts: a flooding sender is overwhelmingly being rejected by some other
guard, and counting only what got through would let a bot flood forever while
still charging a legitimate visitor for one mistake.

The same filter can adjust or remove a built-in guard by editing or unsetting
its entry.

### Reacting to a block

```php
add_action( 'simple_spam_shield_blocked', function ( $guard, $context, $matched, $data ) {
    // $guard   — the guard that decided the block
    // $matched — every guard that matched, in weight order ($matched[0] === $guard)
    // $context — 'comment', 'woo_review', 'jetpack_form', or your own label
}, 10, 4 );
```

`$data` carries the submitted content, author name and email, so treat it as
personal data.

## Lineage

The plugin's architecture draws from two sources:

- **[shelter-pet-sync](https://github.com/jwincek/shelter-pet-sync)** — The config-driven, layered structure: `config/` JSON definitions, `includes/core/` infrastructure, namespaced autoloader, activation/deactivation hooks, and the guard-as-ability pattern.

- **Comment & Form Guard** — Five features were ported and adapted: duplicate submission detection (transient-based hashing), behavioral analysis (mouse/click/time scoring), the allowlist system (IP, CIDR, email, domain matching with proxy-aware IP detection), database-backed logging with `WP_List_Table`, and `uninstall.php` for clean plugin deletion.

### Improvements over both

- **Guard pipeline with weighted priority and short-circuit** — Guards run as an ordered pipeline rather than being registered individually or checked with sequential if/else blocks.
- **Normalized data layer** — Each integration normalizes its form data into a common format so guards never need to know about WP comment arrays, WooCommerce review data, or Jetpack field structures.
- **Two-phase Jetpack processing** — Solves the field-stripping problem without `wp_die()` or undocumented hooks, keeping Jetpack in control of the UX.
- **Server-signed form token** — A single HMAC-signed `<issued_at>.<signature>` token drives both the time gate (tamper-proof issue time) and the signature guard (proof the form came from this site). Because the HMAC does not expire, it is safe under full-page caching — where a WordPress nonce would go stale and block legitimate visitors.
- **No jQuery dependency** — The front-end script uses vanilla JS with `MutationObserver` for dynamic form detection.
- **PHP 8.2+ with strict types** — Union return types (including `true`), `str_starts_with`/`str_contains`, and `match` expressions throughout.

## Linting

```bash
composer install
composer lint        # Check
composer lint:fix    # Auto-fix
```

## License

GPL-2.0-or-later.
