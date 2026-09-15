<?php
/**
 * BuddyPress integration — protects private messages.
 *
 * BuddyPress applies WordPress's disallowed-keys and moderation lists to the
 * activity stream but applies no content moderation at all to private
 * messages. That is a defensible product decision rather than an oversight — a
 * public blocklist over private correspondence is a threat-model mismatch — but
 * it does leave message spam entirely unaddressed.
 *
 * The controls that fit authenticated messaging are rate and duplicate, keyed
 * on the sender, which is what the rate-limit guard was built for: it keys on
 * the logged-in user ID when there is one.
 *
 * Message bodies are never written to the spam log. A block records who, when,
 * and which guard — everything needed to act on it — without accumulating
 * private correspondence in a table the administrator browses.
 *
 * @package Simple_Spam_Shield
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Integrations;

use Simple_Spam_Shield\Core\Guard_Runner;

final class BuddyPress_Messages {

	/**
	 * Submission context, also the slug of its per-form settings section.
	 */
	public const CONTEXT = 'bp_message';

	/**
	 * Register hooks — only when BuddyPress messages are available.
	 */
	public static function init(): void {
		// The messages component can be switched off independently of
		// BuddyPress itself, and there is nothing to protect when it is.
		if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'messages' ) ) {
			return;
		}

		add_filter( 'simple_spam_shield_contexts', [ __CLASS__, 'register_context' ] );

		// Never log a private message body, whether or not protection is on —
		// another integration could use this context, and the guarantee should
		// not depend on a toggle.
		add_filter( 'simple_spam_shield_log_content', [ __CLASS__, 'redact_log_content' ], 10, 2 );

		if ( ! (bool) get_option( 'simple_spam_shield_protect_bp_messages', false ) ) {
			return;
		}

		// Hooked late so anything rewriting the message content has already run.
		add_action( 'messages_message_before_save', [ __CLASS__, 'check' ], 99 );
	}

	/**
	 * Offer private messages their own thresholds on the Per-form tab.
	 *
	 * @param array<string, array{label: string}> $contexts Registered contexts.
	 * @return array<string, array{label: string}>
	 */
	public static function register_context( array $contexts ): array {
		$contexts[ self::CONTEXT ] = [
			'label' => __( 'BuddyPress private messages', 'onsite-spam-guard' ),
		];

		return $contexts;
	}

	/**
	 * Keep private message bodies out of the spam log.
	 *
	 * The guards still run on the real message; only what is persisted changes.
	 *
	 * @param string $content The content about to be logged.
	 * @param string $context Submission context.
	 * @return string
	 */
	public static function redact_log_content( $content, $context = '' ): string {
		return self::CONTEXT === $context ? '' : (string) $content;
	}

	/**
	 * Screen a message before it is stored.
	 *
	 * BuddyPress has no pre-save hook that can return an error, but this one
	 * passes the message by reference and `BP_Messages_Message::send()` bails
	 * on empty recipients immediately afterwards, before any row is written.
	 * Clearing them is therefore a clean abort: `messages_new_message()` turns
	 * it into the generic "Message was not sent. Please try again.", which is
	 * the right thing to show a spammer — it names nothing they can work around.
	 *
	 * Hooking the model rather than the transport means this covers every send
	 * path: the REST endpoint, both AJAX template packs, the compose and view
	 * screens, and WP-CLI.
	 *
	 * @param mixed $message The BP_Messages_Message being saved, by reference.
	 *                       Typed loosely on purpose: it arrives from another
	 *                       plugin's action, and this must not fatal if that
	 *                       ever changes.
	 */
	public static function check( $message ): void {
		if ( ! is_object( $message ) || empty( $message->recipients ) ) {
			return;
		}

		$sender_id = (int) ( $message->sender_id ?? 0 );

		// Never throttle a moderator: they send legitimate bulk correspondence,
		// and locking one out of the inbox is worse than the spam.
		if ( $sender_id > 0 && user_can( $sender_id, 'moderate_comments' ) ) {
			return;
		}

		$sender = $sender_id > 0 ? get_userdata( $sender_id ) : false;

		$data = [
			'content' => trim(
				(string) ( $message->subject ?? '' ) . "\n\n" . (string) ( $message->message ?? '' )
			),
			'author'  => $sender ? (string) $sender->display_name : '',
			'email'   => $sender ? (string) $sender->user_email : '',
		];

		if ( ! is_wp_error( Guard_Runner::run( $data, self::CONTEXT ) ) ) {
			return;
		}

		// Abort the send. send() returns false at its recipients check on the
		// next line, before the INSERT.
		$message->recipients = [];
	}
}
