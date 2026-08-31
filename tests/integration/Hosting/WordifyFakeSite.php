<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Integration\Hosting;

/** One site the fake transport knows about. */
final class WordifyFakeSite {

	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $domain
	) {}

	/** @return array<string, mixed> */
	public function record(): array {
		return array(
			'id'                  => $this->id,
			'display_name'        => $this->name,
			'name'                => $this->name,
			'domain'              => $this->domain,
			'provisioning_status' => 'active',
			'is_staging'          => false,
		);
	}
}
