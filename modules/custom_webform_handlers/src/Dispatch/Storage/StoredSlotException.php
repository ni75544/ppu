<?php

namespace Drupal\custom_webform_handlers\Dispatch\Storage;

/**
 * A stored row could not be turned back into a trustworthy Slot.
 *
 * Raised rather than returning a best-effort Slot. The usual cause is a stored
 * timezone that this server no longer recognises, and the tempting repair — read
 * it as UTC — is the one thing that must never happen: the instant would survive
 * while the local pickup time moved, so every subsequent check would agree with
 * itself and the driver would still arrive at the wrong hour.
 *
 * Overlap enforcement does not depend on this. Windows are compared as UTC
 * timestamps, so a row with a corrupt zone still occupies its capacity correctly
 * and cannot be double booked; only its human-readable local reading is refused.
 */
final class StoredSlotException extends \RuntimeException {
}
