<?php
/**
 * Tests for certificate issue, rendering and download.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Certificates;

use PublicLMS\Certificates\CertificateRenderer;
use PublicLMS\Certificates\CertificateService;
use PublicLMS\Certificates\CertificateTemplate;
use PublicLMS\PostTypes\Meta;
use PublicLMS\Repositories\EnrollmentRepository;
use PublicLMS\Rest\FileResponse;
use PublicLMS\Services\ProgressService;
use PublicLMS\Tests\Rest\RestTestCase;

/**
 * @covers \PublicLMS\Certificates\CertificateService
 * @covers \PublicLMS\Certificates\CertificateRenderer
 * @covers \PublicLMS\Certificates\CertificateFields
 * @covers \PublicLMS\Certificates\CertificateTemplate
 * @covers \PublicLMS\Certificates\DashboardTab
 * @covers \PublicLMS\Rest\CertificatesController
 */
class CertificatesTest extends RestTestCase {

	/**
	 * Completes every lesson of the fixture course for the subscriber.
	 *
	 * @return void
	 */
	private function complete_course(): void {
		publiclms()->container()->get( EnrollmentRepository::class )->enroll( $this->subscriber, $this->fixture['course'] );
		foreach ( $this->fixture['lessons'] as $lesson ) {
			publiclms()->container()->get( ProgressService::class )->complete_item( $this->subscriber, $lesson );
		}
	}

	public function test_completing_a_course_issues_one_certificate() {
		$issued = array();
		add_action(
			'publiclms_certificate_issued',
			static function ( $certificate ) use ( &$issued ) {
				$issued[] = $certificate;
			}
		);
		$this->complete_course();

		$this->assertCount( 1, $issued );
		$certificate = $issued[0];
		$this->assertMatchesRegularExpression( '/^PLMS-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $certificate['certificate_key'] );
		$this->assertSame( 'Lucía Fernández', $certificate['learner_name'] );
		$this->assertSame( 'Introduction to Statistics', $certificate['course_title'] );

		$service = publiclms()->container()->get( CertificateService::class );
		$this->assertSame( $certificate['id'], $service->issue( $this->subscriber, $this->fixture['course'] )['id'], 'Issuing again returns the same certificate.' );
		wp_update_user(
			array(
				'ID'           => $this->subscriber,
				'display_name' => 'Lucía F.',
			)
		);
		$this->assertSame( 'Lucía Fernández', $service->issue( $this->subscriber, $this->fixture['course'] )['learner_name'], 'An issued certificate keeps its text.' );
	}

	public function test_courses_without_certificates_issue_none() {
		update_post_meta( $this->fixture['course'], Meta::CERTIFICATE, '0' );
		$this->complete_course();
		$this->assertNull( publiclms()->container()->get( CertificateService::class )->issue( $this->subscriber, $this->fixture['course'] ) );
		$this->assertSame( 404, $this->request( 'GET', '/certificates/' . $this->fixture['course'], array(), $this->subscriber )->get_status() );
	}

	public function test_the_template_is_filled_from_the_certificate() {
		$this->complete_course();
		$certificate = publiclms()->container()->get( CertificateService::class )->issue( $this->subscriber, $this->fixture['course'] );
		$html        = publiclms()->container()->get( CertificateRenderer::class )->html( $certificate );

		$this->assertStringContainsString( 'Lucía Fernández', $html );
		$this->assertStringContainsString( 'Introduction to Statistics', $html );
		$this->assertStringContainsString( $certificate['certificate_key'], $html );
		$this->assertStringContainsString( wp_date( (string) get_option( 'date_format' ) ), $html );
		$this->assertStringNotContainsString( 'Learner name', $html, 'Placeholders are replaced.' );

		$template = get_post( publiclms()->container()->get( CertificateTemplate::class )->id() );
		$this->assertSame( 'plms_certificate', $template->post_type );
		$this->assertStringContainsString( 'publiclms/certificate-field', $template->post_content );
		$this->assertStringContainsString( 'Learner name', do_blocks( $template->post_content ), 'Outside a render the placeholders stay.' );
	}

	public function test_learners_download_their_certificate_as_a_pdf() {
		$this->assertTrue( CertificateRenderer::available(), 'The prefixed Dompdf is installed.' );
		$this->assertSame( 401, $this->request( 'GET', '/certificates/' . $this->fixture['course'] )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/certificates/' . $this->fixture['course'], array(), $this->subscriber )->get_status(), 'Not before completing the course.' );

		$this->complete_course();
		$pdf = $this->request( 'GET', '/certificates/' . $this->fixture['course'], array(), $this->subscriber );
		$this->assertInstanceOf( FileResponse::class, $pdf );
		$this->assertSame( 'application/pdf', $pdf->mime );
		$this->assertStringStartsWith( '%PDF-', $pdf->contents() );
		$this->assertSame( 'introduction-to-statistics-certificate.pdf', $pdf->file_name );

		$this->assertSame( 404, $this->request( 'GET', '/certificates/' . $this->fixture['course'], array( 'user_id' => $this->subscriber ), $this->other_instructor )->get_status() );
		$this->assertInstanceOf( FileResponse::class, $this->request( 'GET', '/certificates/' . $this->fixture['course'], array( 'user_id' => $this->subscriber ), $this->instructor ) );
		$this->assertSame( 403, $this->request( 'GET', '/certificates/preview', array(), $this->instructor )->get_status() );
		$this->assertStringStartsWith( '%PDF-', $this->request( 'GET', '/certificates/preview', array(), $this->manager )->contents() );

		$mine = $this->request( 'GET', '/certificates/me', array(), $this->subscriber )->get_data();
		$this->assertSame( array( $this->fixture['course'] ), wp_list_pluck( $mine, 'course_id' ) );
		$this->assertStringContainsString( '_wpnonce=', $mine[0]['download_url'] );
	}

	public function test_earlier_completions_get_their_certificate_on_request() {
		$enrollments = publiclms()->container()->get( EnrollmentRepository::class );
		$enrollments->enroll( $this->subscriber, $this->fixture['course'] );
		$enrollments->set_completed( $this->subscriber, $this->fixture['course'], '2026-01-15 10:00:00' );

		$mine = $this->request( 'GET', '/certificates/me', array(), $this->subscriber )->get_data();
		$this->assertCount( 1, $mine );
		$this->assertSame( '2026-01-15T10:00:00Z', $mine[0]['completed_at'] );
	}

	public function test_the_dashboard_lists_certificates() {
		$this->complete_course();
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'publiclms/student-dashboard' ) ) {
			$this->markTestSkipped( 'Blocks are not built; run npm run build.' );
		}
		wp_set_current_user( $this->subscriber );
		$html = do_blocks( '<!-- wp:publiclms/student-dashboard {"defaultTab":"certificates"} /-->' );
		$this->assertStringContainsString( 'Certificate ID', $html );
		$this->assertStringContainsString( 'Download PDF', $html );
		$this->assertStringContainsString( '/publiclms/v1/certificates/' . $this->fixture['course'], rawurldecode( $html ) );
	}
}
