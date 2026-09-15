<?php
/**
 * Oblio REST API client (WordPress HTTP API).
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Api;

use OblioWoo\Api\Exception\ApiException;
use OblioWoo\Api\Exception\AuthException;
use OblioWoo\Support\Logger;
use OblioWoo\Support\RateLimiter;
final class OblioClient {

	private const BASE_URL = 'https://www.oblio.eu';
	private const TIMEOUT  = 30;

	private string $email;

	private string $secret;

	private TokenStore $tokens;

	private Logger $logger;

	private RateLimiter $rate_limiter;

	public function __construct( string $email, string $secret, TokenStore $tokens, Logger $logger, RateLimiter $rate_limiter ) {
		$this->email        = $email;
		$this->secret       = $secret;
		$this->tokens       = $tokens;
		$this->logger       = $logger;
		$this->rate_limiter = $rate_limiter;
	}

	public function nomenclature( string $type, string $cif = '', array $filters = array() ): array {
		$query = array();
		if ( 'companies' !== $type && '' !== $cif ) {
			$query['cif'] = $cif;
		}
		$query = array_merge( $query, $filters );

		$response = $this->request( 'GET', '/api/nomenclature/' . rawurlencode( $type ), array( 'query' => $query ) );

		return is_array( $response['data'] ?? null ) ? $response['data'] : array();
	}

	public function companies(): array {
		return $this->nomenclature( 'companies' );
	}

	public function series( string $cif ): array {
		return $this->nomenclature( 'series', $cif );
	}

	public function management( string $cif ): array {
		return $this->nomenclature( 'management', $cif );
	}

	public function test_connection(): array {
		return $this->companies();
	}

	public function create_document( string $type, array $data ): array {

		$this->rate_limiter->throttle();
		$response = $this->request( 'POST', '/api/docs/' . rawurlencode( $type ), array( 'json' => $data ) );
		return is_array( $response['data'] ?? null ) ? $response['data'] : array();
	}

	public function cancel_document( string $type, string $cif, string $series_name, $number ): array {
		$response = $this->request(
			'PUT',
			'/api/docs/' . rawurlencode( $type ) . '/cancel',
			array(
				'form' => array(
					'cif'        => $cif,
					'seriesName' => $series_name,
					'number'     => $number,
				),
			)
		);
		return is_array( $response['data'] ?? null ) ? $response['data'] : array();
	}

	public function delete_document( string $type, string $cif, string $series_name, $number, array $opts = array() ): array {
		$query    = array_merge(
			array(
				'cif'        => $cif,
				'seriesName' => $series_name,
				'number'     => $number,
			),
			$opts
		);
		$response = $this->request( 'DELETE', '/api/docs/' . rawurlencode( $type ), array( 'query' => $query ) );
		return is_array( $response['data'] ?? null ) ? $response['data'] : array();
	}

	public function collect_invoice( string $cif, string $series_name, $number, array $collect ): array {
		$response = $this->request(
			'PUT',
			'/api/docs/invoice/collect',
			array(
				'form' => array(
					'cif'        => $cif,
					'seriesName' => $series_name,
					'number'     => $number,
					'collect'    => $collect,
				),
			)
		);
		return is_array( $response['data'] ?? null ) ? $response['data'] : array();
	}

	public function create_webhook( string $cif, string $topic, string $endpoint ): array {
		$response = $this->request(
			'POST',
			'/api/webhooks',
			array( 'json' => compact( 'cif', 'topic', 'endpoint' ) )
		);
		return is_array( $response['data'] ?? null ) ? $response['data'] : array();
	}

	public function list_webhooks(): array {
		$response = $this->request( 'GET', '/api/webhooks' );
		return is_array( $response['data'] ?? null ) ? $response['data'] : array();
	}

	public function delete_webhook( $id ): array {
		$response = $this->request( 'DELETE', '/api/webhooks/' . rawurlencode( (string) $id ) );
		return is_array( $response['data'] ?? null ) ? $response['data'] : array();
	}

	public function request( string $method, string $path, array $opts = array() ): array {
		return $this->do_request( $method, $path, $opts, false );
	}

	private function do_request( string $method, string $path, array $opts, bool $retrying ): array {
		$token = $this->access_token();

		$url = self::BASE_URL . $path;
		if ( ! empty( $opts['query'] ) && is_array( $opts['query'] ) ) {
			$url = add_query_arg( array_map( 'strval', $opts['query'] ), $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => $token['token_type'] . ' ' . $token['access_token'],
				'Accept'        => 'application/json',
			),
		);

		if ( isset( $opts['json'] ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $opts['json'] );
		} elseif ( isset( $opts['form'] ) && is_array( $opts['form'] ) ) {

			$args['body'] = $opts['form'];
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			$this->logger->error( sprintf( '%s %s: transport error: %s', $method, $path, $message ) );
			throw new ApiException( esc_html( $message ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 401 === $code && ! $retrying ) {
			$this->tokens->clear();
			return $this->do_request( $method, $path, $opts, true );
		}

		$decoded = json_decode( $body, true );

		if ( 200 !== $code ) {
			$status_message = is_array( $decoded ) && isset( $decoded['statusMessage'] )
				? (string) $decoded['statusMessage']
				: sprintf( 'HTTP %d', $code );
			$this->logger->error( sprintf( '%s %s failed (%d): %s', $method, $path, $code, $status_message ) );
			throw new ApiException( esc_html( $status_message ), (int) $code, esc_html( $status_message ) );
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	private function access_token(): array {
		$token = $this->tokens->get();
		if ( null !== $token ) {
			return $token;
		}
		return $this->authenticate();
	}

	private function authenticate(): array {
		if ( '' === $this->email || '' === $this->secret ) {
			throw new AuthException( esc_html__( 'Email sau API secret lipsă.', 'oblio-fgwoo' ) );
		}

		$response = wp_remote_post(
			self::BASE_URL . '/api/authorize/token',
			array(
				'timeout' => self::TIMEOUT,
				'body'    => array(
					'client_id'     => $this->email,
					'client_secret' => $this->secret,
					'grant_type'    => 'client_credentials',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new AuthException( esc_html( $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$message = is_array( $data ) && isset( $data['statusMessage'] )
				? (string) $data['statusMessage']
				: sprintf( 'Autorizare eșuată (HTTP %d)', $code );
			$this->logger->error( 'Authentication failed: ' . $message );
			throw new AuthException( esc_html( $message ), (int) $code, esc_html( $message ) );
		}

		$this->tokens->set( $data );
		$this->logger->debug( 'Access token obtained', array( 'expires_in' => $data['expires_in'] ?? null ) );

		return $this->tokens->get() ?? array(
			'access_token' => (string) $data['access_token'],
			'token_type'   => (string) ( $data['token_type'] ?? 'Bearer' ),
			'expires_in'   => (int) ( $data['expires_in'] ?? 3600 ),
			'stored_at'    => time(),
		);
	}
}
