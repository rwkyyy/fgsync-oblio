<?php
/**
 * Base Oblio API exception.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Api\Exception;

use RuntimeException;
class ApiException extends RuntimeException {

	protected string $status_message;

	public function __construct( string $message, int $http_status = 0, string $status_message = '', ?\Throwable $previous = null ) {
		parent::__construct( $message, $http_status, $previous );
		$this->status_message = '' !== $status_message ? $status_message : $message;
	}

	public function status_message(): string {
		return $this->status_message;
	}

	public function http_status(): int {
		return (int) $this->getCode();
	}

	public function is_retryable(): bool {
		$code = $this->http_status();
		return 0 === $code || 408 === $code || 429 === $code || $code >= 500;
	}
}
