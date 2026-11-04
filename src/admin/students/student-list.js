/**
 * The student list: DataViews over /publiclms/v1/students.
 */
import { Modal, Spinner } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import { useSelect } from '@wordpress/data';
import { dateI18n, getSettings } from '@wordpress/date';
import { useMemo, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _n, sprintf } from '@wordpress/i18n';

import { STORE_NAME as COURSES } from '../../store/courses';
import { STORE_NAME } from '../../store/students';
import useCursorPages from '../components/use-cursor-pages';

const SORT_FIELDS = { name: 'name', last_enrolled_at: 'enrolled' };

function useStudentList( query ) {
	return useSelect(
		( select ) => {
			const store = select( STORE_NAME );
			return {
				list: store.getStudentList( query ),
				isLoading: ! store.hasFinishedResolution( 'getStudentList', [
					query,
				] ),
			};
		},
		[ query ]
	);
}

function StudentDetail( { studentId, onClose } ) {
	const student = useSelect(
		( select ) => select( STORE_NAME ).getStudent( studentId ),
		[ studentId ]
	);
	const dateFormat = getSettings().formats.date;
	return (
		<Modal
			title={ student ? student.name : __( 'Learner', 'public-lms' ) }
			onRequestClose={ onClose }
			size="medium"
		>
			{ ! student ? (
				<Spinner />
			) : (
				<table className="widefat striped publiclms-student-detail">
					<caption className="screen-reader-text">
						{ __( 'Courses and progress', 'public-lms' ) }
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Course', 'public-lms' ) }
							</th>
							<th scope="col">
								{ __( 'Enrolled', 'public-lms' ) }
							</th>
							<th scope="col">
								{ __( 'Progress', 'public-lms' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ student.courses.map( ( course ) => (
							<tr key={ course.course_id }>
								<td>{ decodeEntities( course.title ) }</td>
								<td>
									{ dateI18n(
										dateFormat,
										course.enrolled_at
									) }
								</td>
								<td>
									<progress
										className="publiclms-student-detail__progress"
										max="100"
										value={ course.percent }
										aria-label={ sprintf(
											/* translators: %s: course title. */
											__(
												'Progress in %s',
												'public-lms'
											),
											decodeEntities( course.title )
										) }
									/>{ ' ' }
									{ sprintf(
										/* translators: %d: percentage. */
										__( '%d%%', 'public-lms' ),
										course.percent
									) }
									{ course.completed_at &&
										` · ${ __( 'Completed', 'public-lms' ) }` }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</Modal>
	);
}

export default function StudentList() {
	const [ view, setView ] = useState( {
		type: 'table',
		search: '',
		filters: [],
		page: 1,
		perPage: 20,
		sort: { field: 'name', direction: 'asc' },
		titleField: 'name',
		mediaField: 'avatar',
		fields: [ 'email', 'courses', 'last_enrolled_at' ],
		layout: {},
	} );
	const [ openId, setOpenId ] = useState( 0 );

	const courseQuery = useMemo(
		() => ( {
			context: 'edit',
			status: [ 'any' ],
			per_page: 100,
			orderby: 'title',
			order: 'asc',
			cursor: '',
		} ),
		[]
	);
	const courses = useSelect(
		( select ) =>
			select( COURSES ).getCourseList( courseQuery )?.items ?? [],
		[ courseQuery ]
	);

	const courseFilter = view.filters?.find(
		( filter ) => filter.field === 'course'
	);
	const baseQuery = useMemo(
		() => ( {
			search: view.search || '',
			orderby: SORT_FIELDS[ view.sort?.field ] || 'name',
			order: view.sort?.direction || 'asc',
			per_page: view.perPage,
			course: Number( courseFilter?.value ) || 0,
		} ),
		[ view.search, view.sort, view.perPage, courseFilter?.value ]
	);

	const { items, isLoading, paginationInfo } = useCursorPages( {
		baseQuery,
		page: view.page,
		perPage: view.perPage,
		useList: useStudentList,
	} );

	const dateFormat = getSettings().formats.date;
	const fields = useMemo(
		() => [
			{
				id: 'avatar',
				label: __( 'Avatar', 'public-lms' ),
				enableSorting: false,
				filterBy: false,
				render: ( { item } ) => (
					<img
						className="publiclms-avatar"
						src={ item.avatar }
						alt=""
					/>
				),
			},
			{
				id: 'name',
				label: __( 'Name', 'public-lms' ),
				enableHiding: false,
				filterBy: false,
			},
			{
				id: 'email',
				label: __( 'Email', 'public-lms' ),
				enableSorting: false,
				filterBy: false,
			},
			{
				id: 'course',
				label: __( 'Course', 'public-lms' ),
				enableSorting: false,
				enableHiding: false,
				elements: courses.map( ( course ) => ( {
					value: course.id,
					label: decodeEntities( course.title ),
				} ) ),
				filterBy: { operators: [ 'is' ] },
				render: () => null,
			},
			{
				id: 'courses',
				label: __( 'Courses', 'public-lms' ),
				type: 'integer',
				enableSorting: false,
				filterBy: false,
			},
			{
				id: 'last_enrolled_at',
				label: __( 'Last enrolled', 'public-lms' ),
				filterBy: false,
				render: ( { item } ) =>
					dateI18n( dateFormat, item.last_enrolled_at ),
			},
		],
		[ courses, dateFormat ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'progress',
				label: __( 'View progress', 'public-lms' ),
				isPrimary: true,
				callback: ( [ item ] ) => setOpenId( item.id ),
			},
		],
		[]
	);

	return (
		<div className="publiclms-screen">
			<div className="publiclms-screen__header">
				<h2 className="publiclms-screen__title">
					{ __( 'Students', 'public-lms' ) }
				</h2>
			</div>
			<p className="publiclms-screen__summary" aria-live="polite">
				{ ! isLoading &&
					sprintf(
						/* translators: %s: number of learners. */
						_n(
							'%s learner',
							'%s learners',
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
				defaultLayouts={ { table: {}, list: {} } }
				search
				searchLabel={ __( 'Search learners', 'public-lms' ) }
				empty={ <p>{ __( 'No learners yet.', 'public-lms' ) }</p> }
			/>
			{ openId > 0 && (
				<StudentDetail
					studentId={ openId }
					onClose={ () => setOpenId( 0 ) }
				/>
			) }
		</div>
	);
}
