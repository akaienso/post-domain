<?php
declare( strict_types = 1 );

namespace PostDomain\Ssl;

final class ReconcileReport {

	/** @param iterable<string, SslStatus> $statuses */
	public function __construct(
		public readonly iterable $statuses,
		public readonly bool $snapshot_complete,
		public readonly ?string $incomplete_reason = null
	) {}
}
