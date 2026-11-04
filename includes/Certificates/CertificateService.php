<?php
/**
 * Certificate service.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Certificates;

use PublicLMS\PostTypes\Meta;
use PublicLMS\PostTypes\PostTypes;
use PublicLMS\Repositories\CertificateRepository;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Repositories\EventRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Issues certificates when learners complete courses (ADR-044).
 *
 * Issuing writes one row with what the certificate says; the PDF is
 * rendered when it is downloaded, from the site's single template (free;
 * multiple templates and a public verification page are Pro). A learner who
 * completed a course before certificates existed gets theirs the first time
 * they ask for it.
 */
class CertificateService {

	/**
	 * Characters of a certificate key: no 0, O, 1 or I, which read alike.
	 */
	const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	/**
	 * Certificates.
	 *
	 * @var CertificateRepository
	 */
	private $certificates;

	/**
	 * Enrolments.
	 *
	 * @var EnrollmentRepository
	 */
	private $enrollments;

	/**
	 * Event log.
	 *
	 * @var EventRepositoryInterface
	 */
	private $events;

	/**
	 * Template.
	 *
	 * @var CertificateTemplate
	 */
	private $template;

	/**
	 * Constructor.
	 *
	 * @param CertificateRepository    $certificates Certificates.
	 * @param EnrollmentRepository     $enrollments  Enrolments.
	 * @param EventRepositoryInterface $events       Event log.
	 * @param CertificateTemplate      $template     Template.
	 */
	public function __construct( CertificateRepository $certificates, EnrollmentRepository $enrollments, EventRepositoryInterface $events, CertificateTemplate $template ) {
		$this->certificates = $certificates;
		$this->enrollments  = $enrollments;
		$this->events       = $events;
		$this->template     = $template;
	}

	/**
	 * Hooks issuing to course completion.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'publiclms_course_completed', array( $this, 'on_course_completed' ), 10, 2 );
	}

	/**
	 * Issues a certificate when a learner completes a course. Hooked to `publiclms_course_completed`.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 * @return void
	 */
	public function on_course_completed( $user_id, $course_id ): void {
		$this->issue( (int) $user_id, (int) $course_id );
	}

	/**
	 * Returns a learner's certificate for a course, issuing it when they completed the course.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 * @return array<string, mixed>|null Null when the course is not complete or gives no certificate.
	 */
	public function issue( int $user_id, int $course_id ): ?array {
		$existing = $this->certificates->for_course( $user_id, $course_id );
		if ( null !== $existing ) {
			return empty( $existing['revoked_at'] ) ? $existing : null;
		}
		$enrolment = $this->enrollments->find_by_user_and_course( $user_id, $course_id );
		$user      = get_userdata( $user_id );
		if ( null === $enrolment || empty( $enrolment['completed_at'] ) || false === $user
			|| PostTypes::COURSE !== get_post_type( $course_id ) || ! Meta::issues_certificate( $course_id ) ) {
			return null;
		}

		$course = get_post( $course_id );
		$data   = array(
			'instructor' => $course instanceof \WP_Post ? (string) get_the_author_meta( 'display_name', (int) $course->post_author ) : '',
			'site'       => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
		);
		$id     = 0;
		for ( $try = 0; $try < 5 && 0 === $id; $try++ ) {
			$id = $this->certificates->insert(
				array(
					'certificate_key' => self::new_key(),
					'user_id'         => $user_id,
					'course_id'       => $course_id,
					'template_id'     => $this->template->id(),
					'learner_name'    => (string) $user->display_name,
					'course_title'    => wp_strip_all_tags( get_the_title( $course_id ) ),
					'completed_at'    => (string) $enrolment['completed_at'],
					'issued_at'       => gmdate( 'Y-m-d H:i:s' ),
					'data'            => (string) wp_json_encode( $data ),
				)
			);
		}
		if ( 0 === $id ) {
			return null;
		}
		$certificate = $this->certificates->find( $id );
		if ( null === $certificate ) {
			return null;
		}
		$this->events->record(
			'certificate.issued',
			array(
				'user_id'     => $user_id,
				'course_id'   => $course_id,
				'object_type' => 'certificate',
				'object_id'   => $id,
			)
		);

		/**
		 * Fires after a certificate was issued.
		 *
		 * @param array<string, mixed> $certificate The certificate row.
		 * @param int                  $user_id     Learner.
		 * @param int                  $course_id   Course.
		 */
		do_action( 'publiclms_certificate_issued', $certificate, $user_id, $course_id );
		return $certificate;
	}

	/**
	 * Returns a learner's certificates, issuing any owed for completed courses.
	 *
	 * @param int $user_id Learner.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( int $user_id ): array {
		foreach ( $this->enrollments->find_by_user( $user_id ) as $enrolment ) {
			if ( ! empty( $enrolment['completed_at'] ) ) {
				$this->issue( $user_id, (int) $enrolment['course_id'] );
			}
		}
		return array_values(
			array_filter(
				$this->certificates->for_user( $user_id ),
				static function ( $row ) {
					return empty( $row['revoked_at'] );
				}
			)
		);
	}

	/**
	 * Returns the download URL of a learner's certificate for a course, with the REST nonce.
	 *
	 * @param int $course_id Course.
	 * @return string
	 */
	public static function download_url( int $course_id ): string {
		return add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'publiclms/v1/certificates/' . $course_id ) );
	}

	/**
	 * Makes a new random key such as "PLMS-7K3F-Q9XM-2DWP".
	 *
	 * @return string
	 */
	public static function new_key(): string {
		$chars = '';
		for ( $i = 0; $i < 12; $i++ ) {
			$chars .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
		}
		return 'PLMS-' . implode( '-', str_split( $chars, 4 ) );
	}
}
