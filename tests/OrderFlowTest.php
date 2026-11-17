<?php

namespace Denar\WooCommerce\Tests;

use Denar\WooCommerce\Admin\OrdersColumn;
use Denar\WooCommerce\Sync\OrderSync;
use PHPUnit\Framework\TestCase;

/**
 * Status after a paired payment and the document shown in the orders list.
 */
final class OrderFlowTest extends TestCase {

	public function test_only_an_order_to_ship_gets_the_chosen_status(): void {
		$this->assertSame( 'paid', OrderSync::status_after_payment( 'processing', 'paid' ) );
		// Downloads only: WooCommerce completes the order, the setting must not hold it back.
		$this->assertSame( 'completed', OrderSync::status_after_payment( 'completed', 'paid' ) );
		$this->assertSame( 'completed', OrderSync::status_after_payment( 'completed', 'processing' ) );
	}

	public function test_orders_list_shows_the_invoice_before_the_proforma(): void {
		$proforma = array( 'id' => 7, 'number' => 'PF20260001' );
		$invoice  = array( 'id' => 8, 'number' => 'E2-20260004' );

		$this->assertSame(
			array( 'role' => 'invoice', 'id' => 8, 'number' => 'E2-20260004' ),
			OrdersColumn::document( array( 'proforma' => $proforma, 'invoice' => $invoice ) )
		);
		$this->assertSame(
			array( 'role' => 'proforma', 'id' => 7, 'number' => 'PF20260001' ),
			OrdersColumn::document( array( 'proforma' => $proforma, 'invoice' => array( 'id' => 9, 'number' => '' ) ) )
		);
	}

	public function test_orders_list_shows_nothing_for_drafts(): void {
		$this->assertNull( OrdersColumn::document( array() ) );
		$this->assertNull( OrdersColumn::document( array( 'invoice' => array( 'id' => 9, 'number' => '' ) ) ) );
	}
}
