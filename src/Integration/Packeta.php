<?php
/**
 * Delivery date of a Packeta parcel.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce\Integration;

use Denar\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Cash on delivery is supplied - and VAT becomes due - the day the customer
 * takes the parcel (§ 19 ods. 1 of the Slovak VAT act), not the day someone
 * marks the order completed. With the Packeta plugin installed the parcel
 * id and the API password are already in the shop, so the date comes from
 * Packeta's tracking history. The Packeta plugin itself keeps only the
 * status text, and it checks it a few times a day at most.
 */
final class Packeta {

	private const API_URL = 'https://www.zasilkovna.cz/api/rest';

	private const TIMEOUT = 10;

	/**
	 * Packeta status code of a parcel handed over to the customer.
	 */
	private const DELIVERED = '7';

	private const META = '_denar_delivered_at';

	/**
	 * Day the parcel of the order was delivered (Y-m-d), or null when the
	 * order has no Packeta parcel, it is not delivered yet or Packeta does
	 * not answer. A found date is remembered on the order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function delivered_on( \WC_Order $order ): ?string {
		$known = (string) $order->get_meta( self::META );
		if ( '' !== $known ) {
			return $known;
		}

		$packet   = self::packet_id( $order->get_id() );
		$password = self::api_password();
		if ( null === $packet || null === $password ) {
			return null;
		}

		$body = '<packetTracking><apiPassword>' . esc_xml( $password ) . '</apiPassword><packetId>' . esc_xml( $packet ) . '</packetId></packetTracking>';

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'text/xml; charset=utf-8' ),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			Plugin::log( 'warning', sprintf( 'Order %d: Packeta tracking unavailable.', $order->get_id() ) );

			return null;
		}

		$date = self::delivered_date( (string) wp_remote_retrieve_body( $response ) );
		if ( null !== $date ) {
			$order->update_meta_data( self::META, $date );
			$order->save();
		}

		return $date;
	}

	/**
	 * Date (Y-m-d) of the delivered record in a packetTracking answer, or null.
	 *
	 * @param string $xml Response body.
	 */
	public static function delivered_date( string $xml ): ?string {
		if ( '' === trim( $xml ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET );
		libxml_use_internal_errors( $previous );

		if ( false === $doc || 'ok' !== (string) $doc->status ) {
			return null;
		}

		$records = $doc->xpath( '//record' );
		$found   = null;
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			// Element names come from the Packeta API.
			$code = trim( (string) $record->{'statusCode'} );
			$text = trim( (string) $record->{'codeText'} );
			if ( self::DELIVERED === $code || 'delivered' === $text ) {
				// Packeta reports local time (Europe/Prague = Bratislava); the last such record wins.
				$found = substr( trim( (string) $record->{'dateTime'} ), 0, 10 );
			}
		}

		return null !== $found && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $found ) ? $found : null;
	}

	/**
	 * Packeta parcel id of the order, or null.
	 *
	 * @param int $order_id Order id.
	 */
	private static function packet_id( int $order_id ): ?string {
		global $wpdb;

		$table = $wpdb->prefix . 'packetery_order';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the Packeta plugin's own table; the result is cached on the order.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$packet = $wpdb->get_var( $wpdb->prepare( "SELECT packet_id FROM {$table} WHERE id = %d", $order_id ) );

		return is_string( $packet ) && '' !== trim( $packet ) ? trim( $packet ) : null;
	}

	/**
	 * API password from the Packeta plugin settings, or null.
	 */
	private static function api_password(): ?string {
		$options  = get_option( 'packetery' );
		$password = is_array( $options ) ? trim( (string) ( $options['api_password'] ?? '' ) ) : '';

		return '' !== $password ? $password : null;
	}
}
