<?php

namespace Denar\WooCommerce\Tests;

use Denar\WooCommerce\Integration\Packeta;
use PHPUnit\Framework\TestCase;

/**
 * Delivery day from a Packeta packetTracking answer.
 */
final class PacketaTest extends TestCase {

	private function answer( string $records ): string {
		return '<?xml version="1.0" encoding="utf-8"?><response><status>ok</status><result>' . $records . '</result></response>';
	}

	private function record( string $date_time, string $code, string $text ): string {
		return '<record><dateTime>' . $date_time . '</dateTime><statusCode>' . $code . '</statusCode><codeText>' . $text . '</codeText><statusText>x</statusText></record>';
	}

	public function test_delivered_record_gives_its_day(): void {
		$xml = $this->answer(
			$this->record( '2026-10-29T16:02:11', '1', 'received data' )
			. $this->record( '2026-10-30T09:40:00', '4', 'ready for pickup' )
			. $this->record( '2026-10-31T23:15:00', '7', 'delivered' )
		);

		// Delivered late on the last day of the month stays in that month.
		$this->assertSame( '2026-10-31', Packeta::delivered_date( $xml ) );
	}

	public function test_not_delivered_yet_gives_null(): void {
		$xml = $this->answer( $this->record( '2026-10-30T09:40:00', '4', 'ready for pickup' ) );

		$this->assertNull( Packeta::delivered_date( $xml ) );
	}

	public function test_error_or_garbage_gives_null(): void {
		$this->assertNull( Packeta::delivered_date( '<response><status>fault</status><fault>IncorrectApiPasswordFault</fault></response>' ) );
		$this->assertNull( Packeta::delivered_date( 'not xml' ) );
		$this->assertNull( Packeta::delivered_date( '' ) );
	}
}
