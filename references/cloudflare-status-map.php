<?php
/**
 * GENERATED from the pinned schema snapshot and the classification policy.
 * Do not edit: run `composer generate:status-map`.
 *
 * @package PostDomain
 */

declare( strict_types = 1 );

return array (
  'hostname' => 
  array (
    'active' => 'active',
    'pending' => 'pending_validation',
    'active_redeploying' => 'active',
    'moved' => 'failed',
    'pending_deletion' => 'revoked',
    'deleted' => 'revoked',
    'pending_blocked' => 'failed',
    'pending_migration' => 'pending_validation',
    'pending_provisioned' => 'pending_validation',
    'test_pending' => 'pending_validation',
    'test_active' => 'pending_validation',
    'test_active_apex' => 'pending_validation',
    'test_blocked' => 'failed',
    'test_failed' => 'failed',
    'provisioned' => 'pending_validation',
    'blocked' => 'failed',
  ),
  'ssl' => 
  array (
    'initializing' => 'pending_validation',
    'pending_validation' => 'pending_validation',
    'deleted' => 'revoked',
    'pending_issuance' => 'pending_validation',
    'pending_deployment' => 'pending_validation',
    'pending_deletion' => 'revoked',
    'pending_expiration' => 'active',
    'expired' => 'failed',
    'active' => 'active',
    'initializing_timed_out' => 'failed',
    'validation_timed_out' => 'failed',
    'issuance_timed_out' => 'failed',
    'deployment_timed_out' => 'failed',
    'deletion_timed_out' => 'failed',
    'pending_cleanup' => 'revoked',
    'staging_deployment' => 'pending_validation',
    'staging_active' => 'pending_validation',
    'deactivating' => 'revoked',
    'inactive' => 'revoked',
    'backup_issued' => 'active',
    'holding_deployment' => 'pending_validation',
  ),
);
