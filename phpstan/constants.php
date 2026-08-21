<?php
/**
 * Plugin constants for static analysis only.
 *
 * They are defined at runtime in onsite-spam-guard.php, but PHPStan cannot
 * rely on that file's top-level `define()` calls because the direct-access
 * guard above them may terminate execution. This file is never shipped — it
 * exists solely as a PHPStan bootstrap.
 *
 * @package Simple_Spam_Shield
 */

define( 'SIMPLE_SPAM_SHIELD_VERSION', '0.0.0' );
define( 'SIMPLE_SPAM_SHIELD_FILE', __FILE__ );
define( 'SIMPLE_SPAM_SHIELD_DIR', dirname( __DIR__ ) . '/' );
define( 'SIMPLE_SPAM_SHIELD_URL', 'https://example.org/wp-content/plugins/onsite-spam-guard/' );
