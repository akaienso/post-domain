<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

use PostDomain\Contracts\Clock;

final class SystemClock implements Clock {

	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	public function mysql(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
