<?php
/**
 * Thrown when the reservation query fails, so callers can't mistake it for a
 * legitimate "nothing reserved" result.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Stock;

use RuntimeException;
final class ReservationUnavailableException extends RuntimeException {
}
