<?php
declare( strict_types = 1 );

namespace PostDomain\Routing;

use PostDomain\Mapping\Mapping;

final class ContextHolder {

	private ?HostContext $host = null;

	private ?ServingContext $serving = null;

	public function set_host( HostContext $context ): void {
		$this->host = $context;
	}

	public function host(): ?HostContext {
		return $this->host;
	}

	public function set_serving( ?ServingContext $context ): void {
		$this->serving = $context;
	}

	public function serving(): ?ServingContext {
		return $this->serving;
	}

	public function resolve( object $resolution, Representation $representation ): void {
		if ( null !== $this->serving ) {
			$this->serving = $this->serving->with_resolution( $resolution, $representation );
		}
	}

	/**
	 * Scoped push and pop, so cron, CLI, and mail can borrow a mapping's context.
	 */
	public function with( ServingContext $context, callable $fn ): mixed {
		$previous      = $this->serving;
		$this->serving = $context;

		try {
			return $fn();
		} finally {
			$this->serving = $previous;
		}
	}

	public function mapping(): ?Mapping {
		return $this->serving?->mapping ?? $this->host?->mapping;
	}
}
