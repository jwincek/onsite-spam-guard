<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Config;
use Simple_Spam_Shield\Core\Guard_Runner;
use Simple_Spam_Shield\Integrations\BuddyPress_Messages;

/** Stand-in for BP_Messages_Message: the properties the integration reads. */
final class Fake_BP_Message {
	public int    $sender_id;
	public string $subject;
	public string $message;
	public array  $recipients;

	public function __construct( int $sender_id, string $subject, string $message, array $recipients = [ 'r1' ] ) {
		$this->sender_id  = $sender_id;
		$this->subject    = $subject;
		$this->message    = $message;
		$this->recipients = $recipients;
	}
}

/** BuddyPress private messages (#7). */
final class BuddyPressMessagesTest extends TestCase {

	protected function setUp(): void {
		Config::init( SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/config/' );
		$GLOBALS['simple_spam_shield_test_options']               = [
			'simple_spam_shield_enabled'     => true,
			'simple_spam_shield_log_blocked' => false,
		];
		$GLOBALS['simple_spam_shield_test_transients']            = [];
		$GLOBALS['simple_spam_shield_test_transient_expirations'] = [];
		$GLOBALS['simple_spam_shield_test_filters']               = [];
		$GLOBALS['simple_spam_shield_test_actions']               = [];
		$GLOBALS['simple_spam_shield_test_log_rows']              = [];
		$GLOBALS['simple_spam_shield_test_users']                 = [
			7 => [ 'ID' => 7, 'display_name' => 'Ada Sender', 'user_email' => 'ada@example.com' ],
			9 => [ 'ID' => 9, 'display_name' => 'Mod', 'user_email' => 'mod@example.com' ],
		];
		$GLOBALS['simple_spam_shield_test_user_caps']             = [ 9 => [ 'moderate_comments' ] ];
		$_SERVER['REMOTE_ADDR']                                   = '198.51.100.60';

		Guard_Runner::init();
	}

	// --- the abort mechanism ----------------------------------------------

	/**
	 * BuddyPress has no pre-save hook returning an error, but send() bails on
	 * empty recipients immediately after this hook and before the INSERT.
	 * Clearing them is the abort.
	 */
	public function test_a_blocked_message_has_its_recipients_cleared(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_keyword_block_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords']      = 'casino';

		$message = new Fake_BP_Message( 7, 'hello', 'try my casino link' );
		BuddyPress_Messages::check( $message );

		$this->assertSame( [], $message->recipients );
	}

	public function test_a_clean_message_keeps_its_recipients(): void {
		$message = new Fake_BP_Message( 7, 'Coffee?', 'Are you free on Thursday?' );
		BuddyPress_Messages::check( $message );

		$this->assertSame( [ 'r1' ], $message->recipients );
	}

	/** A message with no recipients is already going nowhere. */
	public function test_an_empty_recipient_list_is_left_alone(): void {
		$message = new Fake_BP_Message( 7, 'x', 'x', [] );
		BuddyPress_Messages::check( $message );

		$this->assertSame( [], $message->recipients );
	}

	public function test_a_non_object_does_not_fatal(): void {
		BuddyPress_Messages::check( null );
		BuddyPress_Messages::check( 'not a message' );

		$this->expectNotToPerformAssertions();
	}

	// --- who is screened ---------------------------------------------------

	/** Locking a moderator out of the inbox is worse than the spam. */
	public function test_a_moderator_is_never_throttled(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_keyword_block_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords']      = 'casino';

		$message = new Fake_BP_Message( 9, 'notice', 'casino' );
		BuddyPress_Messages::check( $message );

		$this->assertSame( [ 'r1' ], $message->recipients );
	}

	/** The subject is screened too, not only the body. */
	public function test_the_subject_is_screened(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_keyword_block_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords']      = 'casino';

		$message = new Fake_BP_Message( 7, 'Cheap casino deals', 'hello' );
		BuddyPress_Messages::check( $message );

		$this->assertSame( [], $message->recipients );
	}

	/** Rate and duplicate are the controls that fit authenticated messaging. */
	public function test_a_repeated_identical_message_is_caught_as_a_duplicate(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_duplicate_enabled'] = true;

		$first = new Fake_BP_Message( 7, 'hi', 'same text' );
		BuddyPress_Messages::check( $first );
		$this->assertSame( [ 'r1' ], $first->recipients, 'the first send must go through' );

		$second = new Fake_BP_Message( 7, 'hi', 'same text' );
		BuddyPress_Messages::check( $second );
		$this->assertSame( [], $second->recipients );
	}

	// --- privacy -----------------------------------------------------------

	/**
	 * Message bodies must never reach the spam log. The guards still run on the
	 * real content; only what is persisted changes.
	 */
	public function test_message_content_is_redacted_from_the_log(): void {
		$this->assertSame( '', BuddyPress_Messages::redact_log_content( 'a private message', 'bp_message' ) );
	}

	public function test_other_contexts_are_not_redacted(): void {
		$this->assertSame(
			'a public comment',
			BuddyPress_Messages::redact_log_content( 'a public comment', 'comment' )
		);
	}

	/** The redaction is wired through the runner, not only available. */
	public function test_the_runner_honours_the_log_content_filter(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_log_blocked']           = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_keyword_block_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords']      = 'casino';

		$logged = null;
		add_filter(
			'simple_spam_shield_log_content',
			static function ( $content, $context = '' ) use ( &$logged ) {
				$logged = [ 'content' => $content, 'context' => $context ];
				return '';
			},
			10,
			2
		);

		Guard_Runner::run( [ 'content' => 'visit my casino' ], 'bp_message' );

		$this->assertSame( 'visit my casino', $logged['content'], 'the filter receives the real content' );
		$this->assertSame( 'bp_message', $logged['context'] );

		// And the row that would have been written carries none of it.
		$rows = $GLOBALS['simple_spam_shield_test_log_rows'];
		$this->assertCount( 1, $rows, 'the block is still recorded' );
		$this->assertSame( '', $rows[0]['content'], 'the message body must not be persisted' );
		$this->assertSame( 'bp_message', $rows[0]['context'] );
		$this->assertSame( 'keyword_block', $rows[0]['guard'], 'which guard fired is still recorded' );
	}

	/** Without the filter, content is logged — so the redaction is doing the work. */
	public function test_content_is_logged_for_an_ordinary_context(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_log_blocked']           = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_keyword_block_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords']      = 'casino';

		Guard_Runner::run( [ 'content' => 'visit my casino' ], 'comment' );

		$rows = $GLOBALS['simple_spam_shield_test_log_rows'];
		$this->assertSame( 'visit my casino', $rows[0]['content'] );
	}

	public function test_the_context_is_registered(): void {
		$this->assertArrayHasKey( 'bp_message', BuddyPress_Messages::register_context( [] ) );
	}
}
