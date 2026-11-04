/**
 * The course list: DataViews over /publiclms/v1/courses.
 */
import { Button, Modal, TextControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { DataViews } from '@wordpress/dataviews/wp';
import { useDispatch, useSelect } from '@wordpress/data';
import { dateI18n, getSettings } from '@wordpress/date';
import { useMemo, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { STORE_NAME } from '../../store/courses';
import ConfirmDialog from '../components/confirm-dialog';
import { notifyError, notifySuccess } from '../components/notices';
import useCursorPages from '../components/use-cursor-pages';
import { boot } from '../boot';

const STATUS_LABELS = {
	publish: __( 'Published', 'public-lms' ),
	future: __( 'Scheduled', 'public-lms' ),
	draft: __( 'Draft', 'public-lms' ),
	pending: __( 'Pending review', 'public-lms' ),
	private: __( 'Private', 'public-lms' ),
};

const SORTABLE = { title: 'title', date: 'date' };

export function builderUrl( id ) {
	return addQueryArgs( boot.appUrl, { course: id } );
}

function useCourseList( query ) {
	return useSelect(
		( select ) => {
			const store = select( STORE_NAME );
			return {
				list: store.getCourseList( query ),
				isLoading: ! store.hasFinishedResolution( 'getCourseList', [
					query,
				] ),
			};
		},
		[ query ]
	);
}

function useTerms( taxonomy ) {
	return useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', taxonomy, {
				per_page: 100,
				context: 'view',
				_fields: 'id,name',
			} ) ?? [],
		[ taxonomy ]
	);
}

function filterValue( view, field ) {
	const filter = view.filters?.find( ( f ) => f.field === field );
	if ( ! filter || filter.value === undefined || filter.value === null ) {
		return [];
	}
	return Array.isArray( filter.value ) ? filter.value : [ filter.value ];
}

function NewCourseModal( { onClose } ) {
	const [ title, setTitle ] = useState( '' );
	const [ isBusy, setIsBusy ] = useState( false );
	const { createCourse } = useDispatch( STORE_NAME );

	const submit = async ( event ) => {
		event.preventDefault();
		if ( ! title.trim() ) {
			return;
		}
		setIsBusy( true );
		try {
			const course = await createCourse( {
				title: title.trim(),
				status: 'draft',
			} );
			window.location.href = builderUrl( course.id );
		} catch ( error ) {
			notifyError( error );
			setIsBusy( false );
		}
	};

	return (
		<Modal
			title={ __( 'Add a course', 'public-lms' ) }
			onRequestClose={ onClose }
			size="medium"
			focusOnMount="firstContentElement"
		>
			<form onSubmit={ submit } className="publiclms-form">
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Course title', 'public-lms' ) }
					value={ title }
					onChange={ setTitle }
					help={ __(
						'You can change it later. The course starts as a draft.',
						'public-lms'
					) }
					required
				/>
				<div className="publiclms-form__actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'public-lms' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						isBusy={ isBusy }
						disabled={ isBusy || ! title.trim() }
					>
						{ __( 'Create and open builder', 'public-lms' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

export default function CourseList() {
	const [ view, setView ] = useState( {
		type: 'table',
		search: '',
		filters: [],
		page: 1,
		perPage: 20,
		sort: { field: 'date', direction: 'desc' },
		titleField: 'title',
		mediaField: 'featured_image',
		fields: [
			'status',
			'author',
			'lessons_count',
			'enrolled_count',
			'date',
		],
		layout: {},
	} );
	const [ isAdding, setIsAdding ] = useState( false );
	const { trashCourse } = useDispatch( STORE_NAME );
	const levels = useTerms( 'plms_level' );
	const categories = useTerms( 'plms_course_category' );

	const statuses = filterValue( view, 'status' );
	const filtersKey = JSON.stringify( [
		view.search,
		view.sort,
		view.perPage,
		view.filters,
	] );
	const baseQuery = useMemo(
		() => ( {
			context: 'edit',
			status: statuses.length ? statuses : [ 'any' ],
			search: view.search || '',
			orderby: SORTABLE[ view.sort?.field ] || 'date',
			order: view.sort?.direction || 'desc',
			per_page: view.perPage,
			level: filterValue( view, 'levels' ),
			category: filterValue( view, 'categories' ),
		} ),
		// The key covers every part of the view the query reads.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ filtersKey ]
	);

	const { items, isLoading, paginationInfo } = useCursorPages( {
		baseQuery,
		page: view.page,
		perPage: view.perPage,
		useList: useCourseList,
	} );

	const dateFormat = getSettings().formats.date;
	const fields = useMemo(
		() => [
			{
				id: 'featured_image',
				label: __( 'Image', 'public-lms' ),
				enableSorting: false,
				enableHiding: true,
				filterBy: false,
				render: ( { item } ) =>
					item.featured_image ? (
						<img
							className="publiclms-course-list__thumb"
							src={ item.featured_image }
							alt=""
						/>
					) : (
						<span className="publiclms-course-list__thumb is-empty" />
					),
			},
			{
				id: 'title',
				label: __( 'Title', 'public-lms' ),
				enableHiding: false,
				enableGlobalSearch: true,
				filterBy: false,
				getValue: ( { item } ) => decodeEntities( item.title ),
				render: ( { item } ) => (
					<a href={ builderUrl( item.id ) }>
						{ decodeEntities( item.title ) ||
							__( '(no title)', 'public-lms' ) }
					</a>
				),
			},
			{
				id: 'status',
				label: __( 'Status', 'public-lms' ),
				enableSorting: false,
				elements: Object.entries( STATUS_LABELS ).map(
					( [ value, label ] ) => ( { value, label } )
				),
				filterBy: { operators: [ 'isAny' ] },
				render: ( { item } ) => (
					<span className={ `publiclms-status is-${ item.status }` }>
						{ STATUS_LABELS[ item.status ] || item.status }
					</span>
				),
			},
			{
				id: 'author',
				label: __( 'Instructor', 'public-lms' ),
				enableSorting: false,
				filterBy: false,
				getValue: ( { item } ) => item.author?.name,
			},
			{
				id: 'levels',
				label: __( 'Level', 'public-lms' ),
				enableSorting: false,
				elements: levels.map( ( term ) => ( {
					value: term.id,
					label: decodeEntities( term.name ),
				} ) ),
				filterBy: { operators: [ 'isAny' ] },
				render: ( { item } ) =>
					item.levels
						.map( ( id ) =>
							decodeEntities(
								levels.find( ( term ) => term.id === id )
									?.name || ''
							)
						)
						.join( ', ' ),
			},
			{
				id: 'categories',
				label: __( 'Category', 'public-lms' ),
				enableSorting: false,
				elements: categories.map( ( term ) => ( {
					value: term.id,
					label: decodeEntities( term.name ),
				} ) ),
				filterBy: { operators: [ 'isAny' ] },
				render: ( { item } ) =>
					item.categories
						.map( ( id ) =>
							decodeEntities(
								categories.find( ( term ) => term.id === id )
									?.name || ''
							)
						)
						.join( ', ' ),
			},
			{
				id: 'lessons_count',
				label: __( 'Lessons', 'public-lms' ),
				type: 'integer',
				enableSorting: false,
				filterBy: false,
			},
			{
				id: 'enrolled_count',
				label: __( 'Learners', 'public-lms' ),
				type: 'integer',
				enableSorting: false,
				filterBy: false,
			},
			{
				id: 'date',
				label: __( 'Date', 'public-lms' ),
				enableSorting: true,
				filterBy: false,
				getValue: ( { item } ) => item.date_gmt,
				render: ( { item } ) =>
					item.date_gmt ? dateI18n( dateFormat, item.date_gmt ) : '—',
			},
		],
		[ levels, categories, dateFormat ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'builder',
				label: __( 'Open builder', 'public-lms' ),
				isPrimary: true,
				callback: ( [ item ] ) => {
					window.location.href = builderUrl( item.id );
				},
			},
			{
				id: 'view',
				label: __( 'View course', 'public-lms' ),
				callback: ( [ item ] ) => {
					window.location.href = item.link;
				},
			},
			{
				id: 'trash',
				label: __( 'Move to trash', 'public-lms' ),
				isDestructive: true,
				isEligible: ( item ) => item.permissions?.delete,
				hideModalHeader: true,
				RenderModal: ( { items: selected, closeModal } ) => (
					<ConfirmDialog
						title={ __( 'Move course to trash?', 'public-lms' ) }
						message={ sprintf(
							/* translators: %s: course title. */
							__(
								'“%s” moves to the trash with its sections and lessons. Learners keep their progress records.',
								'public-lms'
							),
							decodeEntities( selected[ 0 ].title )
						) }
						confirmLabel={ __( 'Move to trash', 'public-lms' ) }
						onCancel={ closeModal }
						onConfirm={ async () => {
							try {
								await trashCourse( selected[ 0 ].id );
								notifySuccess(
									__(
										'Course moved to the trash.',
										'public-lms'
									)
								);
							} catch ( error ) {
								notifyError( error );
							}
							closeModal();
						} }
					/>
				),
			},
		],
		[ trashCourse ]
	);

	return (
		<div className="publiclms-screen">
			<div className="publiclms-screen__header">
				<h2 className="publiclms-screen__title">
					{ __( 'Courses', 'public-lms' ) }
				</h2>
				{ boot.permissions.createCourses && (
					<Button
						variant="primary"
						onClick={ () => setIsAdding( true ) }
					>
						{ __( 'Add course', 'public-lms' ) }
					</Button>
				) }
			</div>
			<p className="publiclms-screen__summary" aria-live="polite">
				{ ! isLoading &&
					sprintf(
						/* translators: %s: number of courses. */
						_n(
							'%s course',
							'%s courses',
							paginationInfo.totalItems,
							'public-lms'
						),
						paginationInfo.totalItems.toLocaleString()
					) }
			</p>
			<DataViews
				data={ items }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				isLoading={ isLoading }
				paginationInfo={ paginationInfo }
				getItemId={ ( item ) => String( item.id ) }
				defaultLayouts={ { table: {}, grid: {} } }
				search
				searchLabel={ __( 'Search courses', 'public-lms' ) }
				empty={
					<p>
						{ view.search || view.filters?.length
							? __( 'No courses match.', 'public-lms' )
							: __(
									'No courses yet. Add your first course to start building.',
									'public-lms'
								) }
					</p>
				}
			/>
			{ isAdding && (
				<NewCourseModal onClose={ () => setIsAdding( false ) } />
			) }
		</div>
	);
}
