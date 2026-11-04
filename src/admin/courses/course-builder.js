/**
 * The course builder: curriculum tree and settings for one course.
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { arrowLeft, external } from '@wordpress/icons';
import { addQueryArgs } from '@wordpress/url';

import { STORE_NAME } from '../../store/courses';
import { boot } from '../boot';
import AssignmentSettings from '../assignments/assignment-settings';
import CourseSettings from './course-settings';
import CurriculumTree from './curriculum-tree';

const STATUS_LABELS = {
	publish: __( 'Published', 'public-lms' ),
	future: __( 'Scheduled', 'public-lms' ),
	draft: __( 'Draft', 'public-lms' ),
	pending: __( 'Pending review', 'public-lms' ),
	private: __( 'Private', 'public-lms' ),
};

export default function CourseBuilder( { courseId } ) {
	const [ assignment, setAssignment ] = useState( null );
	const { course, curriculum, hasLoaded, error } = useSelect(
		( select ) => {
			const store = select( STORE_NAME );
			return {
				course: store.getCourse( courseId ),
				curriculum: store.getCurriculum( courseId ),
				hasLoaded: store.hasFinishedResolution( 'getCourse', [
					courseId,
				] ),
				error: store.getResolutionError?.( 'getCourse', [ courseId ] ),
			};
		},
		[ courseId ]
	);

	if ( ! hasLoaded ) {
		return (
			<div className="publiclms-screen publiclms-screen--loading">
				<Spinner />
			</div>
		);
	}

	if ( error || ! course || ! curriculum ) {
		return (
			<div className="publiclms-screen">
				<Notice status="error" isDismissible={ false }>
					{ error?.message ||
						__( 'This course could not be loaded.', 'public-lms' ) }
				</Notice>
				<Button variant="link" href={ boot.appUrl }>
					{ __( 'Back to courses', 'public-lms' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="publiclms-screen publiclms-builder">
			<div className="publiclms-screen__header">
				<Button
					variant="tertiary"
					icon={ arrowLeft }
					href={ boot.appUrl }
					className="publiclms-builder__back"
				>
					{ __( 'All courses', 'public-lms' ) }
				</Button>
				<h2 className="publiclms-screen__title">
					{ decodeEntities( course.title ) ||
						__( '(no title)', 'public-lms' ) }
				</h2>
				<span className={ `publiclms-status is-${ course.status }` }>
					{ STATUS_LABELS[ course.status ] || course.status }
				</span>
				<div className="publiclms-screen__header-actions">
					{ course.edit_url && (
						<Button variant="secondary" href={ course.edit_url }>
							{ __( 'Edit course page', 'public-lms' ) }
						</Button>
					) }
					<Button
						variant="tertiary"
						icon={ external }
						iconPosition="right"
						href={ course.link }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ course.status === 'publish'
							? __( 'View course', 'public-lms' )
							: __( 'Preview course', 'public-lms' ) }
					</Button>
				</div>
			</div>
			<div className="publiclms-builder__layout">
				<section
					className="publiclms-builder__curriculum"
					aria-labelledby="publiclms-curriculum-heading"
				>
					<h3
						id="publiclms-curriculum-heading"
						className="publiclms-builder__heading"
					>
						{ __( 'Curriculum', 'public-lms' ) }
					</h3>
					<CurriculumTree
						courseId={ courseId }
						curriculum={ curriculum }
						itemTypes={ [ 'lesson', 'quiz', 'assignment' ] }
						renderItemActions={ ( item ) => {
							if ( item.type === 'quiz' ) {
								return (
									<Button
										variant="secondary"
										size="compact"
										href={ addQueryArgs( boot.appUrl, {
											course: courseId,
											quiz: item.id,
										} ) }
									>
										{ __( 'Edit quiz', 'public-lms' ) }
									</Button>
								);
							}
							if ( item.type === 'assignment' ) {
								return (
									<>
										<Button
											variant="secondary"
											size="compact"
											onClick={ () =>
												setAssignment( item )
											}
										>
											{ __( 'Settings', 'public-lms' ) }
										</Button>
										{ item.edit_url && (
											<Button
												variant="tertiary"
												size="compact"
												href={ item.edit_url }
											>
												{ __(
													'Edit brief',
													'public-lms'
												) }
											</Button>
										) }
									</>
								);
							}
							return null;
						} }
					/>
					{ assignment && (
						<AssignmentSettings
							courseId={ courseId }
							item={ assignment }
							onClose={ () => setAssignment( null ) }
						/>
					) }
				</section>
				<aside className="publiclms-builder__aside">
					<CourseSettings
						key={ course.modified_gmt }
						course={ course }
					/>
				</aside>
			</div>
		</div>
	);
}
