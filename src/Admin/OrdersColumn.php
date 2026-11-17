<?php
/**
 * Invoice number in the orders list.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce\Admin;

use Denar\WooCommerce\Sync\OrderState;

defined( 'ABSPATH' ) || exit;

/**
 * Column "Invoice" in WooCommerce -> Orders (classic and HPOS list): the
 * number of the invoice from Denár as a link to its PDF, the proforma while
 * the order waits for its payment, and a mark when the sync stopped.
 */
final class OrdersColumn {

	public const COLUMN = 'denar_invoice';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'manage_edit-shop_order_columns', array( self::class, 'add' ), 20 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( self::class, 'add' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( self::class, 'render' ), 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( self::class, 'render' ), 10, 2 );
	}

	/**
	 * Adds the column behind the order status.
	 *
	 * @param mixed $columns Columns, id => label.
	 */
	public static function add( $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();
		$label   = __( 'Invoice', 'denar-for-woocommerce' );

		if ( ! isset( $columns['order_status'] ) ) {
			$columns[ self::COLUMN ] = $label;

			return $columns;
		}

		$sorted = array();
		foreach ( $columns as $id => $name ) {
			$sorted[ $id ] = $name;
			if ( 'order_status' === $id ) {
				$sorted[ self::COLUMN ] = $label;
			}
		}

		return $sorted;
	}

	/**
	 * Cell content.
	 *
	 * @param string        $column      Column id.
	 * @param int|\WC_Order $order_or_id Post id (classic storage) or order (HPOS).
	 */
	public static function render( $column, $order_or_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$document = self::document( OrderState::all( $order ) );
		$error    = OrderState::error( $order );

		if ( null !== $document ) {
			$number = 'invoice' === $document['role']
				? $document['number']
				/* translators: %s: proforma number */
				: sprintf( __( 'Proforma %s', 'denar-for-woocommerce' ), $document['number'] );

			if ( current_user_can( 'edit_shop_orders' ) ) {
				printf( '<a href="%s">%s</a>', esc_url( OrderBox::pdf_url( $order, $document['id'] ) ), esc_html( $number ) );
			} else {
				echo esc_html( $number );
			}
		} elseif ( '' === $error ) {
			echo '<span aria-hidden="true">&ndash;</span>';
		}

		if ( '' !== $error ) {
			printf(
				' <span class="dashicons dashicons-warning" style="color:#b32d2e" title="%1$s"></span><span class="screen-reader-text">%1$s</span>',
				esc_attr( $error )
			);
		}
	}

	/**
	 * Document shown for an order: its issued invoice, otherwise its issued
	 * proforma; drafts have no number to show.
	 *
	 * @param array $documents Role => summary, see OrderState.
	 *
	 * @return array{role: string, id: int, number: string}|null
	 */
	public static function document( array $documents ): ?array {
		foreach ( array( 'invoice', 'proforma' ) as $role ) {
			$document = $documents[ $role ] ?? null;

			if ( is_array( $document ) && '' !== (string) ( $document['number'] ?? '' ) && (int) ( $document['id'] ?? 0 ) > 0 ) {
				return array(
					'role'   => $role,
					'id'     => (int) $document['id'],
					'number' => (string) $document['number'],
				);
			}
		}

		return null;
	}
}
