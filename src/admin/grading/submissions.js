/**
 * The Submissions screen: assignment hand-ins waiting for a grade (or
 * graded), newest first, across the courses the user teaches.
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, SelectControl, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { notifyError } from '../components/notices';
import GradingModal from './grading-modal';

/**
 * Describes a hand-in's state in words.
 *
 * @param {Object} row Hand-in.
 * @return {string} Status.
 */
function statusLabel( row ) {
	if ( row.status !== 'graded' ) {
		return __( 'Waiting for a grade', 'public-lms' );
	}
	return sprintf(
		/* translators: 1: grade, 2: highest grade. */
		__( '%1$s of %2$s', 'public-lms' ),
		row.grade,
		row.max_grade
	);
}

export default function SubmissionsScreen() {
	const [ status, setStatus ] = useState( 'submitted' );
	const [ rows, setRows ] = useState( null );
	const [ total, setTotal ] = useState( 0 );
	const [ next, setNext ] = useState( '' );
	const [ grading, setGrading ] = useState( null );

	const fetchPage = useCallback(
		async ( before ) => {
			const response = await apiFetch( {
				path: addQueryArgs( '/publiclms/v1/submissions', {
					status,
					before,
					per_page: 30,
				} ),
				parse: false,
			} );
			setTotal( Number( response.headers.get( 'X-WP-Total' ) || 0 ) );
			setNext( response.headers.get( 'X-PublicLMS-Next-Cursor' ) || '' );
			return response.json();
		},
		[ status ]
	);

	const reload = useCallback( () => {
		setRows( null );
		fetchPage( 0 )
			.then( setRows )
			.catch( ( error ) => {
				notifyError( error );
				setRows( [] );
			} );
	}, [ fetchPage ] );

	useEffect( reload, [ reload ] );

	return (
		<div className="publiclms-screen publiclms-submissions">
			<div className="publiclms-screen__header">
				<h2 className="publiclms-screen__title">
					{ __( 'Submissions', 'public-lms' ) }
				</h2>
			</div>
			<div className="publiclms-bank__filters">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Show', 'public-lms' ) }
					value={ status }
					options={ [
						{
							value: 'submitted',
							label: __( 'Waiting for a grade', 'public-lms' ),
						},
						{
							value: 'graded',
							label: __( 'Graded', 'public-lms' ),
						},
						{ value: '', label: __( 'All', 'public-lms' ) },
					] }
					onChange={ setStatus }
				/>
			</div>
			{ rows === null && <Spinner /> }
			{ rows?.length === 0 && (
				<p className="publiclms-builder__empty">
					{ status === 'submitted'
						? __( 'Nothing to grade. Well done.', 'public-lms' )
						: __( 'No submissions yet.', 'public-lms' ) }
				</p>
			) }
			{ rows?.length > 0 && (
				<>
					<p className="publiclms-screen__summary">
						{ sprintf(
							/* translators: %d: number of submissions. */
							_n(
								'%d submission',
								'%d submissions',
								total,
								'public-lms'
							),
							total
						) }
					</p>
					<table className="widefat striped">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Learner', 'public-lms' ) }
								</th>
								<th scope="col">
									{ __( 'Assignment', 'public-lms' ) }
								</th>
								<th scope="col">
									{ __( 'Course', 'public-lms' ) }
								</th>
								<th scope="col">
									{ __( 'Handed in', 'public-lms' ) }
								</th>
								<th scope="col">
									{ __( 'Status', 'public-lms' ) }
								</th>
								<th scope="col">
									<span className="screen-reader-text">
										{ __( 'Actions', 'public-lms' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr key={ row.id }>
									<td>{ row.user.name }</td>
									<td>
										{ decodeEntities( row.assignment ) }
									</td>
									<td>{ decodeEntities( row.course ) }</td>
									<td>
										{ new Date(
											row.submitted_at
										).toLocaleString() }
									</td>
									<td>{ statusLabel( row ) }</td>
									<td>
										<Button
											variant={
												row.status === 'graded'
													? 'secondary'
													: 'primary'
											}
											size="compact"
											onClick={ () => setGrading( row ) }
										>
											{ row.status === 'graded'
												? __(
														'Change grade',
														'public-lms'
													)
												: __( 'Grade', 'public-lms' ) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					{ next && (
						<Button
							variant="secondary"
							onClick={ () =>
								fetchPage( next )
									.then( ( more ) =>
										setRows( ( current ) => [
											...current,
											...more,
										] )
									)
									.catch( notifyError )
							}
						>
							{ __( 'Load more', 'public-lms' ) }
						</Button>
					) }
				</>
			) }
			{ grading && (
				<GradingModal
					submission={ grading }
					onClose={ () => setGrading( null ) }
					onGraded={ () => {
						setGrading( null );
						reload();
					} }
				/>
			) }
		</div>
	);
}
