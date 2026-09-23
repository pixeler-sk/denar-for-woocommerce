<?php
/**
 * "Denár" box on the order screen.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce\Admin;

use Denar\WooCommerce\Api\ApiException;
use Denar\WooCommerce\Api\Client;
use Denar\WooCommerce\Settings;
use Denar\WooCommerce\Sync\OrderState;
use Denar\WooCommerce\Sync\OrderSync;

defined( 'ABSPATH' ) || exit;

/**
 * Side box listing the order's Denár documents with a PDF download (proxied
 * through the shop - the API key never reaches the browser), the last error,
 * and the order action "Send to Denár again".
 */
final class OrderBox {

	private const PDF_ACTION = 'denar_wc_pdf';

	private const RESYNC_ACTION = 'denar_wc_resync';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add_box' ) );
		add_action( 'admin_post_' . self::PDF_ACTION, array( self::class, 'download_pdf' ) );
		add_filter( 'woocommerce_order_actions', array( self::class, 'order_actions' ) );
		add_action( 'woocommerce_order_action_' . self::RESYNC_ACTION, array( self::class, 'resync' ) );
	}

	/**
	 * Registers the box on the classic and the HPOS order screen.
	 */
	public static function add_box(): void {
		add_meta_box( 'denar-wc', __( 'Denár', 'denar-for-woocommerce' ), array( self::class, 'render' ), wc_get_page_screen_id( 'shop-order' ), 'side', 'default' );
	}

	/**
	 * Box content.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post (classic storage) or order (HPOS).
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$documents = OrderState::all( $order );
		$error     = OrderState::error( $order );

		if ( array() === $documents && '' === $error ) {
			echo '<p>' . esc_html(
				Settings::enabled()
					? __( 'No document yet.', 'denar-for-woocommerce' )
					: __( 'Sending to Denár is off or the API key is missing.', 'denar-for-woocommerce' )
			) . '</p>';
		}

		if ( array() !== $documents ) {
			echo '<ul class="denar-wc-documents">';
			foreach ( $documents as $document ) {
				echo '<li><strong>' . esc_html( self::type_label( (string) $document['type'] ) ) . '</strong> ';
				echo esc_html( '' !== $document['number'] ? $document['number'] : __( '(draft)', 'denar-for-woocommerce' ) );
				echo '<br><span class="description">' . esc_html( self::status_label( $document ) );
				if ( '' !== $document['total'] ) {
					echo ' · ' . esc_html( $document['total'] . ' ' . $document['currency'] );
				}
				echo '</span>';
				if ( '' !== $document['number'] && current_user_can( 'edit_shop_orders' ) ) {
					printf(
						'<br><a href="%s">%s</a>',
						esc_url( self::pdf_url( $order, (int) $document['id'] ) ),
						esc_html__( 'Download PDF', 'denar-for-woocommerce' )
					);
				}
				echo '</li>';
			}
			echo '</ul>';
		}

		if ( '' !== $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
			echo '<p class="description">' . esc_html__( 'Fix it in Denár or on the order, then use the order action "Send to Denár again".', 'denar-for-woocommerce' ) . '</p>';
		}
	}

	/**
	 * Order action.
	 *
	 * @param array $actions Actions.
	 */
	public static function order_actions( array $actions ): array {
		if ( Settings::enabled() ) {
			$actions[ self::RESYNC_ACTION ] = __( 'Send to Denár again', 'denar-for-woocommerce' );
		}

		return $actions;
	}

	/**
	 * Re-runs the step the order is at.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function resync( \WC_Order $order ): void {
		OrderState::set_error( $order, '' );

		if ( $order->is_paid() || $order->has_status( 'completed' ) ) {
			OrderSync::enqueue( $order->get_id(), 'invoice' );
		} elseif ( 'bacs' === $order->get_payment_method() && Settings::bacs_proforma() ) {
			OrderSync::enqueue( $order->get_id(), 'proforma' );
		}

		$order->add_order_note( __( 'Denár: queued again.', 'denar-for-woocommerce' ), 0, true );
	}

	/**
	 * Streams a document PDF; admin-post handler.
	 */
	public static function download_pdf(): void {
		$order_id    = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		$document_id = isset( $_GET['document'] ) ? absint( $_GET['document'] ) : 0;

		check_admin_referer( self::PDF_ACTION . '-' . $order_id . '-' . $document_id );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'denar-for-woocommerce' ), 403 );
		}

		$order = wc_get_order( $order_id );
		$known = $order instanceof \WC_Order ? wp_list_pluck( OrderState::all( $order ), 'number', 'id' ) : array();

		// Only documents of this order - the id comes from the URL.
		if ( ! isset( $known[ $document_id ] ) ) {
			wp_die( esc_html__( 'Document not found.', 'denar-for-woocommerce' ), 404 );
		}

		try {
			$pdf = Client::from_settings()->pdf( $document_id );
		} catch ( ApiException $e ) {
			wp_die( esc_html( $e->getMessage() ), 502 );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $known[ $document_id ] ) . '.pdf"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	/**
	 * Signed download URL.
	 *
	 * @param \WC_Order $order       Order.
	 * @param int       $document_id Document id.
	 */
	private static function pdf_url( \WC_Order $order, int $document_id ): string {
		$url = add_query_arg(
			array(
				'action'   => self::PDF_ACTION,
				'order'    => $order->get_id(),
				'document' => $document_id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::PDF_ACTION . '-' . $order->get_id() . '-' . $document_id );
	}

	/**
	 * Human name of a document type.
	 *
	 * @param string $type Denár document_type.
	 */
	private static function type_label( string $type ): string {
		return match ( $type ) {
			'proforma'    => __( 'Proforma', 'denar-for-woocommerce' ),
			'credit_note' => __( 'Credit note', 'denar-for-woocommerce' ),
			default       => __( 'Invoice', 'denar-for-woocommerce' ),
		};
	}

	/**
	 * Human status of a document.
	 *
	 * @param array $document Stored summary.
	 */
	private static function status_label( array $document ): string {
		if ( $document['is_paid'] ) {
			return __( 'Paid', 'denar-for-woocommerce' );
		}

		return match ( $document['status'] ) {
			'draft'     => __( 'Draft', 'denar-for-woocommerce' ),
			'cancelled' => __( 'Cancelled', 'denar-for-woocommerce' ),
			'overdue'   => __( 'Overdue', 'denar-for-woocommerce' ),
			default     => __( 'Issued', 'denar-for-woocommerce' ),
		};
	}
}
