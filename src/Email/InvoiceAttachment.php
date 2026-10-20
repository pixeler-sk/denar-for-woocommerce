<?php
/**
 * Invoice PDF from Denár attached to customer e-mails.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce\Email;

use Denar\WooCommerce\Api\ApiException;
use Denar\WooCommerce\Api\Client;
use Denar\WooCommerce\Plugin;
use Denar\WooCommerce\Settings;
use Denar\WooCommerce\Sync\OrderState;
use Denar\WooCommerce\Sync\OrderSync;

defined( 'ABSPATH' ) || exit;

/**
 * The customer e-mails chosen in the settings (the completed order by
 * default, and always the invoice sent by hand from the order screen) carry
 * the invoice PDF from Denár. When the order is at one of its invoice
 * statuses but the queue has not issued the invoice yet - a status changed by
 * hand fires the e-mail in the same request - it is created here
 * synchronously; the queued task then finds it and stops. That happens only
 * in the admin, cron or CLI, never in the checkout or a gateway callback.
 * Any failure only leaves the e-mail without the attachment: the e-mail
 * itself always goes out.
 */
final class InvoiceAttachment {

	/**
	 * Sent by hand from the order screen - always with the invoice.
	 */
	private const MANUAL_EMAIL = 'customer_invoice';

	/**
	 * Files written for the current request, removed on shutdown.
	 *
	 * @var string[]
	 */
	private static array $files = array();

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_email_attachments', array( self::class, 'attach' ), 10, 3 );
	}

	/**
	 * Filter woocommerce_email_attachments.
	 *
	 * @param mixed  $attachments  Attachments so far.
	 * @param string $email_id     E-mail id.
	 * @param mixed  $email_object E-mail object (an order for order e-mails).
	 */
	public static function attach( $attachments, $email_id, $email_object = null ) {
		$attachments = is_array( $attachments ) ? $attachments : array();
		$role        = self::role_for( (string) $email_id, Settings::attach_emails() );

		if ( null === $role || ! $email_object instanceof \WC_Order || $email_object instanceof \WC_Order_Refund
			|| ! Settings::enabled() ) {
			return $attachments;
		}

		$document = self::document( $email_object, $role );
		if ( null === $document ) {
			return $attachments;
		}

		try {
			$file = self::write( $document, Client::from_settings()->pdf( $document['id'] ) );
		} catch ( ApiException $e ) {
			Plugin::log( 'warning', sprintf( 'Order %d: PDF %s not attached - %s', $email_object->get_id(), $document['number'], $e->getMessage() ) );

			return $attachments;
		}

		if ( null !== $file ) {
			$attachments[] = $file;
		}

		return $attachments;
	}

	/**
	 * Document role attached to an e-mail, or null.
	 *
	 * @param string   $email_id E-mail id.
	 * @param string[] $emails   E-mail ids chosen in the settings.
	 */
	public static function role_for( string $email_id, array $emails ): ?string {
		return self::MANUAL_EMAIL === $email_id || in_array( $email_id, $emails, true ) ? 'invoice' : null;
	}

	/**
	 * Attachment file name, e.g. "Faktura-E2-20260003.pdf".
	 *
	 * @param string $type   Document type from the API.
	 * @param string $number Document number.
	 */
	public static function file_name( string $type, string $number ): string {
		$label = 'credit_note' === $type ? 'Dobropis' : ( in_array( $type, array( 'proforma', 'advance' ), true ) ? 'Zalohova-faktura' : 'Faktura' );
		$safe  = trim( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '-', $number ), '-' );

		return $label . ( '' !== $safe ? '-' . $safe : '' ) . '.pdf';
	}

	/**
	 * Issued document of the role; the invoice of an order at one of its
	 * invoice statuses is created first when the queue has not done it yet.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $role  Role.
	 */
	private static function document( \WC_Order $order, string $role ): ?array {
		$document = OrderState::get( $order, $role );

		if ( 'invoice' === $role && ( null === $document || '' === $document['number'] )
			&& OrderSync::invoices_at( $order, (string) $order->get_status() ) && self::may_call_api_now() ) {
			OrderSync::process( $order->get_id(), 'invoice' );
			$fresh    = wc_get_order( $order->get_id() );
			$document = $fresh instanceof \WC_Order ? OrderState::get( $fresh, $role ) : null;
		}

		// A draft (total mismatch) has no number and no final PDF yet.
		return null !== $document && $document['id'] > 0 && '' !== $document['number'] ? $document : null;
	}

	/**
	 * Outside the checkout and gateway callbacks: the admin (status changed
	 * by hand, also over admin-ajax), cron or WP-CLI. Not REST - the block
	 * checkout runs over the Store API.
	 */
	private static function may_call_api_now(): bool {
		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Writes the PDF to a temporary file named after the document.
	 *
	 * @param array  $document Document summary.
	 * @param string $pdf      PDF bytes.
	 */
	private static function write( array $document, string $pdf ): ?string {
		if ( ! str_starts_with( $pdf, '%PDF' ) ) {
			return null;
		}

		$dir = trailingslashit( get_temp_dir() ) . 'denar-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		$file = $dir . '/' . self::file_name( $document['type'], $document['number'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temporary file for wp_mail, removed on shutdown.
		if ( false === file_put_contents( $file, $pdf ) ) {
			return null;
		}

		if ( array() === self::$files ) {
			add_action( 'shutdown', array( self::class, 'cleanup' ) );
		}
		self::$files[] = $file;

		return $file;
	}

	/**
	 * Removes the temporary files.
	 */
	public static function cleanup(): void {
		foreach ( self::$files as $file ) {
			wp_delete_file( $file );
			$dir = dirname( $file );
			if ( is_dir( $dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- the empty temporary directory.
				rmdir( $dir );
			}
		}
		self::$files = array();
	}
}
