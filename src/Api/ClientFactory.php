<?php
/**
 * Builds configured OblioClient instances from stored settings.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Api;

use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
final class ClientFactory {

	private Settings $settings;

	private Encryption $encryption;

	private Logger $logger;

	private ConnectionHealth $health;

	private TokenStore $tokens;

	private RateLimiter $rate_limiter;

	public function __construct( Settings $settings, Encryption $encryption, Logger $logger, ConnectionHealth $health ) {
		$this->settings     = $settings;
		$this->encryption   = $encryption;
		$this->logger       = $logger;
		$this->health       = $health;
		$this->tokens       = new TokenStore( $encryption );
		$this->rate_limiter = new RateLimiter();
	}

	public function register(): void {
		add_action( 'update_option_' . $this->settings->option_name( 'email' ), array( $this->tokens, 'clear' ) );
		add_action( 'update_option_' . $this->settings->option_name( 'cif' ), array( $this->tokens, 'clear' ) );
	}

	public function create(): OblioClient {
		return new OblioClient(
			(string) $this->settings->get( 'email' ),
			$this->get_secret(),
			$this->tokens,
			$this->logger,
			$this->rate_limiter,
			$this->health
		);
	}

	public function create_with( string $email, string $secret ): OblioClient {

		return new OblioClient( $email, $secret, new TokenStore( $this->encryption, false ), $this->logger, $this->rate_limiter, $this->health );
	}

	public function tokens(): TokenStore {
		return $this->tokens;
	}

	public function get_secret(): string {
		return $this->encryption->decrypt( (string) $this->settings->get( 'secret' ) );
	}

	public function set_secret( string $plaintext ): void {
		if ( '' === $plaintext ) {
			return;
		}
		$this->settings->set( 'secret', $this->encryption->encrypt( $plaintext ) );
		$this->tokens->clear();
	}

	public function has_secret(): bool {
		return '' !== (string) $this->settings->get( 'secret' );
	}
}
