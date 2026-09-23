<?php
/**
 * Everything the GitHub distribution needs and wordpress.org forbids.
 *
 * This file and lib/ are left out of the wordpress.org build (bin/build.sh
 * wporg): there WordPress itself delivers updates and translations come from
 * translate.wordpress.org. Here the plugin updates from GitHub releases - a
 * tag builds a zip in CI, Plugin Update Checker offers it in Dashboard ->
 * Updates - and loads its bundled translation. See RELEASING.md.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined( 'ABSPATH' ) || exit;

/**
 * GitHub distribution: bundled translation and Plugin Update Checker.
 */
final class SelfHosted {

	public const REPOSITORY = 'https://github.com/pixeler-sk/denar-for-woocommerce/';

	public const SLUG = 'denar-for-woocommerce';

	/**
	 * Icon and banner, same files and names as on wordpress.org
	 * (rendered by assets-src/render.sh). Served from the main branch, so a
	 * new picture needs no release.
	 */
	private const ASSETS = 'https://raw.githubusercontent.com/pixeler-sk/denar-for-woocommerce/main/.wordpress-org/';

	/**
	 * Called from the main file, before plugins_loaded.
	 */
	public static function boot(): void {
		add_action( 'plugins_loaded', array( self::class, 'load_textdomain' ), 5 );
		// Outside the WooCommerce check on purpose: the plugin must stay
		// updatable even while WooCommerce is deactivated.
		add_action( 'init', array( self::class, 'register_updater' ) );
	}

	/**
	 * Bundled translation; on wordpress.org the language pack replaces it.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'denar-for-woocommerce', false, dirname( plugin_basename( DENAR_WC_FILE ) ) . '/languages' );
	}

	/**
	 * Wires Plugin Update Checker.
	 */
	public static function register_updater(): void {
		// Updates are checked only in admin, cron and WP-CLI.
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		require_once DENAR_WC_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

		$checker = PucFactory::buildUpdateChecker( self::REPOSITORY, DENAR_WC_FILE, self::SLUG );

		// Shared hosting burns GitHub's anonymous limit (60 req/h per IP). A
		// fine-grained read-only token in the server's wp-config lifts it,
		// constant DENAR_WC_GITHUB_TOKEN.
		if ( defined( 'DENAR_WC_GITHUB_TOKEN' ) && is_string( DENAR_WC_GITHUB_TOKEN ) && '' !== trim( DENAR_WC_GITHUB_TOKEN ) ) {
			$checker->setAuthentication( trim( DENAR_WC_GITHUB_TOKEN ) );
		}

		// Constant read from the instance: the Api class lives in a
		// version-named namespace (v5p7), a full `use` would break on upgrade.
		// Only the CI-built zip is accepted, never the raw source archive.
		$api = $checker->getVcsApi();
		$api->enableReleaseAssets( '/^denar-for-woocommerce-\d+\.\d+\.\d+\.zip$/', $api::REQUIRE_RELEASE_ASSETS );

		// Icon in Dashboard -> Updates, banner in "View details".
		$checker->addResultFilter(
			static function ( $info ) {
				$info->icons   = array(
					'1x'  => self::ASSETS . 'icon-128x128.png',
					'2x'  => self::ASSETS . 'icon-256x256.png',
					'svg' => self::ASSETS . 'icon.svg',
				);
				$info->banners = array(
					'low'  => self::ASSETS . 'banner-772x250.png',
					'high' => self::ASSETS . 'banner-1544x500.png',
				);

				return $info;
			}
		);
	}
}
