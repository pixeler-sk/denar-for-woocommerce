<?php
/**
 * Denár REST API v1 client.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce\Api;

use Denar\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

// Exception messages are data, escaped where they are printed.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Thin JSON client over wp_remote_request. Contract:
 * https://api.denar.sk/v1/openapi.yaml. The key never leaves this class -
 * not into exceptions, not into logs.
 */
final class Client {

	private const TIMEOUT = 15;

	/**
	 * Constructor.
	 *
	 * @param string $base_url Base URL including /v1, without a trailing slash.
	 * @param string $api_key  Organization API key (denar_...).
	 */
	public function __construct(
		private readonly string $base_url,
		private readonly string $api_key
	) {}

	/**
	 * Client from the stored settings.
	 */
	public static function from_settings(): self {
		return new self( Settings::api_url(), Settings::api_key() );
	}

	/**
	 * Organization and scopes of the key.
	 *
	 * @return array{organization: array, key: array}
	 */
	public function me(): array {
		return $this->request( 'GET', '/me' )['data'] ?? array();
	}

	/**
	 * GET request.
	 *
	 * @param string $path  Path starting with a slash.
	 * @param array  $query Query parameters.
	 */
	public function get( string $path, array $query = array() ): array {
		return $this->request( 'GET', $path, $query );
	}

	/**
	 * POST request with a JSON body.
	 *
	 * @param string $path Path starting with a slash.
	 * @param array  $body Payload.
	 */
	public function post( string $path, array $body = array() ): array {
		return $this->request( 'POST', $path, array(), $body );
	}

	/**
	 * PATCH request with a JSON body.
	 *
	 * @param string $path Path starting with a slash.
	 * @param array  $body Payload.
	 */
	public function patch( string $path, array $body ): array {
		return $this->request( 'PATCH', $path, array(), $body );
	}

	/**
	 * DELETE request.
	 *
	 * @param string $path Path starting with a slash.
	 */
	public function delete( string $path ): void {
		$this->request( 'DELETE', $path );
	}

	/**
	 * Document by its external_reference, or null.
	 *
	 * @param string $reference External reference.
	 */
	public function find_document( string $reference ): ?array {
		$found = $this->get( '/documents', array( 'external_reference' => $reference ) );

		return $found['data'][0] ?? null;
	}

	/**
	 * PDF of a document (binary body).
	 *
	 * @param int $id Document id.
	 *
	 * @throws ApiException On a transport error or a non-2xx status.
	 */
	public function pdf( int $id ): string {
		return $this->request( 'GET', '/documents/' . $id . '/pdf', array(), null, true )['body'];
	}

	/**
	 * Sends the request and decodes the JSON answer.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path starting with a slash.
	 * @param array      $query  Query parameters.
	 * @param array|null $body   JSON payload.
	 * @param bool       $raw    Return the raw body as ['body' => ...] instead of decoding JSON.
	 *
	 * @throws ApiException On a transport error or a non-2xx status.
	 */
	private function request( string $method, string $path, array $query = array(), ?array $body = null, bool $raw = false ): array {
		if ( '' === $this->api_key ) {
			throw new ApiException( __( 'The Denár API key is not set.', 'denar-for-woocommerce' ), 401, 'missing_key' );
		}

		$url = $this->base_url . $path;
		if ( array() !== $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => $raw ? 'application/pdf, application/json' : 'application/json',
				'User-Agent'    => 'denar-for-woocommerce/' . DENAR_WC_VERSION . '; ' . home_url( '/' ),
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new ApiException( $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $raw && $status >= 200 && $status < 300 ) {
			return array( 'body' => (string) wp_remote_retrieve_body( $response ) );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $status < 200 || $status >= 300 ) {
			throw new ApiException(
				(string) ( $decoded['message'] ?? sprintf( 'HTTP %d', $status ) ),
				$status,
				(string) ( $decoded['code'] ?? '' ),
				is_array( $decoded['errors'] ?? null ) ? $decoded['errors'] : array()
			);
		}

		return $decoded;
	}
}
