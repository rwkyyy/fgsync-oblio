<?php
/**
 * Who the buyer on an order is, as far as invoicing and VAT are concerned.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Customer;

final class BuyerProfile {

	public const SOURCE_FACTURARE = 'facturare';

	public const SOURCE_DETECTED = 'detected';

	public function __construct(
		public readonly bool $is_business,
		public readonly string $country,
		public readonly string $company,
		public readonly string $tax_id,
		public readonly string $cnp,
		public readonly string $registration,
		public readonly string $bank,
		public readonly string $iban,
		public readonly string $source
	) {}

	/**
	 * The identifier Oblio gets in the client "cif" field: the company's tax
	 * ID, or the individual's CNP.
	 */
	public function oblio_cif(): string {
		return $this->is_business ? $this->tax_id : $this->cnp;
	}
}
