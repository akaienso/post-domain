<?php
declare( strict_types = 1 );

namespace PostDomain\Support;

use PostDomain\Contracts\Scheduler;

final class WpCronScheduler implements Scheduler {

	/** @param array<int, mixed> $args */
	public function schedule( string $hook, \DateTimeImmutable $at, array $args = array() ): void {
		$this->unschedule( $hook, $args );
		wp_schedule_single_event( $at->getTimestamp(), $hook, $args );
	}

	/** @param array<int, mixed> $args */
	public function unschedule( string $hook, array $args = array() ): void {
		$timestamp = wp_next_scheduled( $hook, $args );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, $hook, $args );
			$timestamp = wp_next_scheduled( $hook, $args );
		}
	}

	/** @param array<int, mixed> $args */
	public function next( string $hook, array $args = array() ): ?\DateTimeImmutable {
		$timestamp = wp_next_scheduled( $hook, $args );

		if ( false === $timestamp ) {
			return null;
		}

		return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}
}
