<?php
/**
 * Order -> Denár document payload.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Pure mapping (no WordPress) from the plain order array collected by
 * OrderData to the body of POST /documents and POST /documents/{id}/credit-note.
 * Unit tested. Amounts come in as numbers or numeric strings and leave as
 * strings with two decimals, compared in whole cents. Denár computes VAT
 * itself, the shop only checks the result (see totals_match()).
 *
 * A shop with prices including VAT (`prices_include_tax`) sends the prices
 * with VAT (`prices_include_vat`, `unit_price_with_vat`, discount
 * `amount_with_vat`): Denár then works the base out of them, so the document
 * total is exactly the order total. Priced without VAT, both sides compute
 * VAT from the base and agree as well.
 */
final class PayloadBuilder {

	/**
	 * Countries whose documents are printed in Slovak / Czech; English elsewhere.
	 */
	private const LANGUAGES = array(
		'SK' => 'sk',
		'CZ' => 'cs',
	);

	/**
	 * WooCommerce gateway id -> Denár payment_method.
	 */
	private const PAYMENT_METHODS = array(
		'bacs'   => 'bank_transfer',
		'cod'    => 'cash',
		'cheque' => 'other',
	);

	/**
	 * Document for an order.
	 *
	 * @param array  $order         Plain order data, see OrderData::collect().
	 * @param string $document_type invoice|proforma.
	 * @param string $reference     external_reference of the document.
	 * @param array  $options       number_series, reverse_charge_regime.
	 */
	public static function document( array $order, string $document_type, string $reference, array $options = array() ): array {
		$with_vat = ! empty( $order['prices_include_tax'] );
		$key      = $with_vat ? 'gross' : 'net';
		$items    = array();
		$discount = self::cents( $order[ 'discount_' . $key ] ?? 0 );
		$reasons  = array_filter( array( (string) ( $order['discount_reason'] ?? '' ) ) );

		foreach ( $order['lines'] as $line ) {
			$amount = self::cents( $line[ $key ] ?? $line['net'] );

			// Negative fees are discounts; EN 16931 (BR-27) forbids negative lines.
			if ( $amount < 0 ) {
				$discount -= $amount;
				$reasons[] = (string) $line['label'];
				continue;
			}

			$items[] = self::item( $line, $with_vat );
		}

		$payload = array(
			'external_reference' => $reference,
			'document_type'      => $document_type,
			'partner'            => self::partner( $order ),
			'currency'           => strtoupper( (string) ( $order['currency'] ?? 'EUR' ) ),
			'language'           => self::LANGUAGES[ strtoupper( (string) ( $order['billing']['country'] ?? 'SK' ) ) ] ?? 'en',
			'payment_method'     => self::PAYMENT_METHODS[ (string) ( $order['payment_method'] ?? '' ) ] ?? 'card',
			'note'               => (string) ( $order['note'] ?? '' ),
			'items'              => $items,
		);

		if ( $with_vat ) {
			$payload['prices_include_vat'] = true;
		}

		$symbol = self::variable_symbol( (string) ( $order['order_number'] ?? '' ) );
		if ( null !== $symbol ) {
			// Same number the customer sees in the WooCommerce bank transfer
			// instructions, so Denár pairs the payment by variable symbol.
			$payload['variable_symbol'] = $symbol;
		}

		if ( $discount > 0 ) {
			$payload['discount'] = array(
				( $with_vat ? 'amount_with_vat' : 'amount' ) => self::money( $discount / 100 ),
				'reason' => implode( ', ', array_unique( $reasons ) ),
			);
		}

		$regime = self::vat_regime( (string) ( $order['vat_exempt_reason'] ?? '' ), $options );
		if ( null !== $regime ) {
			$payload['vat_regime'] = $regime;
		}

		if ( '' !== (string) ( $options['number_series'] ?? '' ) ) {
			$payload['number_series'] = (string) $options['number_series'];
		}

		return $payload;
	}

	/**
	 * Credit note body for a refund.
	 *
	 * @param array  $refund    Plain refund data: lines (as in orders), amount (gross, positive), fallback_rate, note, prices_include_tax.
	 * @param string $reference external_reference of the credit note.
	 */
	public static function credit_note( array $refund, string $reference ): array {
		$with_vat = ! empty( $refund['prices_include_tax'] );
		$items    = array();
		// WooCommerce stores refund lines negative; Denár wants them positive.
		foreach ( $refund['lines'] as $line ) {
			if ( 0 !== self::cents( $line['net'] ) ) {
				$items[] = self::item( $line, $with_vat );
			}
		}

		// Amount-only refund: one line for the refunded amount.
		if ( array() === $items ) {
			$rate  = (float) ( $refund['fallback_rate'] ?? 0 );
			$gross = (float) $refund['amount'];
			$item  = array(
				'label'    => (string) $refund['label'],
				'quantity' => 1,
				'vat_rate' => $rate,
			);

			if ( $with_vat ) {
				$item['unit_price_with_vat'] = self::money( $gross );
			} else {
				$item['unit_price'] = self::money( $gross / ( 1 + $rate / 100 ) );
			}

			$items[] = $item;
		}

		return array(
			'external_reference' => $reference,
			'note'               => (string) ( $refund['note'] ?? '' ),
			'issue'              => false,
			'items'              => $items,
		);
	}

	/**
	 * Whether Denár's total equals the shop's (both gross, same currency).
	 *
	 * @param string|float $denar Total from the API (`totals.with_vat`), may be negative for a credit note.
	 * @param string|float $shop  Order or refund total.
	 */
	public static function totals_match( $denar, $shop ): bool {
		return abs( self::cents( $denar ) ) === abs( self::cents( $shop ) );
	}

	/**
	 * Splits "Dlhá 5" / "Hlavná 12/A" into street and number; an address
	 * without a trailing number stays whole.
	 *
	 * @param string $address Address line.
	 * @return array{0: string, 1: string}
	 */
	public static function split_street( string $address ): array {
		$address = trim( preg_replace( '/\s+/u', ' ', $address ) ?? '' );

		if ( preg_match( '/^(.*\S)\s+(\d[\w\/\-]*)$/u', $address, $m ) ) {
			return array( $m[1], $m[2] );
		}

		return array( $address, '' );
	}

	/**
	 * Variable symbol from the order number: digits only, at most 10.
	 *
	 * @param string $order_number Order number as shown to the customer.
	 */
	public static function variable_symbol( string $order_number ): ?string {
		$digits = preg_replace( '/\D+/', '', $order_number ) ?? '';

		return '' !== $digits && strlen( $digits ) <= 10 ? $digits : null;
	}

	/**
	 * Money in whole cents - no float comparisons, no bcmath dependency
	 * (not every shared host has it).
	 *
	 * @param string|float|int $value Amount.
	 */
	public static function cents( $value ): int {
		return (int) round( (float) $value * 100 );
	}

	/**
	 * Money as a two decimal string.
	 *
	 * @param string|float|int $value Amount.
	 */
	public static function money( $value ): string {
		return number_format( round( (float) $value, 2 ), 2, '.', '' );
	}

	/**
	 * One document line.
	 *
	 * @param array $line     label, quantity, net / gross (line total without / with VAT), rate.
	 * @param bool  $with_vat Send the price with VAT.
	 */
	private static function item( array $line, bool $with_vat = false ): array {
		$quantity = (float) ( $line['quantity'] ?? 1 );
		$quantity = 0.0 === $quantity ? 1.0 : abs( $quantity );

		$item = array(
			'label'    => mb_substr( (string) $line['label'], 0, 255 ),
			'quantity' => $quantity,
		);

		if ( $with_vat ) {
			$item['unit_price_with_vat'] = self::money( abs( (float) ( $line['gross'] ?? $line['net'] ) ) / $quantity );
		} else {
			$item['unit_price'] = self::money( abs( (float) $line['net'] ) / $quantity );
		}

		$item['vat_rate'] = (float) ( $line['rate'] ?? 0 );

		if ( '' !== (string) ( $line['description'] ?? '' ) ) {
			$item['description'] = (string) $line['description'];
		}

		return $item;
	}

	/**
	 * Partner block; Denár matches it by reference, IČO, VAT id and e-mail.
	 *
	 * @param array $order Plain order data.
	 */
	private static function partner( array $order ): array {
		$billing = $order['billing'];
		$person  = trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) );
		$company = trim( (string) ( $billing['company'] ?? '' ) );

		list( $street, $number ) = self::split_street( trim( ( $billing['address_1'] ?? '' ) . ' ' . ( $billing['address_2'] ?? '' ) ) );

		$partner = array(
			'name'          => '' !== $company ? $company : $person,
			'street'        => $street,
			'street_number' => $number,
			'city'          => (string) ( $billing['city'] ?? '' ),
			'zip'           => (string) ( $billing['postcode'] ?? '' ),
			'country_code'  => strtoupper( (string) ( $billing['country'] ?? '' ) ),
			'email'         => (string) ( $billing['email'] ?? '' ),
			'phone'         => (string) ( $billing['phone'] ?? '' ),
			'ico'           => (string) ( $billing['ico'] ?? '' ),
			'dic'           => (string) ( $billing['dic'] ?? '' ),
			'ic_dph'        => (string) ( $billing['ic_dph'] ?? '' ),
		);

		// A registered customer is one partner across orders; a guest is
		// matched by IČO or e-mail on the Denár side.
		if ( ! empty( $order['customer_reference'] ) ) {
			$partner['external_reference'] = (string) $order['customer_reference'];
		}

		return array_filter( $partner, static fn ( $value ) => '' !== $value );
	}

	/**
	 * Denár VAT regime from the reason the shop did not charge VAT
	 * (px-shop-core company module: `_px_vat_exempt_reason`).
	 *
	 * @param string $reason  reverse_charge|export|''.
	 * @param array  $options reverse_charge_regime: eu_goods (default) or eu_service.
	 */
	private static function vat_regime( string $reason, array $options ): ?string {
		return match ( $reason ) {
			'reverse_charge' => (string) ( $options['reverse_charge_regime'] ?? 'eu_goods' ),
			'export'         => 'export',
			default          => null,
		};
	}
}
