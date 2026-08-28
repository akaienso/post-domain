<?php
declare( strict_types = 1 );

namespace PostDomain;

use PostDomain\Contracts\MappingRepository;
use PostDomain\Contracts\RoutingContract;
use PostDomain\Http\AdminRedirect;
use PostDomain\Http\Cors;
use PostDomain\Mapping\AliasResolver;
use PostDomain\Mapping\DbRepository;
use PostDomain\Routing\Classifier;
use PostDomain\Routing\ContentPolicy;
use PostDomain\Routing\ContextHolder;
use PostDomain\Routing\Disposition;
use PostDomain\Routing\EndpointClass;
use PostDomain\Routing\EnumerationScopeProvider;
use PostDomain\Routing\HostContextFactory;
use PostDomain\Routing\MappedHostGuard;
use PostDomain\Routing\MembershipFilter;
use PostDomain\Routing\PathDecomposer;
use PostDomain\Routing\PathNormalizer;
use PostDomain\Routing\Resolver;
use PostDomain\Routing\RoundTripVerifier;
use PostDomain\Routing\ServingEligibility;
use PostDomain\Routing\Subtree;
use PostDomain\Routing\UnknownHostGuard;
use PostDomain\Routing\UnmatchedPolicy;
use PostDomain\Support\AuthorityParser;
use PostDomain\Support\HostNormalizer;
use PostDomain\Support\IdnaNormalizer;
use PostDomain\Support\InfrastructureAllowlist;
use PostDomain\Support\Schema;
use PostDomain\Support\TrustedProxy;
use PostDomain\Url\Adapters\CommentLinks;
use PostDomain\Url\Adapters\CoreLinks;
use PostDomain\Url\Adapters\EmbedLinks;
use PostDomain\Url\Adapters\FeedLinks;
use PostDomain\Url\Adapters\OptionHome;
use PostDomain\Url\Adapters\SitemapLinks;
use PostDomain\Url\Canonical\Adapters\RedirectCanonicalGuard;
use PostDomain\Url\Canonical\Adapters\RelCanonical;
use PostDomain\Url\UrlPolicy;

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
		add_action( 'plugins_loaded', array( $plugin, 'register_url_adapters' ), 10 );
		add_action( 'parse_request', array( $plugin, 'resolve_request' ), 1 );
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

		add_action( 'pd_ssl_sweep', array( $plugin, 'sweep_ssl' ) );

		// Subsystem hook topologies register themselves. Each is one line here
		// rather than a subsystem absorbed into this class.
		\PostDomain\Verification\CronWiring::register();
		\PostDomain\Admin\Wiring::register();
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

	public function register_url_adapters(): void {
		$policy   = new UrlPolicy( home_url() );
		$verifier = new RoundTripVerifier( $this->routing() );

		( new CoreLinks( $this->context, $policy, $verifier, home_url() ) )->register();
		( new FeedLinks( $this->context, $policy ) )->register();
		( new CommentLinks( $this->context, $policy ) )->register();
		( new EmbedLinks( $this->context, $policy ) )->register();
		( new SitemapLinks( $this->context, $policy ) )->register();
		( new OptionHome( $this->context ) )->register();
		( new RelCanonical( $this->context ) )->register();
		( new RedirectCanonicalGuard( $this->context ) )->register();
		( new Cors( $this->repository ) )->register();
	}

	public function resolve_request( \WP $wp ): void {
		$serving = $this->context->serving();
		$host    = $this->context->host();

		if ( null === $serving
			|| null === $host
			|| EndpointClass::ROUTED !== $host->endpoint ) {
			return;
		}

		$decomposer = new PathDecomposer();
		$resolver   = new Resolver( $this->routing(), $decomposer );
		$resolution = $resolver->resolve( $serving, $wp );

		if ( null !== $resolution ) {
			$this->context->resolve(
				$resolution,
				$decomposer->decompose( (string) $wp->request )->rep
			);

			return;
		}

		$mode   = (string) apply_filters( 'pd_unmatched_policy', 'redirect' );
		$policy = new UnmatchedPolicy( $mode, home_url() );
		$uri    = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '/';

		$response = $policy->response_for( $host->method, $uri );

		if ( null === $response ) {
			return;
		}

		if ( isset( $response['url'] ) ) {
			wp_redirect( $response['url'], $response['status'] ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}

		status_header( $response['status'] );
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

	/**
	 * One cron pass: finish what was interrupted, then read what is true.
	 *
	 * Recovery runs first because a fenced mutation's row is not yet a fact
	 * anyone should reconcile against.
	 */
	public function sweep_ssl(): void {
		$clock = new \PostDomain\Support\SystemClock();
		$lease = new \PostDomain\Ssl\MutationLease( $clock );

		// No registry is built here. Cron resolves drivers through exactly the
		// factory REST uses, so the two can never disagree about a row's owner.
		$recovery = new \PostDomain\Ssl\LeaseRecovery( $lease, $this->repository, $clock );
		$resolver = new \PostDomain\Ssl\DriverRecoveryResolver();

		foreach ( $recovery->due( 50 ) as $mapping ) {
			$recovery->recover( $mapping, $resolver );
		}

		\PostDomain\Ssl\Reconciler::run( $this->repository->all() );
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
