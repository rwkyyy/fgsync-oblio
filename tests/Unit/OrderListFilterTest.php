<?php
/**
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Tests\Unit;

use OblioWoo\Admin\OrderListFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \OblioWoo\Admin\OrderListFilter::class )]
final class OrderListFilterTest extends TestCase {

	public function test_invoice_filter_requires_invoice_link(): void {
		$clause = OrderListFilter::meta_query_for( 'invoice' );
		$this->assertSame( 'oblio_fgwoo_invoice_link', $clause['key'] );
		$this->assertSame( 'EXISTS', $clause['compare'] );
	}

	public function test_none_requires_no_invoice_and_no_proforma(): void {
		$clause = OrderListFilter::meta_query_for( 'none' );
		$this->assertSame( 'AND', $clause['relation'] );

		$keys = array( $clause[0]['key'], $clause[1]['key'] );
		$this->assertContains( 'oblio_fgwoo_invoice_link', $keys );
		$this->assertContains( 'oblio_fgwoo_proforma_link', $keys );
		$this->assertSame( 'NOT EXISTS', $clause[0]['compare'] );
		$this->assertSame( 'NOT EXISTS', $clause[1]['compare'] );
	}

	public function test_proforma_excludes_already_invoiced(): void {
		$clause = OrderListFilter::meta_query_for( 'proforma' );
		$this->assertSame( 'AND', $clause['relation'] );

		$by_key = array( $clause[0]['key'] => $clause[0]['compare'], $clause[1]['key'] => $clause[1]['compare'] );
		$this->assertSame( 'EXISTS', $by_key['oblio_fgwoo_proforma_link'] );
		$this->assertSame( 'NOT EXISTS', $by_key['oblio_fgwoo_invoice_link'] );
	}

	public function test_storno_and_failed_keys(): void {
		$this->assertSame( 'oblio_fgwoo_storno_link', OrderListFilter::meta_query_for( 'storno' )['key'] );
		$this->assertSame( 'oblio_fgwoo_notice_link', OrderListFilter::meta_query_for( 'notice' )['key'] );
		$this->assertSame( 'oblio_fgwoo_invoice_failed', OrderListFilter::meta_query_for( 'failed' )['key'] );
	}

	public function test_unknown_filter_is_empty(): void {
		$this->assertSame( array(), OrderListFilter::meta_query_for( 'bogus' ) );
	}
}
