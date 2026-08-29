<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Fixtures;

use PostDomain\Contracts\Clock;

/** A clock the test moves on purpose. Never autoloaded into production code. */
final class FrozenClock implements Clock {

	private \DateTimeImmutable $now;

	public function __construct( ?\DateTimeImmutable $now = null ) {
		$this->now = $now ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	public function now(): \DateTimeImmutable {
		return $this->now;
	}

	public function mysql(): string {
		return $this->now->format( 'Y-m-d H:i:s' );
	}

	public function set( \DateTimeImmutable $now ): void {
		$this->now = $now->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	public function advance( int $seconds ): void {
		$this->set( $this->now->modify( "+{$seconds} seconds" ) );
	}
}
