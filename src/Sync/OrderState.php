<?php
/**
 * Denár documents remembered on an order.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * One order meta (`_denar_documents`, HPOS-safe through the order object)
 * with a short summary of each document - enough for the order box and for
 * deciding the next step without calling the API. Denár stays the source of
 * truth; this is a cache keyed by role (proforma, invoice, refund:<id>).
 */
final class OrderState {

	private const META = '_denar_documents';

	private const ERROR_META = '_denar_error';

	/**
	 * Document summary for a role, or null.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $role  proforma|invoice|refund:<id>.
	 */
	public static function get( \WC_Order $order, string $role ): ?array {
		$all = self::all( $order );

		return $all[ $role ] ?? null;
	}

	/**
	 * All remembered documents, role => summary.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function all( \WC_Order $order ): array {
		$value = $order->get_meta( self::META );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Stores the summary of a document returned by the API.
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $role     proforma|invoice|refund:<id>.
	 * @param array     $document Document from the API (`data`).
	 */
	public static function put( \WC_Order $order, string $role, array $document ): array {
		$summary = array(
			'id'       => (int) ( $document['id'] ?? 0 ),
			'type'     => (string) ( $document['document_type'] ?? '' ),
			'number'   => (string) ( $document['number'] ?? '' ),
			'status'   => (string) ( $document['status'] ?? '' ),
			'is_paid'  => (bool) ( $document['is_paid'] ?? false ),
			'total'    => (string) ( $document['totals']['with_vat'] ?? '' ),
			'currency' => (string) ( $document['currency'] ?? '' ),
			'vs'       => (string) ( $document['variable_symbol'] ?? '' ),
		);

		$all          = self::all( $order );
		$all[ $role ] = $summary;
		$order->update_meta_data( self::META, $all );
		$order->save();

		return $summary;
	}

	/**
	 * Last error that stopped the sync (shown in the order box), or ''.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function error( \WC_Order $order ): string {
		return (string) $order->get_meta( self::ERROR_META );
	}

	/**
	 * Sets or clears (empty string) the error.
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $message Message.
	 */
	public static function set_error( \WC_Order $order, string $message ): void {
		if ( '' === $message ) {
			$order->delete_meta_data( self::ERROR_META );
		} else {
			$order->update_meta_data( self::ERROR_META, $message );
		}
		$order->save();
	}
}
