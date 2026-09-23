<?php
/**
 * WooCommerce -> Settings -> Denár.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce\Admin;

use Denar\WooCommerce\Api\ApiException;
use Denar\WooCommerce\Api\Client;
use Denar\WooCommerce\Plugin;
use Denar\WooCommerce\Settings;
use Denar\WooCommerce\Webhook\Receiver;

defined( 'ABSPATH' ) || exit;

/**
 * Settings tab: connection (API URL, key, webhook secret) and a
 * "Check connection" button calling GET /me. Secrets are never printed back
 * into the form; an empty field keeps the stored value.
 */
final class SettingsPage {

	private const TAB = 'denar';

	private const CHECK_ACTION = 'denar_wc_check_connection';

	/**
	 * Scopes the plugin needs for the order flow.
	 */
	private const REQUIRED_SCOPES = array( 'documents:read', 'documents:write', 'partners:read', 'partners:write' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_settings_tabs_array', array( self::class, 'add_tab' ), 50 );
		add_action( 'woocommerce_settings_tabs_' . self::TAB, array( self::class, 'output' ) );
		add_action( 'woocommerce_update_options_' . self::TAB, array( self::class, 'save' ) );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( self::class, 'check_connection' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DENAR_WC_FILE ), array( self::class, 'action_links' ) );

		foreach ( array( Settings::OPTION_API_KEY, Settings::OPTION_WEBHOOK_SECRET ) as $option ) {
			add_filter( 'woocommerce_admin_settings_sanitize_option_' . $option, array( self::class, 'keep_secret' ), 10, 2 );
		}
	}

	/**
	 * Adds the tab.
	 *
	 * @param array $tabs Tabs.
	 */
	public static function add_tab( array $tabs ): array {
		$tabs[ self::TAB ] = __( 'Denár', 'denar-for-woocommerce' );

		return $tabs;
	}

	/**
	 * "Settings" link in the plugin list.
	 *
	 * @param array $links Links.
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'denar-for-woocommerce' ) ) );

		return $links;
	}

	/**
	 * Settings screen URL.
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=' . self::TAB );
	}

	/**
	 * Renders the tab.
	 */
	public static function output(): void {
		self::render_status();
		\WC_Admin_Settings::output_fields( self::fields() );
	}

	/**
	 * Saves the tab.
	 */
	public static function save(): void {
		$before = Settings::api_url() . '|' . Settings::api_key();

		\WC_Admin_Settings::save_fields( self::fields() );

		// Another key or URL makes the last check meaningless.
		if ( Settings::api_url() . '|' . Settings::api_key() !== $before ) {
			delete_option( Settings::OPTION_CONNECTION );
		}
	}

	/**
	 * Empty secret field = keep the stored value.
	 *
	 * @param mixed $value  Sanitized value.
	 * @param array $option Field definition.
	 */
	public static function keep_secret( $value, array $option ) {
		$value = trim( (string) $value );

		return '' === $value ? (string) get_option( $option['id'], '' ) : $value;
	}

	/**
	 * Calls GET /me and stores the outcome; admin-post handler.
	 */
	public static function check_connection(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'denar-for-woocommerce' ), 403 );
		}

		check_admin_referer( self::CHECK_ACTION );

		try {
			$me     = Client::from_settings()->me();
			$result = array(
				'ok'           => true,
				'checked_at'   => time(),
				'organization' => $me['organization'] ?? array(),
				'scopes'       => $me['key']['scopes'] ?? array(),
			);
		} catch ( ApiException $e ) {
			$result = array(
				'ok'         => false,
				'checked_at' => time(),
				'error'      => $e->getMessage(),
			);
			Plugin::log( 'warning', 'Connection check failed: ' . $e->getMessage(), array( 'status' => $e->status ) );
		}

		update_option( Settings::OPTION_CONNECTION, $result, false );

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Connection status box above the fields.
	 */
	private static function render_status(): void {
		$connection = Settings::connection();

		echo '<h2>' . esc_html__( 'Connection to Denár', 'denar-for-woocommerce' ) . '</h2>';

		if ( null === $connection ) {
			echo '<p>' . esc_html__( 'Not checked yet. Save the API key and check the connection.', 'denar-for-woocommerce' ) . '</p>';
		} elseif ( $connection['ok'] ) {
			$org     = $connection['organization'] ?? array();
			$missing = array_diff( self::REQUIRED_SCOPES, (array) ( $connection['scopes'] ?? array() ) );

			echo '<div class="notice notice-success inline"><p>';
			printf(
				/* translators: 1: organization name, 2: IČO, 3: VAT payer yes/no */
				esc_html__( 'Connected to %1$s (IČO %2$s, VAT payer: %3$s).', 'denar-for-woocommerce' ),
				'<strong>' . esc_html( (string) ( $org['name'] ?? '' ) ) . '</strong>',
				esc_html( (string) ( $org['ico'] ?? '-' ) ),
				! empty( $org['is_vat_payer'] ) ? esc_html__( 'yes', 'denar-for-woocommerce' ) : esc_html__( 'no', 'denar-for-woocommerce' )
			);
			echo '</p></div>';

			if ( array() !== $missing ) {
				echo '<div class="notice notice-warning inline"><p>';
				printf(
					/* translators: %s: list of scopes */
					esc_html__( 'The key is missing permissions: %s. Create a new key in Denár with them.', 'denar-for-woocommerce' ),
					'<code>' . esc_html( implode( ', ', $missing ) ) . '</code>'
				);
				echo '</p></div>';
			}
		} else {
			echo '<div class="notice notice-error inline"><p>';
			printf(
				/* translators: %s: error message from the API */
				esc_html__( 'Connection failed: %s', 'denar-for-woocommerce' ),
				esc_html( (string) ( $connection['error'] ?? '' ) )
			);
			echo '</p></div>';
		}

		if ( null !== $connection ) {
			echo '<p class="description">';
			printf(
				/* translators: %s: date and time */
				esc_html__( 'Checked: %s', 'denar-for-woocommerce' ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $connection['checked_at'] ) )
			);
			echo '</p>';
		}

		if ( '' !== Settings::api_key() ) {
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::CHECK_ACTION ), self::CHECK_ACTION ) ),
				esc_html__( 'Check connection', 'denar-for-woocommerce' )
			);
		}
	}

	/**
	 * Field definitions.
	 */
	private static function fields(): array {
		$stored = __( 'Stored - leave empty to keep it.', 'denar-for-woocommerce' );
		$pinned = __( 'Set in wp-config.php.', 'denar-for-woocommerce' );

		return array(
			array(
				'type' => 'title',
				'id'   => 'denar_wc_api',
				'desc' => __( 'Create the API key in Denár under Settings -> API keys with the scopes documents and partners (read and write).', 'denar-for-woocommerce' ),
			),
			array(
				'id'                => Settings::OPTION_API_URL,
				'title'             => __( 'API URL', 'denar-for-woocommerce' ),
				'type'              => 'url',
				'default'           => '',
				'placeholder'       => Settings::DEFAULT_API_URL,
				'desc_tip'          => __( 'Leave empty for the production Denár. Change only for staging or local development.', 'denar-for-woocommerce' ),
				'custom_attributes' => Settings::is_pinned( 'DENAR_WC_API_URL' ) ? array( 'disabled' => 'disabled' ) : array(),
			),
			array(
				'id'                => Settings::OPTION_API_KEY,
				'title'             => __( 'API key', 'denar-for-woocommerce' ),
				'type'              => 'password',
				'value'             => '',
				'placeholder'       => Settings::is_pinned( 'DENAR_WC_API_KEY' ) ? $pinned : ( '' !== Settings::api_key() ? $stored : 'denar_...' ),
				'custom_attributes' => array_merge(
					array( 'autocomplete' => 'new-password' ),
					Settings::is_pinned( 'DENAR_WC_API_KEY' ) ? array( 'disabled' => 'disabled' ) : array()
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'denar_wc_api',
			),
			array(
				'type'  => 'title',
				'id'    => 'denar_wc_webhook',
				'title' => __( 'Webhook', 'denar-for-woocommerce' ),
				'desc'  => sprintf(
					/* translators: %s: webhook URL */
					__( 'In Denár under Settings -> Webhooks add the URL %s with the events document.paid and document.cancelled, then paste its secret here.', 'denar-for-woocommerce' ),
					'<code>' . esc_html( Receiver::url() ) . '</code>'
				),
			),
			array(
				'id'                => Settings::OPTION_WEBHOOK_SECRET,
				'title'             => __( 'Webhook secret', 'denar-for-woocommerce' ),
				'type'              => 'password',
				'value'             => '',
				'placeholder'       => Settings::is_pinned( 'DENAR_WC_WEBHOOK_SECRET' ) ? $pinned : ( '' !== Settings::webhook_secret() ? $stored : 'whsec_...' ),
				'custom_attributes' => array_merge(
					array( 'autocomplete' => 'new-password' ),
					Settings::is_pinned( 'DENAR_WC_WEBHOOK_SECRET' ) ? array( 'disabled' => 'disabled' ) : array()
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'denar_wc_webhook',
			),
		);
	}
}
