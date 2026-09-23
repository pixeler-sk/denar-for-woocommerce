<?php
/**
 * Reads what an invoice needs from a WooCommerce order.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce side of the mapping: turns WC_Order / WC_Order_Refund into the
 * plain arrays PayloadBuilder understands. Lines go in before coupons (item
 * subtotal) and the coupons as one document discount - the way Denár and
 * EN 16931 model it.
 */
final class OrderData {

	/**
	 * Order meta with IČO, DIČ and IČ DPH. The keys WPify Woo, px-shop-core
	 * and most Slovak invoicing plugins share; filterable for other checkouts.
	 */
	private const COMPANY_META = array(
		'ico'    => '_billing_ic',
		'dic'    => '_billing_dic',
		'ic_dph' => '_billing_dic_dph',
	);

	/**
	 * Plain order data for PayloadBuilder::document().
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $site_key Stable key of this shop, see OrderSync::site_key().
	 */
	public static function collect( \WC_Order $order, string $site_key ): array {
		$lines = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** Product line. @var \WC_Order_Item_Product $item */
			$lines[] = array(
				'label'    => $item->get_name(),
				'quantity' => (float) $item->get_quantity(),
				'net'      => (float) $item->get_subtotal(),
				'rate'     => self::rate( $item->get_taxes()['subtotal'] ?? array(), (float) $item->get_subtotal(), (float) $item->get_subtotal_tax() ),
			);
		}

		foreach ( $order->get_items( array( 'shipping', 'fee' ) ) as $item ) {
			/** Shipping or fee line. @var \WC_Order_Item_Shipping|\WC_Order_Item_Fee $item */
			$lines[] = array(
				'label'    => $item->get_name(),
				'quantity' => 1.0,
				'net'      => (float) $item->get_total(),
				'rate'     => self::rate( $item->get_taxes()['total'] ?? array(), (float) $item->get_total(), (float) $item->get_total_tax() ),
			);
		}

		$meta = (array) apply_filters( 'denar_wc_company_meta_keys', self::COMPANY_META, $order );

		$data = array(
			'order_id'           => $order->get_id(),
			'order_number'       => (string) $order->get_order_number(),
			'currency'           => $order->get_currency(),
			'payment_method'     => $order->get_payment_method(),
			'total'              => (float) $order->get_total(),
			/* translators: %s: order number */
			'note'               => sprintf( __( 'Order no. %s', 'denar-for-woocommerce' ), $order->get_order_number() ),
			'customer_reference' => $order->get_customer_id() ? 'woo:' . $site_key . ':c' . $order->get_customer_id() : '',
			'billing'            => array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'company'    => $order->get_billing_company(),
				'address_1'  => $order->get_billing_address_1(),
				'address_2'  => $order->get_billing_address_2(),
				'city'       => $order->get_billing_city(),
				'postcode'   => $order->get_billing_postcode(),
				'country'    => $order->get_billing_country(),
				'email'      => $order->get_billing_email(),
				'phone'      => $order->get_billing_phone(),
				'ico'        => isset( $meta['ico'] ) ? (string) $order->get_meta( $meta['ico'] ) : '',
				'dic'        => isset( $meta['dic'] ) ? (string) $order->get_meta( $meta['dic'] ) : '',
				'ic_dph'     => isset( $meta['ic_dph'] ) ? (string) $order->get_meta( $meta['ic_dph'] ) : '',
			),
			'lines'              => $lines,
			'discount_net'       => (float) $order->get_discount_total(),
			'discount_reason'    => implode( ', ', $order->get_coupon_codes() ),
			// px-shop-core company module: why VAT was not charged.
			'vat_exempt_reason'  => (string) $order->get_meta( '_px_vat_exempt_reason' ),
		);

		/**
		 * Filters the order data before it is mapped to a Denár document.
		 *
		 * @param array     $data  Plain order data.
		 * @param \WC_Order $order Order.
		 */
		return (array) apply_filters( 'denar_wc_order_data', $data, $order );
	}

	/**
	 * Plain refund data for PayloadBuilder::credit_note().
	 *
	 * @param \WC_Order_Refund $refund Refund.
	 * @param \WC_Order        $order  Refunded order.
	 */
	public static function refund( \WC_Order_Refund $refund, \WC_Order $order ): array {
		$lines = array();

		foreach ( $refund->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
			/** Refunded line. @var \WC_Order_Item_Product|\WC_Order_Item_Shipping|\WC_Order_Item_Fee $item */
			$lines[] = array(
				'label'    => $item->get_name(),
				'quantity' => $item instanceof \WC_Order_Item_Product ? (float) $item->get_quantity() : 1.0,
				'net'      => (float) $item->get_total(),
				'rate'     => self::rate( $item->get_taxes()['total'] ?? array(), (float) $item->get_total(), (float) $item->get_total_tax() ),
			);
		}

		return array(
			'lines'         => $lines,
			'amount'        => (float) $refund->get_amount(),
			'fallback_rate' => self::main_rate( $order ),
			/* translators: %s: order number */
			'label'         => sprintf( __( 'Refund for order no. %s', 'denar-for-woocommerce' ), $order->get_order_number() ),
			'note'          => (string) $refund->get_reason(),
		);
	}

	/**
	 * VAT rate (percent) of a line from its tax rate ids; without tax data it
	 * is derived from the amounts, 0 when no tax was charged.
	 *
	 * @param array $taxes Rate id => tax amount.
	 * @param float $net   Line net.
	 * @param float $tax   Line tax.
	 */
	private static function rate( array $taxes, float $net, float $tax ): float {
		foreach ( $taxes as $rate_id => $amount ) {
			if ( '' !== (string) $amount && 0.0 !== (float) $amount ) {
				return (float) \WC_Tax::get_rate_percent_value( (int) $rate_id );
			}
		}

		if ( 0.0 === $tax || 0.0 === $net ) {
			return 0.0;
		}

		return round( abs( $tax / $net ) * 100 );
	}

	/**
	 * Rate carrying the most net amount on the order - used for a refund of
	 * an amount without lines.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function main_rate( \WC_Order $order ): float {
		$by_rate = array();
		foreach ( self::collect( $order, '' )['lines'] as $line ) {
			$key             = (string) $line['rate'];
			$by_rate[ $key ] = ( $by_rate[ $key ] ?? 0 ) + abs( (float) $line['net'] );
		}

		if ( array() === $by_rate ) {
			return 0.0;
		}

		arsort( $by_rate );

		return (float) array_key_first( $by_rate );
	}
}
