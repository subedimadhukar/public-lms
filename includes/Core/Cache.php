<?php
/**
 * Object cache helper.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over the object cache for the `publiclms` group.
 *
 * Course structure and per-user progress summaries are cached here and
 * invalidated by the repositories on every write (docs/ARCHITECTURE.md §6).
 * Keys are documented next to the code that owns them:
 *
 * - `structure:{course_id}`            CourseStructureRepository
 * - `enrollments:user:{user_id}`       EnrollmentRepository
 * - `enrolled_count:{course_id}`       EnrollmentRepository
 * - `progress_items:{user}:{course}`   ProgressRepository
 * - `course_progress:user:{user_id}`   ProgressRepository
 */
final class Cache {

	/**
	 * Object cache group shared by the plugin.
	 */
	const GROUP = 'publiclms';

	/**
	 * Reads a value.
	 *
	 * @param string    $key   Cache key.
	 * @param bool|null $found Set to whether the key was found, which tells a cached false from a miss.
	 * @return mixed The cached value, or false on a miss.
	 */
	public static function get( string $key, ?bool &$found = null ) {
		return wp_cache_get( $key, self::GROUP, false, $found );
	}

	/**
	 * Stores a value.
	 *
	 * @param string $key    Cache key.
	 * @param mixed  $value  Value to store.
	 * @param int    $expire Seconds until expiry; 0 keeps it until invalidated.
	 * @return bool
	 */
	public static function set( string $key, $value, int $expire = 0 ): bool {
		return wp_cache_set( $key, $value, self::GROUP, $expire );
	}

	/**
	 * Deletes a value.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public static function delete( string $key ): bool {
		return wp_cache_delete( $key, self::GROUP );
	}
}
