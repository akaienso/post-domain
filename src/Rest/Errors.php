<?php
declare( strict_types = 1 );

namespace PostDomain\Rest;

final class Errors {

	public const NS = 'post-domain/v1';

	public const HOST_INVALID             = 'pd_host_invalid';
	public const HOST_MALFORMED_AUTHORITY = 'pd_host_malformed_authority';
	public const HOST_WILDCARD            = 'pd_host_wildcard';
	public const HOST_EXISTS              = 'pd_host_exists';
	public const HOST_TOO_LONG            = 'pd_host_too_long';
	public const LABEL_INVALID            = 'pd_label_invalid';
	public const ALIAS_CHAIN              = 'pd_alias_chain';
	public const ALIAS_NO_TARGET          = 'pd_alias_no_target';
	public const ALIAS_IN_USE             = 'pd_alias_in_use';
	public const POST_INVALID             = 'pd_post_invalid';
	public const CONFLICT                 = 'pd_conflict';
	public const PRECONDITION_REQUIRED    = 'pd_precondition_required';
	public const PRECONDITION_FAILED      = 'pd_precondition_failed';
	public const RATE_LIMITED             = 'pd_rate_limited';
	public const ENVIRONMENT_UNRESOLVED   = 'pd_environment_unresolved';
	public const MUTATION_IN_PROGRESS     = 'pd_mutation_in_progress';
	public const MUTATION_UNAUTHORIZED    = 'pd_mutation_unauthorized';
	public const UNOWNED_RESOURCE         = 'pd_unowned_resource';
	public const CREATE_AMBIGUOUS         = 'pd_provider_create_ambiguous';
	public const METHOD_UNSUPPORTED       = 'pd_method_unsupported';
	public const CONFIRMATION_REQUIRED    = 'pd_confirmation_required';
	public const NO_DRIVER                = 'pd_no_driver';
	public const SSL_NOT_CONFIGURED       = 'pd_ssl_not_configured';
	public const ENVIRONMENT_DRIFTED      = 'pd_provider_environment_changed';
	public const FENCED                   = 'pd_mutation_fenced';
	public const FINALIZATION_FAILED      = 'pd_finalization_failed';
	public const OUTCOME_AMBIGUOUS        = 'pd_provider_outcome_ambiguous';
	public const FORBIDDEN                = 'pd_forbidden';
}
