<?php
declare( strict_types = 1 );

namespace PostDomain\Admin;

use PostDomain\Http\ProbeEndpoint;

/**
 * The hook topology for the admin surface and the diagnostics probe.
 *
 * Plan 11 Tasks 1 and 3 place these registrations inside `Plugin::boot()`.
 * They live here instead, on the same terms as `Verification\CronWiring`, so
 * that `Plugin` has one line to add rather than a subsystem to absorb:
 * `Admin\Wiring::register()`.
 */
final class Wiring {

	public static function register(): void {
		// The settings screen, the domains list, and the blocking clone banner
		// exist only where an operator can see them.
		if ( is_admin() ) {
			SettingsPage::register();
		}

		// The probe page is served on a MAPPED host, never in the admin, so it
		// registers unconditionally.
		ProbeEndpoint::boot();
	}
}
