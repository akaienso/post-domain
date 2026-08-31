<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

/** One step of the guided setup, and what the operator may do about it now. */
final class Step {

	public const DONE        = 'done';
	public const CURRENT     = 'current';
	public const WAITING     = 'waiting';
	public const BLOCKED     = 'blocked';
	public const FAILED      = 'failed';
	public const UPCOMING    = 'upcoming';
	public const UNCONFIRMED = 'unconfirmed';

	/**
	 * @param string      $status   One of the constants above.
	 * @param string|null $action   The admin action this step offers, if any.
	 * @param string|null $label    The button label for that action.
	 * @param string|null $because  Why it cannot be done yet, in plain language.
	 */
	public function __construct(
		public readonly int $number,
		public readonly string $title,
		public readonly string $status,
		public readonly string $detail,
		public readonly ?string $action = null,
		public readonly ?string $label = null,
		public readonly ?string $because = null
	) {}

	public function is_actionable(): bool {
		return null !== $this->action
			&& in_array( $this->status, array( self::CURRENT, self::FAILED, self::UNCONFIRMED ), true );
	}

	/** The word shown on the step's badge. */
	public function status_text(): string {
		return match ( $this->status ) {
			self::DONE        => __( 'Done', 'post-domain' ),
			self::CURRENT     => __( 'Do this now', 'post-domain' ),
			self::WAITING     => __( 'Waiting', 'post-domain' ),
			self::BLOCKED     => __( 'Blocked', 'post-domain' ),
			self::FAILED      => __( 'Needs attention', 'post-domain' ),
			self::UNCONFIRMED => __( 'Not confirmed', 'post-domain' ),
			default           => __( 'Later', 'post-domain' ),
		};
	}
}
