<?php
declare( strict_types = 1 );

namespace PostDomain\Mapping;

/**
 * An ordinary write was refused because a provider mutation holds the row.
 *
 * Distinct from `StaleRevision`: the caller's revision may be perfectly current.
 * What stops the write is that six columns describing an operation which may
 * already have been sent to a provider belong to `MutationLease`, and an
 * ordinary update has no way to leave them untouched while still being one
 * atomic statement (spec §12.6).
 *
 * Expiry is not availability. An expired lease is the record `LeaseRecovery`
 * needs in order to find out what the provider actually did, so it fences an
 * ordinary write exactly as an unexpired one does.
 */
final class MutationInProgress extends \RuntimeException {}
