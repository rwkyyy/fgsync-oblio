<?php
/**
 * Immutable result of an issued Oblio document.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document;

final class DocumentResult {

	public function __construct(
		public readonly string $doc_type,
		public readonly string $series_name,
		public readonly string $number,
		public readonly string $link,
		public readonly string $date = ''
	) {}

	/**
	 * @param string              $doc_type Document type.
	 * @param array<string,mixed> $data     Raw Oblio API response.
	 * @param string              $date     Issue date actually sent to Oblio in the request payload
	 *                                      (e.g. the order date, when issue_date_basis is 'order'), so
	 *                                      stored metadata and email placeholders agree with the real
	 *                                      document instead of always recording today. Empty when not
	 *                                      known (e.g. storno documents, which are always dated today).
	 */
	public static function from_api( string $doc_type, array $data, string $date = '' ): self {
		return new self(
			$doc_type,
			(string) ( $data['seriesName'] ?? '' ),
			(string) ( $data['number'] ?? '' ),
			(string) ( $data['link'] ?? '' ),
			$date
		);
	}
}
