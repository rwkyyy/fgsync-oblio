<?php
/**
 * Contract for issuing a document for an order.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document;

use WC_Order;
interface DocumentIssuer {

	public function issue( WC_Order $order, string $doc_type, array $options = array() ): DocumentResult;
}
