<?php
declare( strict_types = 1 );

namespace PostDomain;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Contracts\RoutingContract;
use PostDomain\Http\AdminRedirect;
use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Routing\Classifier;
use PostDomain\Routing\ContentPolicy;
use PostDomain\Routing\ContextHolder;
use PostDomain\Routing\Disposition;
use PostDomain\Routing\EnumerationScopeProvider;
use PostDomain\Routing\HostContextFactory;
use PostDomain\Routing\MappedHostGuard;
use PostDomain\Routing\MembershipFilter;
use PostDomain\Routing\PathNormalizer;
use PostDomain\Routing\ServingEligibility;
use PostDomain\Routing\Subtree;
use PostDomain\Routing\UnknownHostGuard;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\IdnaNormalizer;
use PostDomain\Support\InfrastructureAllowlist;
use PostDomain\Support\Schema;
use PostDomain\Support\TrustedProxy;

final class Plugin {

	private static ?self $instance = null;

	private ContextHolder $context;

	private MappingRepository $repository;

	private AliasResolver $aliases;

	private ?ServingEligibility $eligibility = null;

	private Disposition $disposition = Disposition::PRIMARY;

	private function __construct() {
		$this->context    = new ContextHolder();
		$this->repository = new DbRepository();
		$this->aliases    = new AliasResolver( $this->repository );
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function boot(): void {
		$plugin = self::instance();

		if ( has_action( 'plugins_loaded', array( $plugin, 'build_host_context' ) ) ) {
			return;
		}

		add_action( 'plugins_loaded', array( $plugin, 'build_host_context' ), 0 );
		add_action( 'plugins_loaded', array( $plugin, 'guard_unknown_host' ), 1 );
		add_action( 'plugins_loaded', array( $plugin, 'redirect_admin' ), 2 );
		add_action( 'plugins_loaded', array( $plugin, 'freeze_eligibility' ), 11 );
		add_action( 'init', array( $plugin, 'freeze_content_policy' ), 99 );
		add_action( 'parse_request', array( $plugin, 'enforce_disposition' ), 0 );
		add_action( 'pre_get_posts', array( $plugin, 'scope_feed_query' ) );
		add_filter( 'the_posts', array( $plugin, 'enforce_membership' ), 10, 2 );

		/**
		 * Schema upgrades run only where a slow dbDelta() is acceptable and a
		 * failure is visible: the admin, cron, and WP-CLI. A front-end request
		 * never migrates the database out from under itself.
		 */
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) ) {
			Schema::maybe_upgrade();
		}
	}

	public function context(): ContextHolder {
		return $this->context;
	}

	public function repository(): MappingRepository {
		return $this->repository;
	}

	public function aliases(): AliasResolver {
		return $this->aliases;
	}

	public function disposition(): Disposition {
		return $this->disposition;
	}

	public function build_host_context(): void {
		/** @var string[] $allowlist */
		$allowlist = (array) apply_filters(
			'pd_allowed_infrastructure_hosts',
			defined( 'PD_ALLOWED_HOSTS' ) ? (array) constant( 'PD_ALLOWED_HOSTS' ) : array()
		);

		/** @var string[] $proxies */
		$proxies = (array) apply_filters(
			'pd_trusted_proxies',
			defined( 'PD_TRUSTED_PROXIES' ) ? (array) constant( 'PD_TRUSTED_PROXIES' ) : array()
		);

		$factory = new HostContextFactory(
			new TrustedProxy( $proxies ),
			new AuthorityParser(),
			new InfrastructureAllowlist( $allowlist ),
			new HostNormalizer( new IdnaNormalizer() ),
			new Classifier( rest_get_url_prefix() ),
			$this->repository,
			(string) wp_parse_url( home_url(), PHP_URL_HOST )
		);

		/**
		 * PHP_SAPI is passed in rather than read inside the classifier, so the
		 * classification stays a pure function of its inputs.
		 */
		$server = array_merge( $_SERVER, array( 'PD_SAPI' => PHP_SAPI ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->context->set_host( $factory->build( $server, $_GET ) );
	}

	public function guard_unknown_host(): void {
		$host = $this->context->host();

		if ( null === $host ) {
			return;
		}

		$status = ( new UnknownHostGuard( $this->unknown_host_policy() ) )->response_for( $host );

		if ( null === $status ) {
			return;
		}

		status_header( $status );
		nocache_headers();
		exit;
	}

	public function redirect_admin(): void {
		$host = $this->context->host();

		if ( null === $host ) {
			return;
		}

		$enabled  = (bool) apply_filters( 'pd_admin_redirect', true );
		$redirect = ( new AdminRedirect( home_url(), $enabled ) )->redirect_for(
			$host,
			isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/'
		);

		if ( null === $redirect ) {
			return;
		}

		wp_redirect( $redirect['url'], $redirect['status'] ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	public function freeze_eligibility(): void {
		$host = $this->context->host();

		if ( null !== $host ) {
			$this->eligibility = ServingEligibility::decide( $host, $this->aliases );
		}
	}

	public function freeze_content_policy(): void {
		$serving = null === $this->eligibility || ! $this->eligibility->is_active
			? null
			: ContentPolicy::freeze( $this->eligibility, $this->aliases );

		$this->context->set_serving( $serving );

		$host = $this->context->host();

		if ( null !== $host ) {
			$this->disposition = MappedHostGuard::decide(
				$host,
				$this->eligibility,
				$serving,
				$this->unknown_host_policy()
			);
		}
	}

	public function enforce_disposition(): void {
		$status = $this->disposition->status();

		if ( null === $status || 400 === $status || 421 === $status ) {
			return;
		}

		status_header( $status );
		nocache_headers();
		exit;
	}

	public function scope_feed_query( \WP_Query $query ): void {
		$serving = $this->context->serving();

		if ( null === $serving || ! $query->is_feed() ) {
			return;
		}

		$limit = (int) apply_filters( 'pd_scope_enumeration_limit', 500 );
		$limit = max( 0, min( 5000, $limit ) );

		$scope = ( new EnumerationScopeProvider( $this->routing(), $limit ) )->scope( $serving );

		if ( ! $scope->is_bounded ) {
			// Never unbounded: an id that cannot exist yields an empty result set.
			$query->set( 'post__in', array( 0 ) );

			return;
		}

		$query->set( 'post__in', $scope->post__in );

		foreach ( $scope->query_args as $key => $value ) {
			$query->set( $key, $value );
		}
	}

	/**
	 * @param \WP_Post[] $posts
	 * @return \WP_Post[]
	 */
	public function enforce_membership( array $posts, \WP_Query $query ): array {
		$serving = $this->context->serving();

		if ( null === $serving || ! $query->is_feed() ) {
			return $posts;
		}

		return ( new MembershipFilter( $this->routing() ) )->keep_members( $posts, $serving );
	}

	public function routing(): RoutingContract {
		return new Subtree( new PathNormalizer() );
	}

	private function unknown_host_policy(): string {
		$policy = defined( 'PD_UNKNOWN_HOST_POLICY' ) ? (string) constant( 'PD_UNKNOWN_HOST_POLICY' ) : '421';
		$policy = (string) apply_filters( 'pd_unknown_host_policy', $policy );

		return in_array( $policy, UnknownHostGuard::POLICIES, true ) ? $policy : '421';
	}
}
