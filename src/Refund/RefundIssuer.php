<?php
/**
 * Contract for issuing a storno (refund) document.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Refund;

use FGSyncOblio\Document\DocumentResult;
use WC_Order;
interface RefundIssuer {

	public function issue_for_refund( int $order_id, int $refund_id, bool $fail_fast = false ): ?DocumentResult;

	public function issue_full_storno( WC_Order $order, bool $fail_fast = false ): DocumentResult;
}
