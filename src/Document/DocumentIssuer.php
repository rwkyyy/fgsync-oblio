<?php
/**
 * Contract for issuing a document for an order.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Document;

use WC_Order;
interface DocumentIssuer {

	public function issue( WC_Order $order, string $doc_type, array $options = array() ): DocumentResult;
}
