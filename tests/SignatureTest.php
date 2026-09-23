<?php

namespace Denar\WooCommerce\Tests;

use Denar\WooCommerce\Webhook\Signature;
use PHPUnit\Framework\TestCase;

/**
 * Must stay in line with App\Support\WebhookSignature in Denár.
 */
final class SignatureTest extends TestCase {

	private const SECRET = 'whsec_test';

	public function test_accepts_own_signature(): void {
		$header = Signature::sign( self::SECRET, 1_700_000_000, '{"a":1}' );

		$this->assertTrue( Signature::verify( self::SECRET, $header, '{"a":1}', 300, 1_700_000_100 ) );
	}

	public function test_matches_denar_reference_digest(): void {
		// Same formula as Denár: HMAC-SHA256 over "<t>.<body>".
		$expected = 't=1700000000,v1=' . hash_hmac( 'sha256', '1700000000.{"a":1}', self::SECRET );

		$this->assertSame( $expected, Signature::sign( self::SECRET, 1_700_000_000, '{"a":1}' ) );
	}

	public function test_rejects_modified_body(): void {
		$header = Signature::sign( self::SECRET, 1_700_000_000, '{"a":1}' );

		$this->assertFalse( Signature::verify( self::SECRET, $header, '{"a":2}', 300, 1_700_000_000 ) );
	}

	public function test_rejects_old_timestamp(): void {
		$header = Signature::sign( self::SECRET, 1_700_000_000, '{}' );

		$this->assertFalse( Signature::verify( self::SECRET, $header, '{}', 300, 1_700_000_301 ) );
	}

	public function test_rejects_wrong_secret_and_empty_secret(): void {
		$header = Signature::sign( self::SECRET, 1_700_000_000, '{}' );

		$this->assertFalse( Signature::verify( 'whsec_other', $header, '{}', 300, 1_700_000_000 ) );
		$this->assertFalse( Signature::verify( '', $header, '{}', 300, 1_700_000_000 ) );
	}

	public function test_rejects_malformed_header(): void {
		$this->assertFalse( Signature::verify( self::SECRET, 'garbage', '{}' ) );
		$this->assertFalse( Signature::verify( self::SECRET, 't=abc,v1=00', '{}' ) );
	}
}
