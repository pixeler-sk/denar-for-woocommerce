<?php

namespace Denar\WooCommerce\Tests;

use Denar\WooCommerce\Sync\PayloadBuilder;
use PHPUnit\Framework\TestCase;

final class PayloadBuilderTest extends TestCase {

	private function order( array $override = array() ): array {
		return array_replace_recursive(
			array(
				'order_number'       => '1042',
				'currency'           => 'EUR',
				'payment_method'     => 'bacs',
				'note'               => 'Objednávka č. 1042',
				'customer_reference' => 'woo:shop.sk:c7',
				'billing'            => array(
					'first_name' => 'Ján',
					'last_name'  => 'Novák',
					'company'    => '',
					'address_1'  => 'Dlhá 5',
					'address_2'  => '',
					'city'       => 'Košice',
					'postcode'   => '040 01',
					'country'    => 'SK',
					'email'      => 'jan@example.com',
					'phone'      => '',
				),
				'lines'              => array(
					array( 'label' => 'Tričko', 'quantity' => 2, 'net' => '16.26', 'rate' => 23 ),
					array( 'label' => 'Doprava', 'quantity' => 1, 'net' => '3.66', 'rate' => 23 ),
				),
				'discount_net'       => 0,
			),
			$override
		);
	}

	public function test_maps_order_to_invoice(): void {
		$payload = PayloadBuilder::document( $this->order(), 'invoice', 'woo:shop.sk:15' );

		$this->assertSame( 'woo:shop.sk:15', $payload['external_reference'] );
		$this->assertSame( 'invoice', $payload['document_type'] );
		$this->assertSame( 'bank_transfer', $payload['payment_method'] );
		$this->assertSame( 'sk', $payload['language'] );
		$this->assertSame( '1042', $payload['variable_symbol'] );
		$this->assertSame( '8.13', $payload['items'][0]['unit_price'] );
		$this->assertSame( 2.0, $payload['items'][0]['quantity'] );
		$this->assertSame( 23.0, $payload['items'][0]['vat_rate'] );
		$this->assertArrayNotHasKey( 'discount', $payload );
		$this->assertArrayNotHasKey( 'vat_regime', $payload );
	}

	public function test_partner_uses_company_and_splits_street(): void {
		$payload = PayloadBuilder::document(
			$this->order( array( 'billing' => array( 'company' => 'Studio Forma, s. r. o.', 'ico' => '12345678' ) ) ),
			'invoice',
			'r'
		);

		$this->assertSame( 'Studio Forma, s. r. o.', $payload['partner']['name'] );
		$this->assertSame( 'Dlhá', $payload['partner']['street'] );
		$this->assertSame( '5', $payload['partner']['street_number'] );
		$this->assertSame( '12345678', $payload['partner']['ico'] );
		$this->assertSame( 'woo:shop.sk:c7', $payload['partner']['external_reference'] );
		$this->assertArrayNotHasKey( 'phone', $payload['partner'] );
	}

	public function test_coupon_and_negative_fee_become_document_discount(): void {
		$order                    = $this->order( array( 'discount_net' => '2.00', 'discount_reason' => 'LETO' ) );
		$order['lines'][]         = array( 'label' => 'Zľava za odber', 'quantity' => 1, 'net' => '-1.50', 'rate' => 23 );
		$payload                  = PayloadBuilder::document( $order, 'invoice', 'r' );

		$this->assertCount( 2, $payload['items'] );
		$this->assertSame( '3.50', $payload['discount']['amount'] );
		$this->assertSame( 'LETO, Zľava za odber', $payload['discount']['reason'] );
	}

	public function test_reverse_charge_and_export_regimes(): void {
		$rc = PayloadBuilder::document( $this->order( array( 'vat_exempt_reason' => 'reverse_charge' ) ), 'invoice', 'r' );
		$ex = PayloadBuilder::document( $this->order( array( 'vat_exempt_reason' => 'export' ) ), 'invoice', 'r', array( 'number_series' => 'ESHOP' ) );

		$this->assertSame( 'eu_goods', $rc['vat_regime'] );
		$this->assertSame( 'export', $ex['vat_regime'] );
		$this->assertSame( 'ESHOP', $ex['number_series'] );
	}

	public function test_language_and_payment_method(): void {
		$payload = PayloadBuilder::document( $this->order( array( 'payment_method' => 'stripe', 'billing' => array( 'country' => 'AT' ) ) ), 'invoice', 'r' );

		$this->assertSame( 'card', $payload['payment_method'] );
		$this->assertSame( 'en', $payload['language'] );
	}

	public function test_split_street(): void {
		$this->assertSame( array( 'Hlavná', '12/A' ), PayloadBuilder::split_street( 'Hlavná 12/A' ) );
		$this->assertSame( array( 'Nám. SNP', '1340/18' ), PayloadBuilder::split_street( ' Nám.  SNP 1340/18 ' ) );
		$this->assertSame( array( 'Horná Lehota', '' ), PayloadBuilder::split_street( 'Horná Lehota' ) );
	}

	public function test_variable_symbol(): void {
		$this->assertSame( '20261042', PayloadBuilder::variable_symbol( 'ES-2026-1042' ) );
		$this->assertNull( PayloadBuilder::variable_symbol( 'ABC' ) );
		$this->assertNull( PayloadBuilder::variable_symbol( '12345678901' ) );
	}

	public function test_credit_note_items_and_amount_only_refund(): void {
		$lines = PayloadBuilder::credit_note(
			array( 'lines' => array( array( 'label' => 'Tričko', 'quantity' => -1, 'net' => '-8.13', 'rate' => 23 ) ), 'amount' => '10.00', 'label' => 'x' ),
			'woo:shop.sk:15:refund:20'
		);
		$this->assertSame( '8.13', $lines['items'][0]['unit_price'] );
		$this->assertSame( 1.0, $lines['items'][0]['quantity'] );

		$amount = PayloadBuilder::credit_note(
			array( 'lines' => array(), 'amount' => '12.30', 'fallback_rate' => 23, 'label' => 'Vrátenie platby' ),
			'r'
		);
		$this->assertSame( '10.00', $amount['items'][0]['unit_price'] );
		$this->assertSame( 23.0, $amount['items'][0]['vat_rate'] );
	}

	public function test_totals_match(): void {
		$this->assertTrue( PayloadBuilder::totals_match( '24.60', 24.6 ) );
		$this->assertTrue( PayloadBuilder::totals_match( '-12.30', '12.30' ) );
		$this->assertFalse( PayloadBuilder::totals_match( '29.96', '29.97' ) );
	}

	public function test_prices_with_vat_are_sent_as_entered(): void {
		$order = $this->order(
			array(
				'prices_include_tax' => true,
				'discount_gross'     => '1.23',
				'discount_reason'    => 'LETO',
			)
		);
		$order['lines'][0]['gross'] = '19.98';
		$order['lines'][1]['gross'] = '4.50';

		$payload = PayloadBuilder::document( $order, 'invoice', 'r' );

		$this->assertTrue( $payload['prices_include_vat'] );
		$this->assertSame( '9.99', $payload['items'][0]['unit_price_with_vat'] );
		$this->assertArrayNotHasKey( 'unit_price', $payload['items'][0] );
		$this->assertSame( '1.23', $payload['discount']['amount_with_vat'] );
		$this->assertArrayNotHasKey( 'amount', $payload['discount'] );
	}

	public function test_credit_note_with_vat(): void {
		$lines = PayloadBuilder::credit_note(
			array(
				'prices_include_tax' => true,
				'lines'              => array( array( 'label' => 'Káva', 'quantity' => -3, 'net' => '-24.37', 'gross' => '-29.97', 'rate' => 23 ) ),
				'amount'             => '29.97',
				'label'              => 'x',
			),
			'r'
		);
		$this->assertSame( '9.99', $lines['items'][0]['unit_price_with_vat'] );

		$amount = PayloadBuilder::credit_note(
			array( 'prices_include_tax' => true, 'lines' => array(), 'amount' => '12.30', 'fallback_rate' => 23, 'label' => 'x' ),
			'r'
		);
		$this->assertSame( '12.30', $amount['items'][0]['unit_price_with_vat'] );
	}
}
