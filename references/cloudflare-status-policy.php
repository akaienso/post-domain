<?php
/**
 * Human-authored classification. The pinned schema says which values exist; this
 * says what each one means locally. Generation fails on any value missing here.
 *
 * Local states: active, pending_validation, failed, revoked.
 *
 * - active             the certificate is serving traffic now
 * - pending_validation the provider is still working; wait, do not act
 * - failed             a terminal problem an operator must resolve
 * - revoked            the resource is being or has been withdrawn
 *
 * Cloudflare's `test_*` hostname statuses describe a staging hostname that is
 * not serving production traffic, so a healthy test state is pending rather
 * than active; a failed one is a real failure.
 *
 * @package PostDomain
 */

declare( strict_types = 1 );

return array(
	'hostname' => array(
		'active'              => 'active',
		'pending'             => 'pending_validation',
		'active_redeploying'  => 'active',
		'moved'               => 'failed',
		'pending_deletion'    => 'revoked',
		'deleted'             => 'revoked',
		'pending_blocked'     => 'failed',
		'pending_migration'   => 'pending_validation',
		'pending_provisioned' => 'pending_validation',
		'test_pending'        => 'pending_validation',
		'test_active'         => 'pending_validation',
		'test_active_apex'    => 'pending_validation',
		'test_blocked'        => 'failed',
		'test_failed'         => 'failed',
		'provisioned'         => 'pending_validation',
		'blocked'             => 'failed',
	),
	'ssl'      => array(
		'initializing'           => 'pending_validation',
		'pending_validation'     => 'pending_validation',
		'deleted'                => 'revoked',
		'pending_issuance'       => 'pending_validation',
		'pending_deployment'     => 'pending_validation',
		'pending_deletion'       => 'revoked',
		'pending_expiration'     => 'active',
		'expired'                => 'failed',
		'active'                 => 'active',
		'initializing_timed_out' => 'failed',
		'validation_timed_out'   => 'failed',
		'issuance_timed_out'     => 'failed',
		'deployment_timed_out'   => 'failed',
		'deletion_timed_out'     => 'failed',
		'pending_cleanup'        => 'revoked',
		'staging_deployment'     => 'pending_validation',
		'staging_active'         => 'pending_validation',
		'deactivating'           => 'revoked',
		'inactive'               => 'revoked',
		'backup_issued'          => 'active',
		'holding_deployment'     => 'pending_validation',
	),
);
