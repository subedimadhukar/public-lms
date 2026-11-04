<?php
/**
 * Question bank routes.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Capabilities\Capabilities;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Quizzes\QuestionBank;
use PublicLMS\Quizzes\QuizSettings;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes (authors only; every response includes the answer key):
 *
 * - GET    /questions        The bank, newest first, with search and type filters and cursor pagination.
 *                            Instructors see their own questions; course managers see all.
 * - POST   /questions        Create a question; with `quiz_id`, also append it to that quiz.
 * - GET    /questions/{id}   One question. edit_post.
 * - PATCH  /questions/{id}   Change it; fields not sent keep their values. edit_post.
 * - DELETE /questions/{id}   Move it to the trash; quizzes skip it from then on. delete_post.
 */
final class QuestionsController extends AbstractController {

	/**
	 * Question bank.
	 *
	 * @var QuestionBank
	 */
	private $bank;

	/**
	 * Constructor.
	 *
	 * @param QuestionBank $bank Question bank.
	 */
	public function __construct( QuestionBank $bank ) {
		parent::__construct();
		$this->rest_base = 'questions';
		$this->bank      = $bank;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array_merge(
						array(
							'search' => array(
								'description'       => __( 'Limit to questions matching a string.', 'public-lms' ),
								'type'              => 'string',
								'default'           => '',
								'sanitize_callback' => 'sanitize_text_field',
								'validate_callback' => 'rest_validate_request_arg',
							),
							'type'   => array(
								'description'       => __( 'Limit to one question type.', 'public-lms' ),
								'type'              => 'string',
								'enum'              => array_merge( array( '' ), QuestionBank::TYPES ),
								'default'           => '',
								'sanitize_callback' => 'sanitize_key',
								'validate_callback' => 'rest_validate_request_arg',
							),
							'quiz'   => array(
								'description'       => __( 'Leave out the questions already in this quiz.', 'public-lms' ),
								'type'              => 'integer',
								'default'           => 0,
								'minimum'           => 0,
								'sanitize_callback' => 'absint',
								'validate_callback' => 'rest_validate_request_arg',
							),
						),
						$this->cursor_collection_params()
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array_merge(
						$this->write_args(),
						array(
							'quiz_id' => $this->id_arg( __( 'Append the new question to this quiz.', 'public-lms' ), false ),
						)
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
					'id' => $this->id_arg( __( 'Question ID.', 'public-lms' ) ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
					'args'                => $this->write_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Needs the authoring capability.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return current_user_can( Capabilities::EDIT_OWN_COURSE ) ? true : $this->forbidden();
	}

	/**
	 * Lists the bank.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$per_page = (int) $request['per_page'];
		$args     = array(
			'post_type'        => PostTypes::QUESTION,
			'post_status'      => 'publish',
			'posts_per_page'   => $per_page + 1,
			'orderby'          => 'ID',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		);
		if ( ! current_user_can( Capabilities::MANAGE_COURSES ) ) {
			$args['author'] = get_current_user_id();
		}
		if ( '' !== (string) $request['search'] ) {
			$args['s'] = (string) $request['search'];
		}
		if ( '' !== (string) $request['type'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Editor-only filter of the question bank.
			$args['meta_query'] = array(
				array(
					'key'     => QuestionBank::DATA_META,
					'value'   => 's:4:"type";s:' . strlen( (string) $request['type'] ) . ':"' . $request['type'] . '";',
					'compare' => 'LIKE',
				),
			);
		}
		$exclude = (int) $request['quiz'] > 0 ? QuizSettings::question_ids( (int) $request['quiz'] ) : array();
		if ( ! empty( $exclude ) ) {
			$args['post__not_in'] = $exclude; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- A quiz holds few questions.
		}

		$count_args                   = $args;
		$count_args['fields']         = 'ids';
		$count_args['posts_per_page'] = 1;
		$count_args['no_found_rows']  = false;
		$total                        = (int) ( new WP_Query( $count_args ) )->found_posts;

		$cursor = $this->decode_cursor( (string) $request['cursor'] );
		$where  = null;
		if ( null !== $cursor ) {
			$before = (int) $cursor[1];
			$where  = static function ( $sql ) use ( $before ) {
				global $wpdb;
				return $sql . $wpdb->prepare( " AND {$wpdb->posts}.ID < %d", $before );
			};
			add_filter( 'posts_where', $where );
		}
		$posts = ( new WP_Query( $args ) )->posts;
		if ( null !== $where ) {
			remove_filter( 'posts_where', $where );
		}

		$next = '';
		if ( count( $posts ) > $per_page ) {
			$posts = array_slice( $posts, 0, $per_page );
			$last  = end( $posts );
			$next  = $last instanceof \WP_Post ? $this->encode_cursor( (int) $last->ID, (int) $last->ID ) : '';
		}
		$items = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$question = $this->bank->get( (int) $post->ID );
				if ( null !== $question ) {
					$items[] = $this->prepare( $question, false );
				}
			}
		}
		return $this->paginated_response( $items, $total, $next, $request );
	}

	/**
	 * Needs the question type's create capability, and edit rights on the target quiz.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$type = get_post_type_object( PostTypes::QUESTION );
		if ( null === $type || ! current_user_can( $type->cap->create_posts ) ) {
			return $this->forbidden();
		}
		if ( isset( $request['quiz_id'] ) ) {
			$quiz_id = (int) $request['quiz_id'];
			if ( PostTypes::QUIZ !== get_post_type( $quiz_id ) ) {
				return $this->not_found();
			}
			if ( ! current_user_can( 'edit_post', $quiz_id ) ) {
				return $this->forbidden();
			}
		}
		return true;
	}

	/**
	 * Creates a question.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$clean = $this->bank->sanitize( $this->input( $request, array() ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$id = $this->bank->save( $clean );
		if ( is_wp_error( $id ) ) {
			$id->add_data( array( 'status' => 500 ) );
			return $id;
		}
		if ( isset( $request['quiz_id'] ) ) {
			$quiz_id = (int) $request['quiz_id'];
			$ids     = QuizSettings::question_ids( $quiz_id );
			$ids[]   = $id;
			QuizSettings::set_question_ids( $quiz_id, $ids );
		}
		$question = $this->bank->get( $id );
		$response = rest_ensure_response( null === $question ? array() : $this->prepare( $question, true ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Needs edit_post on the question.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function item_permissions_check( $request ) {
		$id = (int) $request['id'];
		if ( PostTypes::QUESTION !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) {
			return $this->not_found();
		}
		return current_user_can( 'edit_post', $id ) ? true : $this->forbidden();
	}

	/**
	 * Returns a question with its answer key.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$question = $this->bank->get( (int) $request['id'] );
		return null === $question ? $this->not_found() : rest_ensure_response( $this->prepare( $question, true ) );
	}

	/**
	 * Updates a question; missing fields keep their stored values.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id      = (int) $request['id'];
		$current = $this->bank->get( $id );
		if ( null === $current ) {
			return $this->not_found();
		}
		$clean = $this->bank->sanitize( $this->input( $request, $current ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$saved = $this->bank->save( $clean, $id );
		if ( is_wp_error( $saved ) ) {
			$saved->add_data( array( 'status' => 500 ) );
			return $saved;
		}
		$question = $this->bank->get( $id );
		return rest_ensure_response( null === $question ? array() : $this->prepare( $question, true ) );
	}

	/**
	 * Needs delete_post on the question.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		$id = (int) $request['id'];
		if ( PostTypes::QUESTION !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) {
			return $this->not_found();
		}
		return current_user_can( 'delete_post', $id ) ? true : $this->forbidden();
	}

	/**
	 * Moves a question to the trash.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$id       = (int) $request['id'];
		$question = $this->bank->get( $id );
		if ( null === $question || ! wp_trash_post( $id ) instanceof \WP_Post ) {
			return new WP_Error( 'publiclms_rest_cannot_delete', __( 'The question could not be deleted.', 'public-lms' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response(
			array(
				'deleted'  => true,
				'previous' => $this->prepare( $question, true ),
			)
		);
	}

	/**
	 * Merges request fields over current values for sanitize().
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @param array<string, mixed>                  $current Stored question, or empty when creating.
	 * @return array<string, mixed>
	 */
	private function input( $request, array $current ): array {
		$input = $current;
		foreach ( array( 'type', 'text', 'points', 'explanation', 'options', 'answer', 'template', 'accepted', 'pairs' ) as $field ) {
			if ( isset( $request[ $field ] ) ) {
				$input[ $field ] = $request[ $field ];
			}
		}
		return $input;
	}

	/**
	 * Prepares a question for an author.
	 *
	 * @param array<string, mixed> $question Question with its answer key.
	 * @param bool                 $usage    Whether to list the quizzes using it.
	 * @return array<string, mixed>
	 */
	private function prepare( array $question, bool $usage ): array {
		$data = array(
			'id'          => (int) $question['id'],
			'type'        => (string) $question['type'],
			'title'       => (string) $question['title'],
			'text'        => (string) $question['text'],
			'points'      => (float) $question['points'],
			'explanation' => (string) $question['explanation'],
			'options'     => array_values( (array) $question['options'] ),
			'answer'      => (bool) $question['answer'],
			'template'    => (string) $question['template'],
			'accepted'    => array_values( (array) $question['accepted'] ),
			'pairs'       => array_values( (array) $question['pairs'] ),
			'author'      => (int) $question['author'],
		);
		if ( $usage ) {
			$data['used_in'] = array_map(
				static function ( $quiz_id ) {
					return array(
						'id'    => $quiz_id,
						'title' => wp_strip_all_tags( get_the_title( $quiz_id ) ),
					);
				},
				$this->bank->quizzes_using( (int) $question['id'] )
			);
		}
		return $data;
	}

	/**
	 * Returns the write arguments; validation of the answer key happens in QuestionBank::sanitize().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function write_args(): array {
		return array(
			'type'        => array(
				'description' => __( 'Question type.', 'public-lms' ),
				'type'        => 'string',
				'enum'        => QuestionBank::TYPES,
			),
			'text'        => array(
				'description' => __( 'The question, as HTML.', 'public-lms' ),
				'type'        => 'string',
				'maxLength'   => 10000,
			),
			'points'      => array(
				'description' => __( 'Points for a full answer.', 'public-lms' ),
				'type'        => 'number',
				'minimum'     => 0,
				'maximum'     => 1000,
			),
			'explanation' => array(
				'description' => __( 'Shown after submitting, when the quiz shows results.', 'public-lms' ),
				'type'        => 'string',
				'maxLength'   => 10000,
			),
			'options'     => array(
				'description' => __( 'Single and multiple choice: answers with a correct flag.', 'public-lms' ),
				'type'        => 'array',
				'maxItems'    => QuestionBank::MAX_CHOICES,
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'string' ),
						'text'    => array( 'type' => 'string' ),
						'correct' => array( 'type' => 'boolean' ),
					),
				),
			),
			'answer'      => array(
				'description' => __( 'True or false: the right answer.', 'public-lms' ),
				'type'        => 'boolean',
			),
			'template'    => array(
				'description' => __( 'Fill in the blank: the sentence with each answer in {{double braces}}.', 'public-lms' ),
				'type'        => 'string',
				'maxLength'   => 2000,
			),
			'accepted'    => array(
				'description' => __( 'Short answer: accepted answers.', 'public-lms' ),
				'type'        => 'array',
				'maxItems'    => 20,
				'items'       => array( 'type' => 'string' ),
			),
			'pairs'       => array(
				'description' => __( 'Matching: prompts and their matches.', 'public-lms' ),
				'type'        => 'array',
				'maxItems'    => QuestionBank::MAX_CHOICES,
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'prompt_id' => array( 'type' => 'string' ),
						'prompt'    => array( 'type' => 'string' ),
						'match_id'  => array( 'type' => 'string' ),
						'match'     => array( 'type' => 'string' ),
					),
				),
			),
		);
	}

	/**
	 * Returns the item schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'publiclms-question',
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'id'      => array(
						'type'     => 'integer',
						'readonly' => true,
					),
					'title'   => array(
						'type'     => 'string',
						'readonly' => true,
					),
					'author'  => array(
						'type'     => 'integer',
						'readonly' => true,
					),
					'used_in' => array(
						'type'     => 'array',
						'readonly' => true,
						'items'    => array( 'type' => 'object' ),
					),
				),
				$this->write_args()
			),
		);
		return $this->add_additional_fields_schema( $this->schema );
	}
}
