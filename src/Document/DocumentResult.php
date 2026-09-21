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
		public readonly string $link
	) {}

	public static function from_api( string $doc_type, array $data ): self {
		return new self(
			$doc_type,
			(string) ( $data['seriesName'] ?? '' ),
			(string) ( $data['number'] ?? '' ),
			(string) ( $data['link'] ?? '' )
		);
	}
}
