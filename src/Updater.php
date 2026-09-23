<?php
/**
 * Automatic updates from GitHub releases.
 *
 * Same scheme as px-shop-core: the public repository
 * https://github.com/pixeler-sk/denar-for-woocommerce, a tag builds a zip in
 * CI and attaches it to a release, Plugin Update Checker offers it in
 * Dashboard -> Updates. Only the CI-built zip is accepted
 * (REQUIRE_RELEASE_ASSETS), never the raw source archive. See RELEASING.md.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin Update Checker wiring.
 */
final class Updater {

	public const REPOSITORY = 'https://github.com/pixeler-sk/denar-for-woocommerce/';

	public const SLUG = 'denar-for-woocommerce';

	/**
	 * Icon and banner, same files and names as on wordpress.org
	 * (rendered by assets-src/render.sh). Served from the main branch, so a
	 * new picture needs no release.
	 */
	private const ASSETS = 'https://raw.githubusercontent.com/pixeler-sk/denar-for-woocommerce/main/.wordpress-org/';

	/**
	 * Hooked on init.
	 */
	public static function register(): void {
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
