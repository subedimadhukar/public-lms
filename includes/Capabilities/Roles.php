<?php
/**
 * Role definitions.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Adds and removes the plugin's roles and grants its capabilities.
 *
 * Roles are a starting point only: code checks capabilities (ADR-005).
 * install() is idempotent and never removes a capability a site owner added.
 */
final class Roles {

	const STUDENT    = 'plms_student';
	const INSTRUCTOR = 'plms_instructor';
	const MANAGER    = 'plms_manager';

	/**
	 * Returns the roles the plugin creates, with display names and capabilities.
	 *
	 * Display names are stored untranslated, as WordPress expects for roles.
	 *
	 * @return array<string, array{name: string, capabilities: array<string, bool>}>
	 */
	public static function definitions(): array {
		return array(
			self::STUDENT    => array(
				'name'         => 'Student',
				'capabilities' => array(
					'read' => true,
				),
			),
			self::INSTRUCTOR => array(
				'name'         => 'Instructor',
				'capabilities' => array(
					'read'                        => true,
					'upload_files'                => true,
					Capabilities::EDIT_OWN_COURSE => true,
					Capabilities::VIEW_REPORTS    => true,
					Capabilities::ENROLL_STUDENTS => true,
				),
			),
			self::MANAGER    => array(
				'name'         => 'Course Manager',
				'capabilities' => array(
					'read'                        => true,
					'upload_files'                => true,
					Capabilities::MANAGE_COURSES  => true,
					Capabilities::EDIT_OWN_COURSE => true,
					Capabilities::VIEW_REPORTS    => true,
					Capabilities::MANAGE_ORDERS   => true,
					Capabilities::ENROLL_STUDENTS => true,
				),
			),
		);
	}

	/**
	 * Creates the roles, adds any missing capabilities, and grants every
	 * plugin capability to administrators.
	 *
	 * @return void
	 */
	public static function install(): void {
		foreach ( self::definitions() as $role_name => $definition ) {
			$role = get_role( $role_name );
			if ( null === $role ) {
				add_role( $role_name, $definition['name'], $definition['capabilities'] );
				continue;
			}
			foreach ( $definition['capabilities'] as $cap => $grant ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap, $grant );
				}
			}
		}

		$admin = get_role( 'administrator' );
		if ( null !== $admin ) {
			foreach ( Capabilities::ALL as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Removes the roles and strips the plugin's capabilities from every role.
	 *
	 * Only called on uninstall when the site owner opted in to removing data.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		foreach ( array_keys( self::definitions() ) as $role_name ) {
			remove_role( $role_name );
		}

		$roles = wp_roles();
		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = $roles->get_role( $role_name );
			if ( null === $role ) {
				continue;
			}
			foreach ( Capabilities::ALL as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
