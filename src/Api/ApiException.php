<?php
/**
 * Failed API call.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the HTTP status and the machine code from the Denár error body
 * (`{"message": "...", "code": "not_found", "errors": {...}}`).
 */
final class ApiException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message    Human readable message (Slovak from the API).
	 * @param int    $status     HTTP status, 0 for a transport error.
	 * @param string $error_code Machine code, e.g. insufficient_scope, document_locked.
	 * @param array  $errors     Validation errors of a 422 response.
	 */
	public function __construct(
		string $message,
		public readonly int $status = 0,
		public readonly string $error_code = '',
		public readonly array $errors = array()
	) {
		parent::__construct( $message, $status );
	}

	/**
	 * Transport errors, 429 and 5xx are worth another attempt; 4xx are not.
	 */
	public function is_retryable(): bool {
		return 0 === $this->status || 429 === $this->status || $this->status >= 500;
	}
}
