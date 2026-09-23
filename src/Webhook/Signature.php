<?php
/**
 * Webhook signature verification.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce\Webhook;

/**
 * Port of App\Support\WebhookSignature from Denár - keep them identical.
 * Header `X-Denar-Signature: t=<unix time>,v1=<hex HMAC-SHA256(secret, "<t>.<body>")>`.
 * Pure (no WordPress), unit tested.
 */
final class Signature {

	public const HEADER = 'X-Denar-Signature';

	/**
	 * Header value for the body; used by tests and the local simulator.
	 *
	 * @param string $secret    Endpoint secret.
	 * @param int    $timestamp Unix time.
	 * @param string $body      Raw body.
	 */
	public static function sign( string $secret, int $timestamp, string $body ): string {
		return 't=' . $timestamp . ',v1=' . self::digest( $secret, $timestamp, $body );
	}

	/**
	 * True when the header carries a valid signature of the body and its
	 * timestamp lies within the tolerance (seconds) of now.
	 *
	 * @param string   $secret    Endpoint secret.
	 * @param string   $header    Header value.
	 * @param string   $body      Raw body exactly as received.
	 * @param int      $tolerance Allowed clock skew / replay window.
	 * @param int|null $now       Current time, for tests.
	 */
	public static function verify( string $secret, string $header, string $body, int $tolerance = 300, ?int $now = null ): bool {
		if ( '' === $secret ) {
			return false;
		}

		$parts = array();
		foreach ( explode( ',', $header ) as $pair ) {
			$kv = explode( '=', trim( $pair ), 2 );
			if ( 2 === count( $kv ) ) {
				$parts[ $kv[0] ] = $kv[1];
			}
		}

		if ( ! isset( $parts['t'], $parts['v1'] ) || ! ctype_digit( $parts['t'] ) ) {
			return false;
		}

		$timestamp = (int) $parts['t'];
		if ( abs( ( $now ?? time() ) - $timestamp ) > $tolerance ) {
			return false;
		}

		return hash_equals( self::digest( $secret, $timestamp, $body ), $parts['v1'] );
	}

	/**
	 * HMAC digest.
	 *
	 * @param string $secret    Endpoint secret.
	 * @param int    $timestamp Unix time.
	 * @param string $body      Raw body.
	 */
	private static function digest( string $secret, int $timestamp, string $body ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}
}
