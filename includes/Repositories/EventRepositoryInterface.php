<?php
/**
 * Event log repository contract.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Appends to plms_events. Rows are never updated; reports, notifications and
 * webhooks read from this log.
 */
interface EventRepositoryInterface {

	/**
	 * Appends an event.
	 *
	 * @param string               $event_type Dotted event name, for example 'lesson.completed'.
	 * @param array<string, mixed> $context    Optional user_id, course_id, object_type, object_id and payload.
	 * @return int The event ID, or 0 on failure.
	 */
	public function record( string $event_type, array $context = array() ): int;

	/**
	 * Lists events after an ID, oldest first, for incremental processing.
	 *
	 * @param int $after_id Return events with an ID greater than this.
	 * @param int $limit    Maximum events to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function since( int $after_id, int $limit = 500 ): array;
}
