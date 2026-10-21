<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Api\OblioClient;
use FGSyncOblio\Api\TokenStore;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( OblioClient::class )]
final class OblioClientTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	public function test_token_and_api_requests_identify_the_plugin_wordpress_woocommerce_and_shop(): void {
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) ),
		);
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'data' => array() ) ),
		);

		$client = new OblioClient( 'shop@example.test', 'secret', new TokenStore( new Encryption(), false ), new Logger(), new RateLimiter( new InMemorySlotStore() ), new ConnectionHealth() );
		$client->companies();

		$this->assertCount( 2, $GLOBALS['oblio_test_http_calls'] );
		foreach ( $GLOBALS['oblio_test_http_calls'] as $call ) {
			$this->assertSame( 'FGSync-Oblio/1.0.2 (WordPress/6.8.3; WooCommerce/10.2.1; https://example.test)', $call['args']['user-agent'], $call['url'] );
		}
	}
}
