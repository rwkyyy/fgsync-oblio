<?php
/**
 * Contract for issuing a storno (refund) document.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Refund;

use OblioWoo\Document\DocumentResult;
use WC_Order;
interface RefundIssuer {

	public function issue_for_refund( int $order_id, int $refund_id ): ?DocumentResult;

	public function issue_full_storno( WC_Order $order ): DocumentResult;
}
