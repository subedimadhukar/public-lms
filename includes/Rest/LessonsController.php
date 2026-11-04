<?php
/**
 * Lesson routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use WP_Error;
use WP_Post;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /lessons (see AbstractItemController for the shared ones).
 *
 * Lessons add `attachments`: media library files listed as downloads under
 * the lesson for learners who can open it.
 */
final class LessonsController extends AbstractItemController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'lessons';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function item_type(): string {
		return 'lesson';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function post_type(): string {
		return PostTypes::LESSON;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function settings_args(): array {
		return array(
			'attachments' => array(
				'description'       => __( 'Media library attachment IDs offered as downloads under the lesson.', 'public-lms' ),
				'type'              => 'array',
				'items'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'maxItems'          => 20,
				'sanitize_callback' => array( Meta::class, 'sanitize_id_list' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                                   $item_id Lesson ID.
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	protected function apply_settings( int $item_id, $request ) {
		if ( ! isset( $request['attachments'] ) ) {
			return true;
		}
		$ids = Meta::sanitize_id_list( $request['attachments'] );
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'read_post', $id ) ) {
				/* translators: %d: attachment ID. */
				return new WP_Error( 'rest_invalid_param', sprintf( __( 'Attachment %d is not in the media library.', 'public-lms' ), $id ), array( 'status' => 400 ) );
			}
		}
		update_post_meta( $item_id, Meta::ATTACHMENTS, $ids );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post $post     Lesson.
	 * @param bool    $can_edit Whether the current user can edit the lesson's course.
	 * @return array<string, mixed>
	 */
	protected function settings_data( WP_Post $post, bool $can_edit ): array {
		$allowed = $can_edit || $this->policy->can_access_item( (int) $post->ID )->is_allowed();
		return array(
			'attachments' => $allowed ? self::attachments( (int) $post->ID ) : array(),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function settings_schema(): array {
		return array(
			'attachments' => array(
				'description' => __( 'Downloads under the lesson; empty while the lesson is locked.', 'public-lms' ),
				'type'        => 'array',
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer' ),
						'title'    => array( 'type' => 'string' ),
						'url'      => array( 'type' => 'string' ),
						'filename' => array( 'type' => 'string' ),
						'size'     => array( 'type' => 'string' ),
						'mime'     => array( 'type' => 'string' ),
					),
				),
			),
		);
	}

	/**
	 * Returns a lesson's attachments that still exist, in order.
	 *
	 * @param int $lesson_id Lesson ID.
	 * @return array<int, array{id: int, title: string, url: string, filename: string, size: string, mime: string}>
	 */
	public static function attachments( int $lesson_id ): array {
		$list = array();
		foreach ( Meta::id_list( $lesson_id, Meta::ATTACHMENTS ) as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) {
				continue;
			}
			$file   = (string) get_attached_file( $id );
			$bytes  = '' !== $file && is_readable( $file ) ? (int) filesize( $file ) : 0;
			$list[] = array(
				'id'       => $id,
				'title'    => wp_strip_all_tags( get_the_title( $id ) ),
				'url'      => (string) wp_get_attachment_url( $id ),
				'filename' => wp_basename( $file ),
				'size'     => $bytes > 0 ? (string) size_format( $bytes ) : '',
				'mime'     => (string) get_post_mime_type( $id ),
			);
		}
		return $list;
	}
}
