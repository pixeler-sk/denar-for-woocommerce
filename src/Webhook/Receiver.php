<?php
/**
 * Incoming Denár webhooks.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce\Webhook;

use Denar\WooCommerce\Plugin;
use Denar\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint POST /wp-json/denar/v1/webhook. Verifies the signature,
 * drops repeated deliveries (Denár retries until it gets a 2xx) and hands
 * the event to `denar_wc_webhook_{event}` handlers, e.g.
 * `denar_wc_webhook_document.paid`. Handlers must be quick - heavy work goes
 * to Action Scheduler, a slow answer counts as a failed delivery.
 */
final class Receiver {

	public const NAMESPACE = 'denar/v1';

	public const ROUTE = '/webhook';

	/**
	 * How long a delivery id is remembered; covers Denár's whole retry
	 * schedule (1 min ... 12 h).
	 */
	private const DEDUP_TTL = 2 * DAY_IN_SECONDS;

	/**
	 * Hooks the route.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Public URL to paste into Denár -> Settings -> Webhooks.
	 */
	public static function url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	/**
	 * Registers the REST route.
	 */
	public static function register_route(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				// Authenticity is the HMAC signature, checked in handle().
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handles one delivery.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$body     = $request->get_body();
		$header   = (string) $request->get_header( 'x_denar_signature' );
		$event    = sanitize_text_field( (string) $request->get_header( 'x_denar_event' ) );
		$delivery = sanitize_key( (string) $request->get_header( 'x_denar_delivery' ) );

		if ( ! Signature::verify( Settings::webhook_secret(), $header, $body ) ) {
			Plugin::log( 'warning', 'Webhook rejected: invalid signature.', array( 'event' => $event ) );

			return new \WP_REST_Response( array( 'message' => 'invalid_signature' ), 401 );
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) || '' === $event ) {
			return new \WP_REST_Response( array( 'message' => 'invalid_payload' ), 400 );
		}

		$dedup_key = 'denar_wc_delivery_' . md5( $delivery );
		if ( '' !== $delivery && get_transient( $dedup_key ) ) {
			return new \WP_REST_Response( array( 'message' => 'duplicate' ), 200 );
		}

		/**
		 * Fires for a verified Denár webhook.
		 *
		 * @param array  $payload  Decoded body.
		 * @param string $delivery Delivery uuid.
		 */
		do_action( 'denar_wc_webhook_' . $event, $payload, $delivery );

		if ( '' !== $delivery ) {
			set_transient( $dedup_key, 1, self::DEDUP_TTL );
		}

		Plugin::log(
			'info',
			'Webhook received.',
			array(
				'event'    => $event,
				'delivery' => $delivery,
			)
		);

		return new \WP_REST_Response( array( 'message' => 'ok' ), 200 );
	}
}
