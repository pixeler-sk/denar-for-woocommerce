<?php
/**
 * Order -> Denár document flows.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce\Sync;

use Denar\WooCommerce\Api\ApiException;
use Denar\WooCommerce\Api\Client;
use Denar\WooCommerce\Plugin;
use Denar\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The flows, all run by Action Scheduler (never inside the checkout request):
 *
 * - bank transfer (bacs), order on hold  -> proforma, issued (number, VS =
 *   order number, PAY by square on its PDF)
 * - Denár pairs the payment (webhook document.paid on the proforma)
 *   -> WC_Order::payment_complete() -> invoice from the proforma, paid
 * - paid order (card, gateway, bacs confirmed by hand) -> invoice, paid;
 *   with a proforma it is created from it
 * - cash on delivery -> invoice when the order is completed, paid
 * - refund -> credit note to the invoice
 * - cancelled order -> unpaid proforma cancelled (a draft one deleted)
 *
 * Every document carries an external_reference (`woo:<site>:<order id>`,
 * `:proforma`, `:refund:<id>`), so a retry after a lost response finds the
 * document instead of creating another. A document whose Denár total differs
 * from the shop's (rounding of prices with VAT) stays a draft and waits for
 * a person - it is never issued with a wrong amount.
 */
final class OrderSync {

	public const HOOK = 'denar_wc_process';

	public const GROUP = 'denar';

	/**
	 * Waits between attempts after a transport error, 429 or 5xx (seconds).
	 */
	private const RETRY_DELAYS = array( 60, 300, 1800, 7200, 43200 );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'process' ), 10, 4 );
		add_action( 'denar_wc_webhook_document.paid', array( self::class, 'on_document_paid' ) );
		add_action( 'denar_wc_webhook_document.cancelled', array( self::class, 'on_document_cancelled' ) );

		if ( ! Settings::enabled() ) {
			return;
		}

		add_action( 'woocommerce_order_status_on-hold', array( self::class, 'on_hold' ), 10, 2 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'on_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'on_processing' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'on_paid' ) );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'on_cancelled' ) );
		add_action( 'woocommerce_order_refunded', array( self::class, 'on_refunded' ), 10, 2 );
	}

	/**
	 * Bank transfer order waiting for payment.
	 *
	 * @param int       $order_id Order id.
	 * @param \WC_Order $order    Order.
	 */
	public static function on_hold( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order && 'bacs' === $order->get_payment_method() && Settings::bacs_proforma() ) {
			self::enqueue( (int) $order_id, 'proforma' );
		}
	}

	/**
	 * Payment complete or order completed.
	 *
	 * @param int $order_id Order id.
	 */
	public static function on_paid( $order_id ): void {
		self::enqueue( (int) $order_id, 'invoice' );
	}

	/**
	 * Processing: paid, except cash on delivery (invoiced on completion).
	 *
	 * @param int       $order_id Order id.
	 * @param \WC_Order $order    Order.
	 */
	public static function on_processing( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order && 'cod' !== $order->get_payment_method() ) {
			self::enqueue( (int) $order_id, 'invoice' );
		}
	}

	/**
	 * Cancelled order.
	 *
	 * @param int $order_id Order id.
	 */
	public static function on_cancelled( $order_id ): void {
		self::enqueue( (int) $order_id, 'cancel' );
	}

	/**
	 * Refund created.
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 */
	public static function on_refunded( $order_id, $refund_id ): void {
		self::enqueue( (int) $order_id, 'refund', (int) $refund_id );
	}

	/**
	 * Queues a task; the same pending task is queued once.
	 *
	 * @param int    $order_id  Order id.
	 * @param string $task      proforma|invoice|cancel|refund.
	 * @param int    $refund_id Refund id for the refund task.
	 */
	public static function enqueue( int $order_id, string $task, int $refund_id = 0 ): void {
		$args = array( $order_id, $task, $refund_id, 0 );

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return;
		}

		as_enqueue_async_action( self::HOOK, $args, self::GROUP );
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param int    $order_id  Order id.
	 * @param string $task      Task.
	 * @param int    $refund_id Refund id.
	 * @param int    $attempt   Attempt number, 0 = first.
	 */
	public static function process( $order_id, $task, $refund_id = 0, $attempt = 0 ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund ) {
			return;
		}

		$client = Client::from_settings();

		try {
			match ( (string) $task ) {
				'proforma' => self::proforma( $order, $client ),
				'invoice'  => self::invoice( $order, $client ),
				'cancel'   => self::cancel( $order, $client ),
				'refund'   => self::refund( $order, (int) $refund_id, $client ),
				default    => null,
			};
			OrderState::set_error( $order, '' );
		} catch ( ApiException $e ) {
			$attempt = (int) $attempt;

			if ( $e->is_retryable() && isset( self::RETRY_DELAYS[ $attempt ] ) ) {
				as_schedule_single_action( time() + self::RETRY_DELAYS[ $attempt ], self::HOOK, array( (int) $order_id, (string) $task, (int) $refund_id, $attempt + 1 ), self::GROUP );
				Plugin::log( 'warning', sprintf( 'Order %d, %s: %s - retry %d scheduled.', $order->get_id(), $task, $e->getMessage(), $attempt + 1 ) );

				return;
			}

			self::fail( $order, $e );
		}
	}

	/**
	 * Proforma for a bank transfer order.
	 *
	 * @param \WC_Order $order  Order.
	 * @param Client    $client API client.
	 */
	private static function proforma( \WC_Order $order, Client $client ): void {
		$known = OrderState::get( $order, 'proforma' );
		if ( null !== $known && '' !== $known['number'] ) {
			return;
		}
		// Paid before the proforma was made: the invoice task takes over.
		if ( null !== OrderState::get( $order, 'invoice' ) ) {
			return;
		}

		$data     = OrderData::collect( $order, Settings::site_key() );
		$document = self::create( $client, PayloadBuilder::document( $data, 'proforma', self::reference( $order, 'proforma' ), self::options() ), $data['total'] );
		$document = self::issue_if_matching( $order, $client, $document, $data['total'], 'proforma' );

		if ( null !== $document['number'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: document number */
					__( 'Denár: proforma %s issued.', 'denar-for-woocommerce' ),
					$document['number']
				)
			);
		}
	}

	/**
	 * Invoice for a paid order - from the proforma when there is one.
	 *
	 * @param \WC_Order $order  Order.
	 * @param Client    $client API client.
	 */
	private static function invoice( \WC_Order $order, Client $client ): void {
		$known = OrderState::get( $order, 'invoice' );
		if ( null !== $known && $known['is_paid'] ) {
			return;
		}

		$paid_at   = self::paid_at( $order );
		$reference = self::reference( $order, 'invoice' );
		$proforma  = OrderState::get( $order, 'proforma' );
		$document  = $client->find_document( $reference );

		// A draft left by an earlier attempt goes through create() again,
		// which brings it up to date with the order.
		if ( null !== $document && empty( $document['is_issued'] ) ) {
			$document = null;
		}

		if ( null === $document && null !== $proforma && '' !== $proforma['number'] ) {
			// Proforma paid by hand in the shop (not paired in Denár yet).
			if ( ! $proforma['is_paid'] ) {
				$paid = $client->post( '/documents/' . $proforma['id'] . '/pay', array( 'paid_at' => $paid_at ) )['data'];
				OrderState::put( $order, 'proforma', $paid );
			}

			$document = $client->post(
				'/documents/' . $proforma['id'] . '/invoice',
				array(
					'external_reference' => $reference,
					'delivery_date'      => $paid_at,
					'issue'              => true,
				)
			)['data'];
		}

		if ( null === $document ) {
			// A draft proforma that never got issued is replaced by the invoice.
			if ( null !== $proforma && '' === $proforma['number'] ) {
				self::delete_draft( $order, $client, 'proforma', $proforma );
			}

			$data     = OrderData::collect( $order, Settings::site_key() );
			$document = self::create( $client, PayloadBuilder::document( $data, 'invoice', $reference, self::options() ), $data['total'] );
		}

		OrderState::put( $order, 'invoice', $document );
		$document = self::issue_if_matching( $order, $client, $document, (float) $order->get_total(), 'invoice' );

		if ( null === $document['number'] ) {
			return;
		}

		if ( empty( $document['is_paid'] ) ) {
			$document = $client->post( '/documents/' . $document['id'] . '/pay', array( 'paid_at' => $paid_at ) )['data'];
			OrderState::put( $order, 'invoice', $document );
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: document number */
				__( 'Denár: invoice %s issued and marked paid.', 'denar-for-woocommerce' ),
				$document['number']
			)
		);
	}

	/**
	 * Cancelled order: an unpaid proforma is cancelled, a draft deleted.
	 *
	 * @param \WC_Order $order  Order.
	 * @param Client    $client API client.
	 */
	private static function cancel( \WC_Order $order, Client $client ): void {
		$proforma = OrderState::get( $order, 'proforma' );
		if ( null === $proforma || $proforma['is_paid'] || 'cancelled' === $proforma['status'] || null !== OrderState::get( $order, 'invoice' ) ) {
			return;
		}

		if ( '' === $proforma['number'] ) {
			self::delete_draft( $order, $client, 'proforma', $proforma );

			return;
		}

		$document = $client->post( '/documents/' . $proforma['id'] . '/cancel' )['data'];
		OrderState::put( $order, 'proforma', $document );
		$order->add_order_note(
			sprintf(
				/* translators: %s: document number */
				__( 'Denár: proforma %s cancelled.', 'denar-for-woocommerce' ),
				$proforma['number']
			)
		);
	}

	/**
	 * Credit note for a refund.
	 *
	 * @param \WC_Order $order     Order.
	 * @param int       $refund_id Refund id.
	 * @param Client    $client    API client.
	 */
	private static function refund( \WC_Order $order, int $refund_id, Client $client ): void {
		$role  = 'refund:' . $refund_id;
		$known = OrderState::get( $order, $role );
		if ( null !== $known && '' !== $known['number'] ) {
			return;
		}

		$refund  = wc_get_order( $refund_id );
		$invoice = OrderState::get( $order, 'invoice' );
		if ( ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		if ( null === $invoice || '' === $invoice['number'] ) {
			$order->add_order_note( __( 'Denár: refund without an issued invoice - no credit note created.', 'denar-for-woocommerce' ) );

			return;
		}

		$reference = self::reference( $order, $role );
		$document  = $client->find_document( $reference )
			?? $client->post( '/documents/' . $invoice['id'] . '/credit-note', PayloadBuilder::credit_note( OrderData::refund( $refund, $order ), $reference ) )['data'];

		OrderState::put( $order, $role, $document );
		$document = self::issue_if_matching( $order, $client, $document, (float) $refund->get_amount(), $role );

		if ( null !== $document['number'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: document number */
					__( 'Denár: credit note %s issued.', 'denar-for-woocommerce' ),
					$document['number']
				)
			);
		}
	}

	/**
	 * Webhook document.paid: a paired proforma completes the order payment.
	 *
	 * @param array $payload Webhook body.
	 */
	public static function on_document_paid( array $payload ): void {
		$document = $payload['data']['document'] ?? array();
		$order    = self::order_for( (string) ( $document['external_reference'] ?? '' ) );
		if ( null === $order ) {
			return;
		}

		$role = 'proforma' === ( $document['document_type'] ?? '' ) ? 'proforma' : ( 'invoice' === ( $document['document_type'] ?? '' ) ? 'invoice' : '' );
		if ( '' === $role ) {
			return;
		}

		OrderState::put( $order, $role, $document );

		if ( 'proforma' === $role && ! $order->is_paid() ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: document number */
					__( 'Denár: payment of proforma %s received.', 'denar-for-woocommerce' ),
					(string) ( $document['number'] ?? '' )
				)
			);
			// Status to processing, paid date, stock - and the invoice task.
			$order->payment_complete();
		}
	}

	/**
	 * Webhook document.cancelled: keep the order box in sync.
	 *
	 * @param array $payload Webhook body.
	 */
	public static function on_document_cancelled( array $payload ): void {
		$document = $payload['data']['document'] ?? array();
		$order    = self::order_for( (string) ( $document['external_reference'] ?? '' ) );
		if ( null !== $order && 'proforma' === ( $document['document_type'] ?? '' ) ) {
			OrderState::put( $order, 'proforma', $document );
		}
	}

	/**
	 * External reference of a document of this order.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $role  invoice|proforma|refund:<id>.
	 */
	public static function reference( \WC_Order $order, string $role ): string {
		$base = 'woo:' . Settings::site_key() . ':' . $order->get_id();

		return 'invoice' === $role ? $base : $base . ':' . $role;
	}

	/**
	 * Order of this shop for a document reference, or null.
	 *
	 * @param string $reference External reference.
	 */
	private static function order_for( string $reference ): ?\WC_Order {
		$prefix = 'woo:' . Settings::site_key() . ':';
		if ( ! str_starts_with( $reference, $prefix ) || ! preg_match( '/^(\d+)/', substr( $reference, strlen( $prefix ) ), $m ) ) {
			return null;
		}

		$order = wc_get_order( (int) $m[1] );

		return $order instanceof \WC_Order && ! $order instanceof \WC_Order_Refund ? $order : null;
	}

	/**
	 * POST /documents. For a known reference Denár returns the existing
	 * document unchanged - a draft left behind by an earlier attempt (say a
	 * total that did not match) is then updated with the current order.
	 *
	 * @param Client $client   API client.
	 * @param array  $payload  Document payload.
	 * @param float  $expected Shop total (gross).
	 */
	private static function create( Client $client, array $payload, float $expected ): array {
		$document = $client->post( '/documents', $payload )['data'];

		if ( empty( $document['is_issued'] ) && ! PayloadBuilder::totals_match( $document['totals']['with_vat'] ?? '', $expected ) ) {
			unset( $payload['document_type'], $payload['external_reference'] );
			$document = $client->patch( '/documents/' . $document['id'], $payload )['data'];
		}

		return $document;
	}

	/**
	 * Issues a draft when Denár's total equals the shop's; otherwise leaves it
	 * a draft with a note. Returns the document (number null while a draft).
	 *
	 * @param \WC_Order $order    Order.
	 * @param Client    $client   API client.
	 * @param array     $document Document from the API.
	 * @param float     $expected Shop total (gross).
	 * @param string    $role     Role for OrderState.
	 *
	 * @throws ApiException When the totals differ (total_mismatch), or from the API.
	 */
	private static function issue_if_matching( \WC_Order $order, Client $client, array $document, float $expected, string $role ): array {
		if ( empty( $document['is_issued'] ) ) {
			$total = (string) ( $document['totals']['with_vat'] ?? '' );

			if ( ! PayloadBuilder::totals_match( $total, $expected ) ) {
				OrderState::put( $order, $role, $document );
				$message = sprintf(
					/* translators: 1: Denár total, 2: shop total */
					__( 'Denár: the draft total %1$s differs from the shop total %2$s (rounding of prices with VAT). The draft was not issued - check it in Denár.', 'denar-for-woocommerce' ),
					PayloadBuilder::money( abs( (float) $total ) ),
					PayloadBuilder::money( $expected )
				);
				$order->add_order_note( $message );
				OrderState::set_error( $order, $message );

				throw new ApiException( $message, 422, 'total_mismatch' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- data, escaped where printed.
			}

			$document = $client->post( '/documents/' . $document['id'] . '/issue' )['data'];
		}

		OrderState::put( $order, $role, $document );

		return $document;
	}

	/**
	 * Deletes a never-issued draft and forgets it.
	 *
	 * @param \WC_Order $order   Order.
	 * @param Client    $client  API client.
	 * @param string    $role    Role.
	 * @param array     $summary Stored summary.
	 *
	 * @throws ApiException From the API, except 404 (already gone).
	 */
	private static function delete_draft( \WC_Order $order, Client $client, string $role, array $summary ): void {
		try {
			$client->delete( '/documents/' . $summary['id'] );
		} catch ( ApiException $e ) {
			if ( 404 !== $e->status ) {
				throw $e;
			}
		}

		$all = OrderState::all( $order );
		unset( $all[ $role ] );
		$order->update_meta_data( '_denar_documents', $all );
		$order->save();
	}

	/**
	 * Stops the flow: order note and the error in the order box.
	 *
	 * @param \WC_Order    $order Order.
	 * @param ApiException $e     Error.
	 */
	private static function fail( \WC_Order $order, ApiException $e ): void {
		if ( 'total_mismatch' === $e->error_code ) {
			return; // Already noted.
		}

		$message = $e->getMessage();
		foreach ( $e->errors as $field => $errors ) {
			$message .= ' ' . $field . ': ' . implode( ' ', (array) $errors );
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: error message */
				__( 'Denár: %s', 'denar-for-woocommerce' ),
				$message
			)
		);
		OrderState::set_error( $order, $message );
		Plugin::log( 'error', sprintf( 'Order %d: %s', $order->get_id(), $message ) );
	}

	/**
	 * Payment date of the order (Y-m-d), today when unknown.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function paid_at( \WC_Order $order ): string {
		$date = $order->get_date_paid() ?? $order->get_date_completed();

		return $date ? $date->date( 'Y-m-d' ) : wp_date( 'Y-m-d' );
	}

	/**
	 * Mapping options from the settings.
	 */
	private static function options(): array {
		return array(
			'number_series'         => Settings::number_series(),
			'reverse_charge_regime' => Settings::reverse_charge_regime(),
		);
	}
}
