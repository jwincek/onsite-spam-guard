<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Admin;

/** The Settings link in the plugin's row on the Plugins screen (#58). */
final class SettingsLinkTest extends TestCase {

	private const CORE_LINKS = [ 'deactivate' => '<a href="plugins.php?action=deactivate">Deactivate</a>' ];

	protected function setUp(): void {
		$GLOBALS['simple_spam_shield_test_caps'] = [ 'manage_options' => true ];
	}

	public function test_settings_comes_first_and_core_links_are_kept(): void {
		$links = Admin::add_action_links( self::CORE_LINKS );

		$this->assertSame( [ 'settings', 'deactivate' ], array_keys( $links ) );
		$this->assertSame( self::CORE_LINKS['deactivate'], $links['deactivate'] );
	}

	public function test_it_links_to_the_settings_page(): void {
		$links = Admin::add_action_links( self::CORE_LINKS );

		$this->assertSame( '<a href="https://example.test/wp-admin/admin.php?page=onsite-spam-guard">Settings</a>', $links['settings'] );
	}

	public function test_no_link_for_someone_who_cannot_change_settings(): void {
		$GLOBALS['simple_spam_shield_test_caps'] = [];

		$this->assertSame( self::CORE_LINKS, Admin::add_action_links( self::CORE_LINKS ) );
	}

	public function test_a_non_array_from_another_filter_is_passed_on(): void {
		$this->assertNull( Admin::add_action_links( null ) );
	}
}
