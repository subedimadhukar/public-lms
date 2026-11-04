/**
 * The curriculum tree of the course builder.
 *
 * Sections and their lessons are sortable with dnd-kit (ADR-034): by pointer,
 * or by keyboard (Space to pick up, arrow keys to move, Space to drop) with
 * screen reader announcements. Every row also has "Move up" and "Move down"
 * in its menu, for anyone who prefers not to drag.
 */
import {
	DndContext,
	KeyboardSensor,
	PointerSensor,
	closestCenter,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import {
	SortableContext,
	arrayMove,
	sortableKeyboardCoordinates,
	useSortable,
	verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Button, DropdownMenu } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Icon,
	arrowDown,
	arrowUp,
	dragHandle,
	help,
	lock,
	moreVertical,
	page,
	pencil,
	seen,
	trash,
	unseen,
	upload,
} from '@wordpress/icons';

import { STORE_NAME } from '../../store/courses';
import ConfirmDialog from '../components/confirm-dialog';
import { notifyError, notifySuccess } from '../components/notices';
import AccessRulesModal, { accessSummary } from './access-rules';
import { AddInline, InlineForm } from './inline-forms';

const TYPE_ICONS = {
	lesson: page,
	quiz: help,
	assignment: upload,
};

/**
 * Translated words for each item type, used in labels and messages.
 *
 * @param {string} type Item type.
 * @return {Object} Labels.
 */
export function typeLabels( type ) {
	if ( type === 'quiz' ) {
		return {
			name: __( 'Quiz', 'public-lms' ),
			reorder: ( title ) =>
				/* translators: %s: quiz title. */
				sprintf( __( 'Reorder quiz: %s', 'public-lms' ), title ),
			actions: ( title ) =>
				/* translators: %s: quiz title. */
				sprintf( __( 'Actions for quiz: %s', 'public-lms' ), title ),
			titleField: __( 'Quiz title', 'public-lms' ),
			renamed: __( 'Quiz renamed.', 'public-lms' ),
			updated: __( 'Quiz updated.', 'public-lms' ),
			deleted: __( 'Quiz deleted.', 'public-lms' ),
			deleteTitle: __( 'Delete quiz?', 'public-lms' ),
			deleteConfirm: __( 'Delete quiz', 'public-lms' ),
			add: __( 'Add quiz', 'public-lms' ),
			added: __( 'Quiz added.', 'public-lms' ),
		};
	}
	if ( type === 'assignment' ) {
		return {
			name: __( 'Assignment', 'public-lms' ),
			reorder: ( title ) =>
				/* translators: %s: assignment title. */
				sprintf( __( 'Reorder assignment: %s', 'public-lms' ), title ),
			actions: ( title ) =>
				sprintf(
					/* translators: %s: assignment title. */
					__( 'Actions for assignment: %s', 'public-lms' ),
					title
				),
			titleField: __( 'Assignment title', 'public-lms' ),
			renamed: __( 'Assignment renamed.', 'public-lms' ),
			updated: __( 'Assignment updated.', 'public-lms' ),
			deleted: __( 'Assignment deleted.', 'public-lms' ),
			deleteTitle: __( 'Delete assignment?', 'public-lms' ),
			deleteConfirm: __( 'Delete assignment', 'public-lms' ),
			add: __( 'Add assignment', 'public-lms' ),
			added: __( 'Assignment added.', 'public-lms' ),
		};
	}
	return {
		name: __( 'Lesson', 'public-lms' ),
		reorder: ( title ) =>
			/* translators: %s: lesson title. */
			sprintf( __( 'Reorder lesson: %s', 'public-lms' ), title ),
		actions: ( title ) =>
			/* translators: %s: lesson title. */
			sprintf( __( 'Actions for lesson: %s', 'public-lms' ), title ),
		titleField: __( 'Lesson title', 'public-lms' ),
		renamed: __( 'Lesson renamed.', 'public-lms' ),
		updated: __( 'Lesson updated.', 'public-lms' ),
		deleted: __( 'Lesson deleted.', 'public-lms' ),
		deleteTitle: __( 'Delete lesson?', 'public-lms' ),
		deleteConfirm: __( 'Delete lesson', 'public-lms' ),
		add: __( 'Add lesson', 'public-lms' ),
		added: __( 'Lesson added.', 'public-lms' ),
	};
}

const sectionKey = ( id ) => `s-${ id }`;
const itemKey = ( id ) => `i-${ id }`;
const isSectionKey = ( key ) => String( key ).startsWith( 's-' );
const idOf = ( key ) => Number( String( key ).slice( 2 ) );

/**
 * Returns the inline style dnd-kit needs while a row moves. Only the
 * transform and transition are set, through custom properties the
 * stylesheet reads, so themes and admin colour schemes keep control.
 *
 * @param {Object|null} transform  dnd-kit transform.
 * @param {string}      transition dnd-kit transition.
 * @return {Object} Style object.
 */
function dragStyle( transform, transition ) {
	return {
		'--publiclms-drag-transform':
			CSS.Translate.toString( transform ) || 'none',
		'--publiclms-drag-transition': transition || 'none',
	};
}

function titleOf( entity ) {
	return decodeEntities( entity.title ) || __( '(no title)', 'public-lms' );
}

function ItemRow( {
	courseId,
	item,
	isFirst,
	isLast,
	onMove,
	sections,
	renderActions,
} ) {
	const [ isRenaming, setIsRenaming ] = useState( false );
	const [ isDeleting, setIsDeleting ] = useState( false );
	const [ isEditingRules, setIsEditingRules ] = useState( false );
	const { updateItem, deleteItem } = useDispatch( STORE_NAME );
	const labels = typeLabels( item.type );
	const update = ( data ) => updateItem( courseId, item.type, item.id, data );
	const rules = accessSummary( item );
	const {
		attributes,
		listeners,
		setNodeRef,
		setActivatorNodeRef,
		transform,
		transition,
		isDragging,
	} = useSortable( { id: itemKey( item.id ), data: { type: 'item' } } );

	const title = titleOf( item );
	const run = async ( promise, message ) => {
		try {
			await promise;
			if ( message ) {
				notifySuccess( message );
			}
		} catch ( error ) {
			notifyError( error );
		}
	};

	return (
		<li
			ref={ setNodeRef }
			style={ dragStyle( transform, transition ) }
			className={ `publiclms-builder__item${ isDragging ? ' is-dragging' : '' }` }
		>
			<button
				type="button"
				ref={ setActivatorNodeRef }
				className="publiclms-drag-handle"
				{ ...attributes }
				{ ...listeners }
				aria-label={ labels.reorder( title ) }
			>
				<Icon
					icon={ dragHandle }
					className="publiclms-drag-handle__icon"
				/>
			</button>
			<Icon
				icon={ TYPE_ICONS[ item.type ] || page }
				className="publiclms-builder__item-icon"
			/>
			<span className="screen-reader-text">{ labels.name }:</span>
			{ isRenaming ? (
				<InlineForm
					label={ labels.titleField }
					initial={ item.title }
					submitLabel={ __( 'Save', 'public-lms' ) }
					onCancel={ () => setIsRenaming( false ) }
					onSubmit={ async ( value ) => {
						await update( { title: value } );
						setIsRenaming( false );
						notifySuccess( labels.renamed );
					} }
				/>
			) : (
				<span className="publiclms-builder__item-title">{ title }</span>
			) }
			<span className="publiclms-builder__badges">
				{ item.status !== 'publish' && (
					<span className={ `publiclms-status is-${ item.status }` }>
						{ item.status === 'draft'
							? __( 'Draft', 'public-lms' )
							: item.status }
					</span>
				) }
				{ item.preview && (
					<span className="publiclms-badge">
						{ __( 'Free preview', 'public-lms' ) }
					</span>
				) }
				{ rules && (
					<span className="publiclms-badge is-rules">{ rules }</span>
				) }
			</span>
			{ renderActions && renderActions( item ) }
			{ item.edit_url && item.type === 'lesson' && (
				<Button
					variant="tertiary"
					size="compact"
					href={ item.edit_url }
				>
					{ __( 'Edit content', 'public-lms' ) }
				</Button>
			) }
			<DropdownMenu
				icon={ moreVertical }
				label={ labels.actions( title ) }
				toggleProps={ { size: 'compact' } }
				controls={ [
					{
						title: __( 'Rename', 'public-lms' ),
						icon: pencil,
						onClick: () => setIsRenaming( true ),
					},
					{
						title: item.preview
							? __( 'Remove free preview', 'public-lms' )
							: __( 'Make free preview', 'public-lms' ),
						icon: item.preview ? unseen : seen,
						onClick: () =>
							run(
								update( {
									preview: ! item.preview,
								} ),
								item.preview
									? __(
											'Free preview removed.',
											'public-lms'
										)
									: __(
											'Lesson is now a free preview.',
											'public-lms'
										)
							),
					},
					{
						title: __( 'Access rules', 'public-lms' ),
						icon: lock,
						onClick: () => setIsEditingRules( true ),
					},
					{
						title:
							item.status === 'publish'
								? __( 'Switch to draft', 'public-lms' )
								: __( 'Publish', 'public-lms' ),
						onClick: () =>
							run(
								update( {
									status:
										item.status === 'publish'
											? 'draft'
											: 'publish',
								} ),
								labels.updated
							),
					},
					{
						title: __( 'Move up', 'public-lms' ),
						icon: arrowUp,
						isDisabled: isFirst,
						onClick: () => onMove( item.id, -1 ),
					},
					{
						title: __( 'Move down', 'public-lms' ),
						icon: arrowDown,
						isDisabled: isLast,
						onClick: () => onMove( item.id, 1 ),
					},
					{
						title: __( 'Delete', 'public-lms' ),
						icon: trash,
						onClick: () => setIsDeleting( true ),
					},
				] }
			/>
			{ isEditingRules && (
				<AccessRulesModal
					entity={ item }
					kind="item"
					sections={ sections }
					onSave={ update }
					onClose={ () => setIsEditingRules( false ) }
				/>
			) }
			{ isDeleting && (
				<ConfirmDialog
					title={ labels.deleteTitle }
					message={ sprintf(
						/* translators: %s: lesson, quiz or assignment title. */
						__(
							'“%s” moves to the trash. You can restore it from the trash.',
							'public-lms'
						),
						title
					) }
					confirmLabel={ labels.deleteConfirm }
					onCancel={ () => setIsDeleting( false ) }
					onConfirm={ async () => {
						await run(
							deleteItem( courseId, item.type, item.id ),
							labels.deleted
						);
						setIsDeleting( false );
					} }
				/>
			) }
		</li>
	);
}

function SectionCard( {
	courseId,
	section,
	sections,
	index,
	count,
	firstItemId,
	lastItemId,
	onMoveSection,
	onMoveItem,
	itemTypes,
	renderItemActions,
} ) {
	const [ isRenaming, setIsRenaming ] = useState( false );
	const [ isDeleting, setIsDeleting ] = useState( false );
	const [ isEditingRules, setIsEditingRules ] = useState( false );
	const { updateSection, deleteSection, addItem } = useDispatch( STORE_NAME );
	const rules = accessSummary( section );
	const {
		attributes,
		listeners,
		setNodeRef,
		setActivatorNodeRef,
		transform,
		transition,
		isDragging,
	} = useSortable( {
		id: sectionKey( section.id ),
		data: { type: 'section' },
	} );

	const title = titleOf( section );
	const headingId = `publiclms-section-${ section.id }`;

	return (
		<li
			ref={ setNodeRef }
			style={ dragStyle( transform, transition ) }
			className={ `publiclms-builder__section${ isDragging ? ' is-dragging' : '' }` }
			aria-labelledby={ headingId }
		>
			<div className="publiclms-builder__section-header">
				<button
					type="button"
					ref={ setActivatorNodeRef }
					className="publiclms-drag-handle"
					{ ...attributes }
					{ ...listeners }
					aria-label={ sprintf(
						/* translators: %s: section title. */
						__( 'Reorder section: %s', 'public-lms' ),
						title
					) }
				>
					<Icon
						icon={ dragHandle }
						className="publiclms-drag-handle__icon"
					/>
				</button>
				{ isRenaming ? (
					<InlineForm
						label={ __( 'Section title', 'public-lms' ) }
						initial={ section.title }
						submitLabel={ __( 'Save', 'public-lms' ) }
						onCancel={ () => setIsRenaming( false ) }
						onSubmit={ async ( value ) => {
							await updateSection( courseId, section.id, {
								title: value,
							} );
							setIsRenaming( false );
							notifySuccess(
								__( 'Section renamed.', 'public-lms' )
							);
						} }
					/>
				) : (
					<h3
						id={ headingId }
						className="publiclms-builder__section-title"
					>
						{ title }
					</h3>
				) }
				<span className="publiclms-builder__count">
					{ sprintf(
						/* translators: %d: number of lessons, quizzes and assignments. */
						_n(
							'%d step',
							'%d steps',
							section.items.length,
							'public-lms'
						),
						section.items.length
					) }
				</span>
				{ rules && (
					<span className="publiclms-badge is-rules">{ rules }</span>
				) }
				<DropdownMenu
					icon={ moreVertical }
					label={ sprintf(
						/* translators: %s: section title. */
						__( 'Actions for section: %s', 'public-lms' ),
						title
					) }
					toggleProps={ { size: 'compact' } }
					controls={ [
						{
							title: __( 'Rename', 'public-lms' ),
							icon: pencil,
							onClick: () => setIsRenaming( true ),
						},
						{
							title: __( 'Access rules', 'public-lms' ),
							icon: lock,
							onClick: () => setIsEditingRules( true ),
						},
						{
							title: __( 'Move up', 'public-lms' ),
							icon: arrowUp,
							isDisabled: index === 0,
							onClick: () => onMoveSection( section.id, -1 ),
						},
						{
							title: __( 'Move down', 'public-lms' ),
							icon: arrowDown,
							isDisabled: index === count - 1,
							onClick: () => onMoveSection( section.id, 1 ),
						},
						{
							title: __( 'Delete', 'public-lms' ),
							icon: trash,
							onClick: () => setIsDeleting( true ),
						},
					] }
				/>
			</div>
			<SortableContext
				items={ section.items.map( ( item ) => itemKey( item.id ) ) }
				strategy={ verticalListSortingStrategy }
			>
				<ul className="publiclms-builder__items">
					{ section.items.map( ( item ) => (
						<ItemRow
							key={ item.id }
							courseId={ courseId }
							item={ item }
							isFirst={ item.id === firstItemId }
							isLast={ item.id === lastItemId }
							onMove={ onMoveItem }
							sections={ sections }
							renderActions={ renderItemActions }
						/>
					) ) }
					{ section.items.length === 0 && (
						<li className="publiclms-builder__empty">
							{ __(
								'No lessons yet. Add one, or drag a lesson here.',
								'public-lms'
							) }
						</li>
					) }
				</ul>
			</SortableContext>
			<div className="publiclms-builder__add-items">
				{ itemTypes.map( ( type ) => {
					const labels = typeLabels( type );
					return (
						<AddInline
							key={ type }
							buttonLabel={ labels.add }
							fieldLabel={ labels.titleField }
							onAdd={ async ( value ) => {
								await addItem(
									courseId,
									section.id,
									type,
									value
								);
								notifySuccess( labels.added );
							} }
						/>
					);
				} ) }
			</div>
			{ isEditingRules && (
				<AccessRulesModal
					entity={ section }
					kind="section"
					sections={ sections }
					onSave={ ( data ) =>
						updateSection( courseId, section.id, data )
					}
					onClose={ () => setIsEditingRules( false ) }
				/>
			) }
			{ isDeleting && (
				<ConfirmDialog
					title={ __( 'Delete section?', 'public-lms' ) }
					message={ sprintf(
						/* translators: 1: section title, 2: number of lessons, quizzes and assignments. */
						_n(
							'“%1$s” and its %2$d step move to the trash.',
							'“%1$s” and its %2$d steps move to the trash.',
							section.items.length,
							'public-lms'
						),
						title,
						section.items.length
					) }
					confirmLabel={ __( 'Delete section', 'public-lms' ) }
					onCancel={ () => setIsDeleting( false ) }
					onConfirm={ async () => {
						try {
							await deleteSection( courseId, section.id );
							notifySuccess(
								__( 'Section deleted.', 'public-lms' )
							);
						} catch ( error ) {
							notifyError( error );
						}
						setIsDeleting( false );
					} }
				/>
			) }
		</li>
	);
}

/**
 * Finds the index of the section holding a section or item key.
 *
 * @param {Array}  sections Sections.
 * @param {string} key      Section or item key.
 * @return {number} Index, or -1.
 */
function sectionIndexOf( sections, key ) {
	if ( isSectionKey( key ) ) {
		return sections.findIndex( ( section ) => section.id === idOf( key ) );
	}
	return sections.findIndex( ( section ) =>
		section.items.some( ( item ) => item.id === idOf( key ) )
	);
}

export default function CurriculumTree( {
	courseId,
	curriculum,
	itemTypes = [ 'lesson' ],
	renderItemActions,
} ) {
	const { saveOrder, addSection } = useDispatch( STORE_NAME );
	const [ sections, setSections ] = useState( curriculum.sections );
	const [ activeKey, setActiveKey ] = useState( null );

	useEffect( () => {
		if ( ! activeKey ) {
			setSections( curriculum.sections );
		}
	}, [ curriculum, activeKey ] );

	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 4 } } ),
		useSensor( KeyboardSensor, {
			coordinateGetter: sortableKeyboardCoordinates,
		} )
	);

	const persist = useCallback(
		async ( next ) => {
			setSections( next );
			try {
				await saveOrder( courseId, next );
				notifySuccess( __( 'Curriculum order saved.', 'public-lms' ) );
			} catch ( error ) {
				notifyError( error );
			}
		},
		[ courseId, saveOrder ]
	);

	// Sections only collide with sections; lessons with lessons and empty sections.
	const collisionDetection = useCallback(
		( args ) => {
			const dragsSection = isSectionKey( args.active.id );
			const droppableContainers = args.droppableContainers.filter(
				( container ) => {
					if ( dragsSection ) {
						return isSectionKey( container.id );
					}
					if ( ! isSectionKey( container.id ) ) {
						return true;
					}
					const section = sections.find(
						( s ) => s.id === idOf( container.id )
					);
					return section && section.items.length === 0;
				}
			);
			return closestCenter( { ...args, droppableContainers } );
		},
		[ sections ]
	);

	const findTitle = ( key ) => {
		if ( isSectionKey( key ) ) {
			const section = sections.find( ( s ) => s.id === idOf( key ) );
			return section ? titleOf( section ) : '';
		}
		for ( const section of sections ) {
			const item = section.items.find( ( i ) => i.id === idOf( key ) );
			if ( item ) {
				return titleOf( item );
			}
		}
		return '';
	};

	const announcements = {
		onDragStart: ( { active } ) =>
			sprintf(
				/* translators: %s: title. */
				__( 'Picked up %s.', 'public-lms' ),
				findTitle( active.id )
			),
		onDragOver: ( { active, over } ) =>
			over
				? sprintf(
						/* translators: 1: dragged title, 2: title it is over. */
						__( '%1$s is over %2$s.', 'public-lms' ),
						findTitle( active.id ),
						findTitle( over.id )
					)
				: sprintf(
						/* translators: %s: title. */
						__(
							'%s is no longer over a drop position.',
							'public-lms'
						),
						findTitle( active.id )
					),
		onDragEnd: ( { active, over } ) =>
			over
				? sprintf(
						/* translators: %s: title. */
						__( 'Dropped %s.', 'public-lms' ),
						findTitle( active.id )
					)
				: sprintf(
						/* translators: %s: title. */
						__( '%s was dropped where it started.', 'public-lms' ),
						findTitle( active.id )
					),
		onDragCancel: ( { active } ) =>
			sprintf(
				/* translators: %s: title. */
				__( 'Moving %s was cancelled.', 'public-lms' ),
				findTitle( active.id )
			),
	};

	const onDragOver = ( { active, over } ) => {
		if ( ! over || isSectionKey( active.id ) ) {
			return;
		}
		const from = sectionIndexOf( sections, active.id );
		const to = sectionIndexOf( sections, over.id );
		if ( from < 0 || to < 0 || from === to ) {
			return;
		}
		setSections( ( current ) => {
			const next = current.map( ( section ) => ( {
				...section,
				items: [ ...section.items ],
			} ) );
			const itemIndex = next[ from ].items.findIndex(
				( item ) => item.id === idOf( active.id )
			);
			const [ moved ] = next[ from ].items.splice( itemIndex, 1 );
			const overIndex = isSectionKey( over.id )
				? next[ to ].items.length
				: next[ to ].items.findIndex(
						( item ) => item.id === idOf( over.id )
					);
			next[ to ].items.splice(
				overIndex < 0 ? next[ to ].items.length : overIndex,
				0,
				moved
			);
			return next;
		} );
	};

	const onDragEnd = ( { active, over } ) => {
		setActiveKey( null );
		if ( ! over ) {
			setSections( curriculum.sections );
			return;
		}
		let next = sections;
		if ( isSectionKey( active.id ) ) {
			const from = sectionIndexOf( sections, active.id );
			const to = sectionIndexOf( sections, over.id );
			if ( from >= 0 && to >= 0 && from !== to ) {
				next = arrayMove( sections, from, to );
			}
		} else {
			const index = sectionIndexOf( sections, active.id );
			if ( index >= 0 && ! isSectionKey( over.id ) ) {
				const items = sections[ index ].items;
				const from = items.findIndex(
					( item ) => item.id === idOf( active.id )
				);
				const to = items.findIndex(
					( item ) => item.id === idOf( over.id )
				);
				if ( from >= 0 && to >= 0 && from !== to ) {
					next = sections.map( ( section, i ) =>
						i === index
							? {
									...section,
									items: arrayMove( items, from, to ),
								}
							: section
					);
				}
			}
		}
		const changed =
			JSON.stringify(
				next.map( ( s ) => [ s.id, s.items.map( ( i ) => i.id ) ] )
			) !==
			JSON.stringify(
				curriculum.sections.map( ( s ) => [
					s.id,
					s.items.map( ( i ) => i.id ),
				] )
			);
		if ( changed ) {
			persist( next );
		}
	};

	const moveSection = ( sectionId, delta ) => {
		const from = sections.findIndex(
			( section ) => section.id === sectionId
		);
		const to = from + delta;
		if ( from < 0 || to < 0 || to >= sections.length ) {
			return;
		}
		persist( arrayMove( sections, from, to ) );
	};

	// Moving past the first or last lesson of a section continues into the neighbouring section.
	const moveItem = ( itemId, delta ) => {
		const flat = [];
		sections.forEach( ( section, sectionIndex ) => {
			section.items.forEach( ( item, itemIndex ) =>
				flat.push( { item, sectionIndex, itemIndex } )
			);
		} );
		const position = flat.findIndex(
			( entry ) => entry.item.id === itemId
		);
		if ( position < 0 ) {
			return;
		}
		const current = flat[ position ];
		const next = sections.map( ( section ) => ( {
			...section,
			items: [ ...section.items ],
		} ) );
		const items = next[ current.sectionIndex ].items;
		const target = current.itemIndex + delta;
		if ( target >= 0 && target < items.length ) {
			next[ current.sectionIndex ].items = arrayMove(
				items,
				current.itemIndex,
				target
			);
		} else {
			const neighbour = current.sectionIndex + delta;
			if ( neighbour < 0 || neighbour >= next.length ) {
				return;
			}
			items.splice( current.itemIndex, 1 );
			if ( delta < 0 ) {
				next[ neighbour ].items.push( current.item );
			} else {
				next[ neighbour ].items.unshift( current.item );
			}
		}
		persist( next );
	};

	const allItems = sections.flatMap( ( section ) => section.items );

	return (
		<div className="publiclms-builder__tree">
			<DndContext
				sensors={ sensors }
				collisionDetection={ collisionDetection }
				onDragStart={ ( { active } ) => setActiveKey( active.id ) }
				onDragOver={ onDragOver }
				onDragEnd={ onDragEnd }
				onDragCancel={ () => {
					setActiveKey( null );
					setSections( curriculum.sections );
				} }
				accessibility={ {
					announcements,
					screenReaderInstructions: {
						draggable: __(
							'To pick up a section or lesson, press Space or Enter. Use the arrow keys to move it, then press Space or Enter to drop it, or Escape to cancel.',
							'public-lms'
						),
					},
				} }
			>
				<SortableContext
					items={ sections.map( ( section ) =>
						sectionKey( section.id )
					) }
					strategy={ verticalListSortingStrategy }
				>
					<ol className="publiclms-builder__sections">
						{ sections.map( ( section, index ) => (
							<SectionCard
								key={ section.id }
								courseId={ courseId }
								section={ section }
								sections={ sections }
								itemTypes={ itemTypes }
								renderItemActions={ renderItemActions }
								index={ index }
								count={ sections.length }
								firstItemId={ allItems[ 0 ]?.id }
								lastItemId={
									allItems[ allItems.length - 1 ]?.id
								}
								onMoveSection={ moveSection }
								onMoveItem={ moveItem }
							/>
						) ) }
					</ol>
				</SortableContext>
			</DndContext>
			{ sections.length === 0 && (
				<p className="publiclms-builder__empty">
					{ __(
						'This course has no sections yet. Add the first one to start the curriculum.',
						'public-lms'
					) }
				</p>
			) }
			<AddInline
				buttonLabel={ __( 'Add section', 'public-lms' ) }
				fieldLabel={ __( 'Section title', 'public-lms' ) }
				onAdd={ async ( value ) => {
					await addSection( courseId, value );
					notifySuccess( __( 'Section added.', 'public-lms' ) );
				} }
			/>
		</div>
	);
}
