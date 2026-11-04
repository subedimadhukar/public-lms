/**
 * The Gradebook screen: one course's learners by quizzes and assignments,
 * with the CSV export (free, ADR-019, ADR-043).
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, SelectControl, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs, getQueryArg } from '@wordpress/url';

import { STORE_NAME } from '../../store/courses';
import { boot } from '../boot';
import { notifyError } from '../components/notices';
import GradingModal from './grading-modal';

const COURSES_QUERY = {
	per_page: 100,
	status: [ 'any' ],
	context: 'edit',
	orderby: 'title',
	order: 'asc',
};

/**
 * Formats one gradebook cell.
 *
 * @param {Object} column Column.
 * @param {Object} cell   Cell.
 * @return {string} Text.
 */
function cellText( column, cell ) {
	if ( ! cell || cell.status === 'none' ) {
		return '—';
	}
	if ( column.type === 'quiz' ) {
		return `${ Math.round( cell.percent ) }%`;
	}
	if ( cell.status === 'submitted' ) {
		return __( 'To grade', 'public-lms' );
	}
	return `${ cell.grade } / ${ cell.max }`;
}

export default function GradebookScreen() {
	const courses = useSelect(
		( select ) =>
			select( STORE_NAME ).getCourseList( COURSES_QUERY )?.items,
		[]
	);
	const [ courseId, setCourseId ] = useState(
		Number( getQueryArg( window.location.href, 'course' ) ) || 0
	);
	const [ book, setBook ] = useState( null );
	const [ grading, setGrading ] = useState( null );

	useEffect( () => {
		if ( ! courseId && courses?.length ) {
			setCourseId( courses[ 0 ].id );
		}
	}, [ courseId, courses ] );

	const load = useCallback( () => {
		if ( ! courseId ) {
			return;
		}
		setBook( null );
		apiFetch( {
			path: `/publiclms/v1/courses/${ courseId }/gradebook?per_page=100`,
		} )
			.then( setBook )
			.catch( ( error ) => {
				notifyError( error );
				setBook( { columns: [], rows: [] } );
			} );
	}, [ courseId ] );

	useEffect( load, [ load ] );

	const openSubmission = async ( id ) => {
		try {
			setGrading(
				await apiFetch( { path: `/publiclms/v1/submissions/${ id }` } )
			);
		} catch ( error ) {
			notifyError( error );
		}
	};

	const csvUrl = courseId
		? addQueryArgs(
				`${ boot.restUrl }publiclms/v1/courses/${ courseId }/gradebook`,
				{ format: 'csv', _wpnonce: boot.restNonce }
			)
		: '';

	const more = () =>
		apiFetch( {
			path: `/publiclms/v1/courses/${ courseId }/gradebook?per_page=100&after=${ book.next }`,
		} )
			.then( ( page ) =>
				setBook( ( current ) => ( {
					...page,
					rows: [ ...current.rows, ...page.rows ],
				} ) )
			)
			.catch( notifyError );

	return (
		<div className="publiclms-screen publiclms-gradebook">
			<div className="publiclms-screen__header">
				<h2 className="publiclms-screen__title">
					{ __( 'Gradebook', 'public-lms' ) }
				</h2>
				<div className="publiclms-screen__header-actions">
					{ csvUrl && (
						<Button variant="secondary" href={ csvUrl }>
							{ __( 'Export CSV', 'public-lms' ) }
						</Button>
					) }
				</div>
			</div>
			<div className="publiclms-bank__filters">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Course', 'public-lms' ) }
					value={ String( courseId ) }
					options={ ( courses || [] ).map( ( course ) => ( {
						value: String( course.id ),
						label: decodeEntities( course.title ),
					} ) ) }
					onChange={ ( value ) => setCourseId( Number( value ) ) }
				/>
			</div>
			{ courses?.length === 0 && (
				<p className="publiclms-builder__empty">
					{ __( 'No courses yet.', 'public-lms' ) }
				</p>
			) }
			{ courseId > 0 && ! book && <Spinner /> }
			{ book && book.rows.length === 0 && (
				<p className="publiclms-builder__empty">
					{ __( 'No learners in this course yet.', 'public-lms' ) }
				</p>
			) }
			{ book && book.rows.length > 0 && (
				<div className="publiclms-gradebook__scroll">
					<table className="widefat striped publiclms-gradebook__table">
						<caption className="screen-reader-text">
							{ __(
								'Learners by quizzes and assignments',
								'public-lms'
							) }
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Learner', 'public-lms' ) }
								</th>
								<th scope="col">
									{ __( 'Progress', 'public-lms' ) }
								</th>
								{ book.columns.map( ( column ) => (
									<th scope="col" key={ column.id }>
										{ decodeEntities( column.title ) }
										<span className="publiclms-gradebook__kind">
											{ column.type === 'quiz'
												? __( 'Quiz', 'public-lms' )
												: sprintf(
														/* translators: %s: highest grade. */
														__(
															'Assignment, out of %s',
															'public-lms'
														),
														column.max
													) }
										</span>
									</th>
								) ) }
								<th scope="col">
									{ __( 'Average', 'public-lms' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ book.rows.map( ( row ) => (
								<tr key={ row.enrollment_id }>
									<th scope="row">
										{ row.name }
										<span className="publiclms-gradebook__kind">
											{ row.email }
										</span>
									</th>
									<td>{ row.progress }%</td>
									{ book.columns.map( ( column ) => {
										const cell = row.cells[ column.id ];
										const text = cellText( column, cell );
										return (
											<td
												key={ column.id }
												className={
													cell?.passed === false
														? 'is-failed'
														: undefined
												}
											>
												{ cell?.submission_id ? (
													<Button
														variant="link"
														onClick={ () =>
															openSubmission(
																cell.submission_id
															)
														}
													>
														{ text }
													</Button>
												) : (
													text
												) }
											</td>
										);
									} ) }
									<td>
										{ row.average === null
											? '—'
											: `${ row.average }%` }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					{ book.next > 0 && (
						<Button variant="secondary" onClick={ more }>
							{ __( 'Load more learners', 'public-lms' ) }
						</Button>
					) }
				</div>
			) }
			{ grading && (
				<GradingModal
					submission={ grading }
					onClose={ () => setGrading( null ) }
					onGraded={ () => {
						setGrading( null );
						load();
					} }
				/>
			) }
		</div>
	);
}
