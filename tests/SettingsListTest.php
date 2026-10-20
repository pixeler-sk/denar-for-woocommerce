<?php

namespace Denar\WooCommerce\Tests;

use Denar\WooCommerce\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Status and e-mail lists from the settings (multiselect options).
 */
final class SettingsListTest extends TestCase {

	public function test_never_saved_option_gives_the_default(): void {
		$this->assertSame( array( 'processing', 'completed' ), Settings::normalize_list( false, Settings::DEFAULT_INVOICE_STATUSES ) );
	}

	public function test_saved_empty_list_stays_empty(): void {
		// Nothing ticked = no invoice at any status, not "back to the default".
		$this->assertSame( array(), Settings::normalize_list( array(), Settings::DEFAULT_INVOICE_STATUSES ) );
		$this->assertSame( array(), Settings::normalize_list( '', Settings::DEFAULT_INVOICE_STATUSES ) );
	}

	public function test_statuses_lose_the_wc_prefix_and_duplicates(): void {
		$this->assertSame(
			array( 'paid', 'production', 'completed' ),
			Settings::normalize_list( array( 'wc-paid', 'production', ' completed ', 'paid' ), array() )
		);
	}
}
