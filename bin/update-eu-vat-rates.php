<?php
/**
 * Refreshes data/eu-vat-rates.json from api.vatcomply.com.
 *
 * Run by .github/workflows/vat-rates.yml. Leaves the file untouched when the
 * rates are unchanged (so no monthly churn), and fails without writing when
 * the response doesn't validate.
 *
 * Usage: php bin/update-eu-vat-rates.php
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script, runs outside WordPress.

require __DIR__ . '/../src/Tax/EuVatRates.php';

use FGSyncOblio\Tax\EuVatRates;

/**
 * @return int Process exit code.
 */
function oblio_fgwoo_update_eu_vat_rates(): int {
	$target  = __DIR__ . '/../data/eu-vat-rates.json';
	$context = stream_context_create( array( 'http' => array( 'timeout' => 30 ) ) );
	$body    = file_get_contents( EuVatRates::SOURCE, false, $context );
	$items   = false === $body ? null : json_decode( $body, true );

	if ( ! is_array( $items ) ) {
		fwrite( STDERR, 'Could not fetch or decode ' . EuVatRates::SOURCE . "\n" );
		return 1;
	}

	$fresh = EuVatRates::from_vatcomply( $items, gmdate( 'Y-m-d' ) );

	try {
		EuVatRates::validate( $fresh );
	} catch ( InvalidArgumentException $exception ) {
		fwrite( STDERR, 'Rejected: ' . $exception->getMessage() . "\n" );
		return 1;
	}

	$current = is_readable( $target ) ? json_decode( (string) file_get_contents( $target ), true ) : null;
	if ( is_array( $current ) && EuVatRates::same_rates( $current, $fresh ) ) {
		echo "EU VAT rates unchanged.\n";
		return 0;
	}

	file_put_contents( $target, json_encode( $fresh, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) . "\n" );
	echo "EU VAT rates updated.\n";
	return 0;
}

exit( oblio_fgwoo_update_eu_vat_rates() );
