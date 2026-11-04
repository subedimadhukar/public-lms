<?php
/**
 * REST namespace registration.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Rest;

use PublicLMS\Access\AccessPolicy;
use PublicLMS\Core\Container;
use PublicLMS\Courses\CourseFields;
use PublicLMS\Repositories\CourseStructureRepositoryInterface;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Services\EnrollmentService;
use PublicLMS\Quizzes\QuestionBank;
use PublicLMS\Repositories\QuizAttemptRepository;
use PublicLMS\Repositories\SubmissionRepository;
use PublicLMS\Certificates\CertificateRenderer;
use PublicLMS\Certificates\CertificateService;
use PublicLMS\Services\AssignmentService;
use PublicLMS\Services\Gradebook;
use PublicLMS\Services\ProgressService;
use PublicLMS\Services\QuizService;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the publiclms/v1 namespace and its controllers.
 */
final class Api {

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Hooks route registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( FileResponse::class, 'serve' ), 10, 2 );
	}

	/**
	 * Registers every controller's routes. Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		foreach ( $this->controllers() as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Returns the controllers to register.
	 *
	 * @return array<int, \WP_REST_Controller>
	 */
	public function controllers(): array {
		$c           = $this->container;
		$structure   = $c->get( CourseStructureRepositoryInterface::class );
		$enrollments = $c->get( EnrollmentRepository::class );
		$policy      = $c->get( AccessPolicy::class );
		$progress    = $c->get( ProgressService::class );
		$fields      = $c->get( CourseFields::class );

		$controllers = array(
			new HealthController(),
			new CoursesController( $structure, $enrollments, $policy, $progress, $fields ),
			new SectionsController( $structure ),
			new LessonsController( $structure, $policy, $progress ),
			new QuizzesController( $structure, $policy, $progress, $c->get( QuestionBank::class ), $c->get( QuizService::class ) ),
			new QuestionsController( $c->get( QuestionBank::class ) ),
			new QuizAttemptsController( $c->get( QuizService::class ), $c->get( QuizAttemptRepository::class ), $structure ),
			new AssignmentsController( $structure, $policy, $progress, $c->get( AssignmentService::class ), $c->get( SubmissionRepository::class ) ),
			new SubmissionsController( $c->get( AssignmentService::class ), $c->get( SubmissionRepository::class ), $structure ),
			new GradebookController( $c->get( Gradebook::class ) ),
			new CertificatesController( $c->get( CertificateService::class ), $c->get( CertificateRenderer::class ), $policy ),
			new EnrollmentsController( $enrollments, $c->get( EnrollmentService::class ), $progress, $fields ),
			new ProgressController( $progress, $enrollments, $policy ),
			new StudentsController( $enrollments, $progress ),
			new SettingsController(),
		);

		/**
		 * Filters the REST controllers registered under publiclms/v1.
		 *
		 * Pro and add-ons append their controllers here.
		 *
		 * @param array<int, \WP_REST_Controller> $controllers Controller instances.
		 * @param Container                       $container   Service container.
		 */
		$controllers = (array) apply_filters( 'publiclms_rest_controllers', $controllers, $this->container );

		return array_values(
			array_filter(
				$controllers,
				static function ( $controller ) {
					return $controller instanceof \WP_REST_Controller;
				}
			)
		);
	}
}
