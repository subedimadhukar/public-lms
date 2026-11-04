/**
 * The question bank screen: every reusable question the user may edit, with
 * search, a type filter and the quizzes each one is used in.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import ConfirmDialog from '../components/confirm-dialog';
import { notifyError, notifySuccess } from '../components/notices';
import QuestionEditor, { QUESTION_TYPES } from './question-editor';

export default function QuestionBankScreen() {
	const [ search, setSearch ] = useState( '' );
	const [ type, setType ] = useState( '' );
	const [ rows, setRows ] = useState( null );
	const [ total, setTotal ] = useState( 0 );
	const [ cursor, setCursor ] = useState( '' );
	const [ editing, setEditing ] = useState( null );
	const [ deleting, setDeleting ] = useState( null );

	const fetchPage = useCallback(
		async ( after ) => {
			const response = await apiFetch( {
				path: addQueryArgs( '/publiclms/v1/questions', {
					search,
					type,
					per_page: 30,
					cursor: after,
				} ),
				parse: false,
			} );
			const data = await response.json();
			setTotal( Number( response.headers.get( 'X-WP-Total' ) || 0 ) );
			setCursor(
				response.headers.get( 'X-PublicLMS-Next-Cursor' ) || ''
			);
			return data;
		},
		[ search, type ]
	);

	const reload = useCallback( () => {
		setRows( null );
		fetchPage( '' )
			.then( setRows )
			.catch( ( error ) => {
				notifyError( error );
				setRows( [] );
			} );
	}, [ fetchPage ] );

	useEffect( () => {
		const timer = setTimeout( reload, 250 );
		return () => clearTimeout( timer );
	}, [ reload ] );

	const more = async () => {
		try {
			const data = await fetchPage( cursor );
			setRows( ( current ) => [ ...( current || [] ), ...data ] );
		} catch ( error ) {
			notifyError( error );
		}
	};

	return (
		<div className="publiclms-screen publiclms-bank">
			<div className="publiclms-screen__header">
				<h2 className="publiclms-screen__title">
					{ __( 'Question bank', 'public-lms' ) }
				</h2>
				<div className="publiclms-screen__header-actions">
					<Button
						variant="primary"
						onClick={ () => setEditing( 'new' ) }
					>
						{ __( 'New question', 'public-lms' ) }
					</Button>
				</div>
			</div>
			<p className="publiclms-screen__summary">
				{ __(
					'Questions are reusable: add the same question to as many quizzes as you like from each quiz’s editor.',
					'public-lms'
				) }
			</p>
			<div className="publiclms-bank__filters">
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search questions', 'public-lms' ) }
					value={ search }
					onChange={ setSearch }
				/>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Type', 'public-lms' ) }
					value={ type }
					options={ [
						{ value: '', label: __( 'All types', 'public-lms' ) },
						...Object.entries( QUESTION_TYPES ).map(
							( [ value, label ] ) => ( { value, label } )
						),
					] }
					onChange={ setType }
				/>
			</div>
			{ rows === null && <Spinner /> }
			{ rows?.length === 0 && (
				<p className="publiclms-builder__empty">
					{ __( 'No questions found.', 'public-lms' ) }
				</p>
			) }
			{ rows?.length > 0 && (
				<>
					<p className="publiclms-screen__summary">
						{ sprintf(
							/* translators: %d: number of questions. */
							_n(
								'%d question',
								'%d questions',
								total,
								'public-lms'
							),
							total
						) }
					</p>
					<table className="widefat striped publiclms-bank__table">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Question', 'public-lms' ) }
								</th>
								<th scope="col">
									{ __( 'Type', 'public-lms' ) }
								</th>
								<th scope="col">
									{ __( 'Points', 'public-lms' ) }
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
									<td>{ decodeEntities( row.title ) }</td>
									<td>{ QUESTION_TYPES[ row.type ] }</td>
									<td>{ row.points }</td>
									<td className="publiclms-bank__actions">
										<Button
											variant="link"
											onClick={ () =>
												setEditing( row.id )
											}
										>
											{ __( 'Edit', 'public-lms' ) }
										</Button>
										<Button
											variant="link"
											isDestructive
											onClick={ () => setDeleting( row ) }
										>
											{ __( 'Delete', 'public-lms' ) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					{ cursor && (
						<Button variant="secondary" onClick={ more }>
							{ __( 'Load more', 'public-lms' ) }
						</Button>
					) }
				</>
			) }
			{ editing && (
				<QuestionEditor
					questionId={ editing === 'new' ? 0 : editing }
					onClose={ () => setEditing( null ) }
					onSaved={ () => {
						setEditing( null );
						reload();
					} }
				/>
			) }
			{ deleting && (
				<ConfirmDialog
					title={ __( 'Delete question?', 'public-lms' ) }
					message={ __(
						'The question moves to the trash. Quizzes that list it skip it from their next attempt; past results keep their scores.',
						'public-lms'
					) }
					confirmLabel={ __( 'Delete question', 'public-lms' ) }
					onCancel={ () => setDeleting( null ) }
					onConfirm={ async () => {
						try {
							await apiFetch( {
								path: `/publiclms/v1/questions/${ deleting.id }`,
								method: 'DELETE',
							} );
							notifySuccess(
								__( 'Question deleted.', 'public-lms' )
							);
							reload();
						} catch ( error ) {
							notifyError( error );
						}
						setDeleting( null );
					} }
				/>
			) }
		</div>
	);
}
