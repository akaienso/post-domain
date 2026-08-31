<?php
declare( strict_types = 1 );

namespace PostDomain\Tests\Unit\Hosting\Fixtures;

use PostDomain\Hosting\WordifyAccount;
use PostDomain\Hosting\WordifyClient;
use PostDomain\Hosting\WordifyDomain;
use PostDomain\Hosting\WordifyDomainList;
use PostDomain\Hosting\WordifyFailure;
use PostDomain\Hosting\WordifySite;
use PostDomain\Hosting\WordifySiteList;

/**
 * A scripted Wordify client. No network, no credentials, no fixtures of real
 * account data.
 *
 * Each operation is given a queue of answers. A queue with one entry left keeps
 * answering with it, so a test that cares about one read does not have to
 * predict how many the code makes; what a test asserts on instead is the
 * recorded call count, which is how "the write was attempted exactly once" is
 * checked.
 */
final class FakeWordifyClient implements WordifyClient {

	public bool $ready = true;

	/** @var array<int, array{op: string, args: array<int, string>}> */
	public array $calls = array();

	/** @var array<int, WordifyDomain|WordifyFailure> */
	private array $attach_answers = array();

	/** @var array<int, WordifyDomainList|WordifyFailure> */
	private array $domain_answers = array();

	/** @var array<int, WordifySiteList|WordifyFailure> */
	private array $site_answers = array();

	public static function domain( string $host, bool $is_primary = false, string $reference = 'dom-1' ): WordifyDomain {
		return new WordifyDomain( $host, $is_primary, 'active', 'verified', '2026-08-30T00:00:00Z', '2026-08-30T00:00:00Z', $reference );
	}

	/** @param WordifyDomain|WordifyFailure ...$answers */
	public function will_attach( ...$answers ): self {
		$this->attach_answers = array_values( $answers );

		return $this;
	}

	/** @param WordifyDomainList|WordifyFailure ...$answers */
	public function will_read_domains( ...$answers ): self {
		$this->domain_answers = array_values( $answers );

		return $this;
	}

	/** @param WordifySiteList|WordifyFailure ...$answers */
	public function will_list_sites( ...$answers ): self {
		$this->site_answers = array_values( $answers );

		return $this;
	}

	/** How many times an operation was called. */
	public function count_of( string $operation ): int {
		return count(
			array_filter(
				$this->calls,
				static fn ( array $call ): bool => $call['op'] === $operation
			)
		);
	}

	public function is_ready(): bool {
		return $this->ready;
	}

	/** @return WordifyAccount|WordifyFailure */
	public function me() {
		$this->record( 'me' );

		return new WordifyAccount( 'user-1', array( 'team-1' ) );
	}

	/**
	 * @param array<string, string> $filters
	 * @return WordifySiteList|WordifyFailure
	 */
	public function sites( array $filters = array() ) {
		$this->record( 'sites', implode( ',', array_values( $filters ) ) );

		/** @var WordifySiteList|WordifyFailure $answer */
		$answer = $this->next( $this->site_answers, new WordifySiteList( array() ) );

		return $answer;
	}

	/** @return WordifySite|WordifyFailure */
	public function site( string $site_id ) {
		$this->record( 'site', $site_id );

		return new WordifySite( $site_id, 'active' );
	}

	/** @return WordifyDomainList|WordifyFailure */
	public function domains( string $site_id ) {
		$this->record( 'domains', $site_id );

		/** @var WordifyDomainList|WordifyFailure $answer */
		$answer = $this->next( $this->domain_answers, new WordifyDomainList( array() ) );

		return $answer;
	}

	/** @return WordifyDomain|WordifyFailure */
	public function attach_domain( string $site_id, string $host ) {
		$this->record( 'attach_domain', $site_id, $host );

		/** @var WordifyDomain|WordifyFailure $answer */
		$answer = $this->next( $this->attach_answers, self::domain( $host ) );

		return $answer;
	}

	/** @return WordifyDomainList|WordifyFailure */
	public function recheck( string $site_id ) {
		$this->record( 'recheck', $site_id );

		return new WordifyDomainList( array() );
	}

	private function record( string $operation, string ...$args ): void {
		$this->calls[] = array(
			'op'   => $operation,
			'args' => array_values( $args ),
		);
	}

	/**
	 * @param array<int, mixed> $queue
	 * @param mixed             $fallback
	 * @return mixed
	 */
	private function next( array &$queue, $fallback ) {
		if ( array() === $queue ) {
			return $fallback;
		}

		return count( $queue ) > 1 ? array_shift( $queue ) : $queue[0];
	}
}
