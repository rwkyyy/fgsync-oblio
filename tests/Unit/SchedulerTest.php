<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Queue\Scheduler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \FGSyncOblio\Queue\Scheduler::class )]
final class SchedulerTest extends TestCase {

	private Scheduler $scheduler;

	protected function setUp(): void {
		oblio_test_reset();
		$this->scheduler = new Scheduler();
	}

	public function test_first_attempt_claims_the_marker_and_schedules(): void {
		$ok = $this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );

		$this->assertTrue( $ok );
		$this->assertTrue( $this->scheduler->has_pending_document( 1, 'invoice' ) );
		$this->assertCount( 1, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_the_scheduled_payload_carries_the_claimed_owner(): void {
		$this->scheduler->enqueue_document( 1, 'invoice', array( 'use_stock' => true ), 1, 0 );

		$payload = $GLOBALS['oblio_test_as_calls'][0]['args'][0];

		$this->assertSame( 1, $payload['order_id'] );
		$this->assertSame( 'invoice', $payload['doc_type'] );
		$this->assertSame( array( 'use_stock' => true ), $payload['options'] );
		$this->assertIsString( $payload['pending_owner'] );
		$this->assertNotSame( '', $payload['pending_owner'] );
	}

	public function test_a_second_first_attempt_for_the_same_order_and_type_is_deduped(): void {
		$first  = $this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );
		$second = $this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );

		$this->assertTrue( $first );
		$this->assertFalse( $second );
		$this->assertCount( 1, $GLOBALS['oblio_test_as_calls'] );
	}

	public function test_a_different_doc_type_for_the_same_order_is_not_deduped(): void {
		$invoice  = $this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );
		$proforma = $this->scheduler->enqueue_document( 1, 'proforma', array(), 1, 0 );

		$this->assertTrue( $invoice );
		$this->assertTrue( $proforma );
	}

	public function test_a_scheduling_failure_releases_the_marker_and_reports_failure(): void {
		$GLOBALS['oblio_test_as_fail'] = true;

		$ok = $this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );

		$this->assertFalse( $ok );
		$this->assertFalse( $this->scheduler->has_pending_document( 1, 'invoice' ) );
	}

	public function test_after_a_scheduling_failure_a_fresh_attempt_can_claim_again(): void {
		$GLOBALS['oblio_test_as_fail'] = true;
		$this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );

		$GLOBALS['oblio_test_as_fail'] = false;
		$ok = $this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );

		$this->assertTrue( $ok );
	}

	public function test_a_retry_attempt_does_not_re_claim_and_carries_the_owner_forward(): void {
		$ok = $this->scheduler->enqueue_document( 1, 'invoice', array(), 2, 30, 'original-owner' );

		$this->assertTrue( $ok );
		$payload = $GLOBALS['oblio_test_as_calls'][0]['args'][0];
		$this->assertSame( 'original-owner', $payload['pending_owner'] );
		$this->assertSame( 2, $payload['attempt'] );
	}

	public function test_reschedule_document_renews_the_marker_and_schedules_a_delayed_action(): void {
		$this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );
		$owner = $GLOBALS['oblio_test_as_calls'][0]['args'][0]['pending_owner'];

		$ok = $this->scheduler->reschedule_document( 1, 'invoice', array(), 1, 8, $owner );

		$this->assertTrue( $ok );
		$this->assertTrue( $this->scheduler->has_pending_document( 1, 'invoice' ) );
		$this->assertSame( 'single', $GLOBALS['oblio_test_as_calls'][1]['type'] );
	}

	/**
	 * RateLimiter::peek() is unbounded, but the marker is only renewed for
	 * PENDING_TTL (1h) - an uncapped delay could schedule the retry to run
	 * after the marker has already expired.
	 */
	public function test_reschedule_document_clamps_a_delay_larger_than_the_marker_ttl(): void {
		$this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );
		$owner = $GLOBALS['oblio_test_as_calls'][0]['args'][0]['pending_owner'];

		$before = time();
		$this->scheduler->reschedule_document( 1, 'invoice', array(), 1, 100000, $owner );

		$timestamp = $GLOBALS['oblio_test_as_calls'][1]['timestamp'];
		$this->assertGreaterThan( $before + 1700, $timestamp );
		$this->assertLessThanOrEqual( $before + 1800 + 2, $timestamp );
	}

	public function test_reschedule_refund_clamps_a_delay_larger_than_the_marker_ttl(): void {
		$this->scheduler->enqueue_refund( 1, 0, 1, 0 );
		$owner = $GLOBALS['oblio_test_as_calls'][0]['args'][0]['pending_owner'];

		$before = time();
		$this->scheduler->reschedule_refund( 1, 0, 1, 100000, $owner );

		$timestamp = $GLOBALS['oblio_test_as_calls'][1]['timestamp'];
		$this->assertGreaterThan( $before + 1700, $timestamp );
		$this->assertLessThanOrEqual( $before + 1800 + 2, $timestamp );
	}

	public function test_reschedule_refund_carries_the_invoice_wait_attempt_through(): void {
		$this->scheduler->enqueue_refund( 1, 5, 1, 0 );
		$owner = $GLOBALS['oblio_test_as_calls'][0]['args'][0]['pending_owner'];

		$this->scheduler->reschedule_refund( 1, 5, 1, 30, $owner, 4 );

		$payload = $GLOBALS['oblio_test_as_calls'][1]['args'][0];
		$this->assertSame( 1, $payload['attempt'] );
		$this->assertSame( 4, $payload['invoice_wait_attempt'] );
	}

	public function test_reschedule_document_reports_failure_without_crashing(): void {
		$this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );
		$owner = $GLOBALS['oblio_test_as_calls'][0]['args'][0]['pending_owner'];

		$GLOBALS['oblio_test_as_fail'] = true;
		$ok = $this->scheduler->reschedule_document( 1, 'invoice', array(), 1, 8, $owner );

		$this->assertFalse( $ok );
	}

	public function test_release_pending_document_requires_the_matching_owner(): void {
		$this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );
		$owner = $GLOBALS['oblio_test_as_calls'][0]['args'][0]['pending_owner'];

		$this->scheduler->release_pending_document( 1, 'invoice', 'wrong-owner' );
		$this->assertTrue( $this->scheduler->has_pending_document( 1, 'invoice' ) );

		$this->scheduler->release_pending_document( 1, 'invoice', $owner );
		$this->assertFalse( $this->scheduler->has_pending_document( 1, 'invoice' ) );
	}

	public function test_release_pending_document_with_an_empty_owner_is_a_no_op(): void {
		$this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );

		$this->scheduler->release_pending_document( 1, 'invoice', '' );

		$this->assertTrue( $this->scheduler->has_pending_document( 1, 'invoice' ) );
	}

	public function test_enqueue_refund_claims_a_marker_independent_of_documents(): void {
		$this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );
		$refundOk = $this->scheduler->enqueue_refund( 1, 5, 1, 0 );

		$this->assertTrue( $refundOk );
		$this->assertTrue( $this->scheduler->has_pending_refund( 1, 5 ) );
	}

	public function test_enqueue_refund_dedup_and_release_mirrors_documents(): void {
		$first  = $this->scheduler->enqueue_refund( 1, 5, 1, 0 );
		$second = $this->scheduler->enqueue_refund( 1, 5, 1, 0 );
		$this->assertTrue( $first );
		$this->assertFalse( $second );

		$owner = $GLOBALS['oblio_test_as_calls'][0]['args'][0]['pending_owner'];
		$this->scheduler->release_pending_refund( 1, 5, $owner );

		$this->assertFalse( $this->scheduler->has_pending_refund( 1, 5 ) );
	}

	public function test_a_full_storno_uses_refund_id_zero_as_its_own_marker(): void {
		$storno = $this->scheduler->enqueue_refund( 1, 0, 1, 0 );
		$partial = $this->scheduler->enqueue_refund( 1, 7, 1, 0 );

		$this->assertTrue( $storno );
		$this->assertTrue( $partial );
		$this->assertTrue( $this->scheduler->has_pending_refund( 1, 0 ) );
	}

	public function test_enqueue_stock_batch_reports_scheduling_success_and_failure(): void {
		$ok = $this->scheduler->enqueue_stock_batch( 0, 1, 0, 'run-token' );
		$this->assertTrue( $ok );

		$GLOBALS['oblio_test_as_fail'] = true;
		$failed = $this->scheduler->enqueue_stock_batch( 250, 1, 0, 'run-token' );
		$this->assertFalse( $failed );
	}

	/**
	 * Stock-batch actions carry a payload arg, so passing a group here would
	 * make as_unschedule_all_actions() match on args = [] exactly (real
	 * action-scheduler behaviour) and cancel nothing - hook-only is its
	 * documented wildcard for "every pending action under this hook".
	 */
	public function test_cancel_stock_batches_passes_no_group_so_it_matches_regardless_of_payload(): void {
		$this->scheduler->cancel_stock_batches();

		$this->assertCount( 1, $GLOBALS['oblio_test_as_unschedule_calls'] );
		$call = $GLOBALS['oblio_test_as_unschedule_calls'][0];
		$this->assertSame( Scheduler::HOOK_STOCK_BATCH, $call['hook'] );
		$this->assertSame( '', $call['group'] );
	}

	public function test_ensure_stock_scheduled_reports_failure_when_action_scheduler_rejects_it(): void {
		$GLOBALS['oblio_test_as_fail'] = true;

		$this->assertFalse( $this->scheduler->ensure_stock_scheduled( true, 'hourly' ) );
	}

	public function test_ensure_stock_scheduled_reports_success(): void {
		$this->assertTrue( $this->scheduler->ensure_stock_scheduled( true, 'hourly' ) );
	}

	public function test_ensure_stock_scheduled_reports_success_when_disabled(): void {
		$this->assertTrue( $this->scheduler->ensure_stock_scheduled( false, 'hourly' ) );
	}

	public function test_ensure_reconcile_scheduled_reports_failure_when_action_scheduler_rejects_it(): void {
		$GLOBALS['oblio_test_as_fail'] = true;

		$this->assertFalse( $this->scheduler->ensure_reconcile_scheduled( true, 'hourly' ) );
	}

	public function test_ensure_reconcile_scheduled_reports_success(): void {
		$this->assertTrue( $this->scheduler->ensure_reconcile_scheduled( true, 'hourly' ) );
	}

	public function test_renew_pending_document_succeeds_for_the_owner_that_claimed_it(): void {
		$this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );
		$owner = $GLOBALS['oblio_test_as_calls'][0]['args'][0]['pending_owner'];

		$this->assertTrue( $this->scheduler->renew_pending_document( 1, 'invoice', $owner ) );
		$this->assertTrue( $this->scheduler->has_pending_document( 1, 'invoice' ) );
	}

	public function test_renew_pending_document_fails_for_a_different_owner(): void {
		$this->scheduler->enqueue_document( 1, 'invoice', array(), 1, 0 );

		$this->assertFalse( $this->scheduler->renew_pending_document( 1, 'invoice', 'wrong-owner' ) );
	}

	/**
	 * An Action Scheduler pickup delay past PENDING_TTL could let the marker
	 * expire and get reclaimed by a second, duplicate chain before this job
	 * even starts - renewal by the original owner must fail cleanly in that
	 * case rather than clobbering the new claimant's marker.
	 */
	public function test_renew_pending_document_fails_once_reclaimed_by_a_different_owner(): void {
		$GLOBALS['oblio_test_options']['oblio_fgwoo_pending_doc_invoice_1'] = ( time() + 3600 ) . '|someone-else';

		$this->assertFalse( $this->scheduler->renew_pending_document( 1, 'invoice', 'original-owner' ) );
	}

	public function test_renew_pending_document_with_an_empty_owner_is_a_no_op(): void {
		$this->assertFalse( $this->scheduler->renew_pending_document( 1, 'invoice', '' ) );
	}

	public function test_renew_pending_refund_succeeds_for_the_owner_that_claimed_it(): void {
		$this->scheduler->enqueue_refund( 1, 5, 1, 0 );
		$owner = $GLOBALS['oblio_test_as_calls'][0]['args'][0]['pending_owner'];

		$this->assertTrue( $this->scheduler->renew_pending_refund( 1, 5, $owner ) );
	}

	public function test_renew_pending_refund_fails_for_a_different_owner(): void {
		$this->scheduler->enqueue_refund( 1, 5, 1, 0 );

		$this->assertFalse( $this->scheduler->renew_pending_refund( 1, 5, 'wrong-owner' ) );
	}

	public function test_backoff_grows_and_stays_within_the_hourly_cap(): void {
		$first = $this->scheduler->backoff( 1 );
		$later = $this->scheduler->backoff( 10 );

		$this->assertGreaterThanOrEqual( 30, $first );
		$this->assertLessThanOrEqual( 3600 + 20, $later );
	}
}
