<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Config;
use Simple_Spam_Shield\Core\Guard_Runner;
use Simple_Spam_Shield\Guards\Abstract_Guard;

/** A guard supplied by a hypothetical third-party plugin. */
final class Test_External_Guard extends Abstract_Guard {
	public static int $observed_calls = 0;

	public function check( array $data, string $context, bool $observe_only = false ): \WP_Error|true {
		if ( $observe_only ) {
			++self::$observed_calls;
		}
		if ( str_contains( (string) ( $data['content'] ?? '' ), 'forbidden-by-external' ) ) {
			return $this->fail( 'Blocked by the external guard.' );
		}
		return true;
	}
}

/** Does not implement Guard_Interface — must be refused rather than fataling. */
final class Test_Not_A_Guard {}

final class GuardRegistrationTest extends TestCase {

	private const SECRET = 'gggggggggggggggggggggggggggggggggggggggggggggggggggggggggggggggg';

	protected function setUp(): void {
		Config::init( SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/config/' );
		$GLOBALS['simple_spam_shield_test_options']    = [
			'simple_spam_shield_enabled'      => true,
			'simple_spam_shield_log_blocked'  => false,
			'simple_spam_shield_token_secret' => self::SECRET,
		];
		$GLOBALS['simple_spam_shield_test_transients'] = [];
		$GLOBALS['simple_spam_shield_test_filters']    = [];
		$GLOBALS['simple_spam_shield_test_actions']    = [];
		$_SERVER['REMOTE_ADDR']                        = '198.51.100.9';
		$_POST                                         = [];
		Test_External_Guard::$observed_calls           = 0;
	}

	private function token(): string {
		$issued = time() - 30;
		return $issued . '.' . hash_hmac( 'sha256', (string) $issued, self::SECRET );
	}

	private function registerExternalGuard( int $weight = 50 ): void {
		add_filter(
			'simple_spam_shield_guards',
			static function ( array $defs ) use ( $weight ): array {
				$defs['external'] = [
					'class'              => Test_External_Guard::class,
					'label'              => 'External guard',
					'weight'             => $weight,
					'enabled_by_default' => true,
				];
				return $defs;
			}
		);
		Guard_Runner::init();
	}

	public function test_builtin_guards_are_registered_without_any_filter(): void {
		Guard_Runner::init();
		$defs = Guard_Runner::definitions();

		$this->assertArrayHasKey( 'honeypot', $defs );
		$this->assertSame( 8, count( $defs ) );
		// Every built-in carries the class that implements it.
		foreach ( $defs as $slug => $def ) {
			$this->assertArrayHasKey( 'class', $def, "guard {$slug} has no class" );
		}
	}

	public function test_a_registered_guard_participates_in_the_pipeline(): void {
		$this->registerExternalGuard();

		$result = Guard_Runner::run(
			[
				'content'                        => 'this is forbidden-by-external content',
				'simple_spam_shield_website_url' => '',
				'simple_spam_shield_form_loaded' => $this->token(),
			],
			'comment'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'simple_spam_shield_external_failed', $result->get_error_code() );
	}

	public function test_registered_guard_respects_its_weight(): void {
		// Above the honeypot (100), so it decides even when the honeypot also matches.
		$this->registerExternalGuard( 120 );

		$result = Guard_Runner::run(
			[
				'content'                        => 'forbidden-by-external',
				'simple_spam_shield_website_url' => 'http://bot.example',
				'simple_spam_shield_form_loaded' => $this->token(),
			],
			'comment'
		);

		$this->assertSame( 'simple_spam_shield_external_failed', $result->get_error_code() );
	}

	public function test_registered_guard_is_evaluated_in_observe_mode_after_a_block(): void {
		// Below the honeypot, so the honeypot decides and this one only observes.
		$this->registerExternalGuard( 10 );

		Guard_Runner::run(
			[
				'content'                        => 'ordinary text',
				'simple_spam_shield_website_url' => 'http://bot.example',
				'simple_spam_shield_form_loaded' => $this->token(),
			],
			'comment'
		);

		$this->assertSame( 1, Test_External_Guard::$observed_calls );
	}

	public function test_a_class_that_does_not_implement_the_contract_is_skipped(): void {
		add_filter(
			'simple_spam_shield_guards',
			static function ( array $defs ): array {
				$defs['bogus'] = [ 'class' => Test_Not_A_Guard::class, 'weight' => 50 ];
				$defs['absent'] = [ 'class' => 'No\\Such\\Class', 'weight' => 50 ];
				return $defs;
			}
		);
		Guard_Runner::init();

		// A clean submission still passes: neither bad entry broke the pipeline.
		$this->assertTrue(
			Guard_Runner::run(
				[
					'content'                        => 'ordinary text',
					'simple_spam_shield_website_url' => '',
					'simple_spam_shield_form_loaded' => $this->token(),
				],
				'comment'
			)
		);
	}

	public function test_a_builtin_guard_can_be_removed_by_filter(): void {
		add_filter(
			'simple_spam_shield_guards',
			static function ( array $defs ): array {
				unset( $defs['honeypot'] );
				return $defs;
			}
		);
		Guard_Runner::init();

		// With the honeypot gone, a filled trap no longer blocks.
		$this->assertTrue(
			Guard_Runner::run(
				[
					'content'                        => 'ordinary text',
					'simple_spam_shield_website_url' => 'http://bot.example',
					'simple_spam_shield_form_loaded' => $this->token(),
				],
				'comment'
			)
		);
	}

	public function test_blocked_action_fires_with_every_matched_guard(): void {
		Guard_Runner::init();
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords'] = 'casino';

		$seen = [];
		add_action(
			'simple_spam_shield_blocked',
			static function ( $guard, $context, $matched, $data ) use ( &$seen ): void {
				$seen = compact( 'guard', 'context', 'matched', 'data' );
			}
		);

		Guard_Runner::run(
			[
				'content'                        => 'visit my casino',
				'simple_spam_shield_website_url' => 'http://bot.example',
				'simple_spam_shield_form_loaded' => $this->token(),
			],
			'comment'
		);

		$this->assertSame( 'honeypot', $seen['guard'] );
		$this->assertSame( 'comment', $seen['context'] );
		$this->assertContains( 'honeypot', $seen['matched'] );
		$this->assertContains( 'keyword_block', $seen['matched'] );
		$this->assertSame( $seen['guard'], $seen['matched'][0] );
	}

	/** A filter returning nonsense must not disable every guard. */
	public function test_a_filter_returning_a_non_array_falls_back_to_the_builtins(): void {
		add_filter( 'simple_spam_shield_guards', static fn() => 'not an array' );
		Guard_Runner::init();

		$this->assertSame( 8, count( Guard_Runner::definitions() ) );

		// The honeypot still blocks, so protection was not lost.
		$this->assertInstanceOf(
			WP_Error::class,
			Guard_Runner::run(
				[
					'content'                        => 'ordinary text',
					'simple_spam_shield_website_url' => 'http://bot.example',
					'simple_spam_shield_form_loaded' => $this->token(),
				],
				'comment'
			)
		);
	}

	public function test_blocked_action_does_not_fire_for_a_clean_submission(): void {
		Guard_Runner::init();
		$fired = false;
		add_action( 'simple_spam_shield_blocked', static function () use ( &$fired ): void { $fired = true; } );

		Guard_Runner::run(
			[
				'content'                        => 'ordinary text',
				'simple_spam_shield_website_url' => '',
				'simple_spam_shield_form_loaded' => $this->token(),
			],
			'comment'
		);

		$this->assertFalse( $fired );
	}
}
