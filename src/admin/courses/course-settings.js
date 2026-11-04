/**
 * The course settings panel of the builder.
 */
import {
	Button,
	Panel,
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { MediaUpload } from '@wordpress/media-utils';

import { STORE_NAME } from '../../store/courses';
import { boot } from '../boot';
import { notifyError, notifySuccess } from '../components/notices';

function useTermOptions( taxonomy, emptyLabel ) {
	const terms = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', taxonomy, {
				per_page: 100,
				context: 'view',
				_fields: 'id,name',
			} ) ?? [],
		[ taxonomy ]
	);
	return [
		{ value: '0', label: emptyLabel },
		...terms.map( ( term ) => ( {
			value: String( term.id ),
			label: decodeEntities( term.name ),
		} ) ),
	];
}

export default function CourseSettings( { course } ) {
	const [ edits, setEdits ] = useState( {} );
	const [ isSaving, setIsSaving ] = useState( false );
	const { updateCourse } = useDispatch( STORE_NAME );
	const categoryOptions = useTermOptions(
		'plms_course_category',
		__( 'No category', 'public-lms' )
	);
	const levelOptions = useTermOptions(
		'plms_level',
		__( 'No level', 'public-lms' )
	);

	const value = ( key ) => ( key in edits ? edits[ key ] : course[ key ] );
	const set = ( key ) => ( next ) =>
		setEdits( ( current ) => ( { ...current, [ key ]: next } ) );
	const isDirty = Object.keys( edits ).length > 0;

	const statusOptions = [
		{ value: 'draft', label: __( 'Draft', 'public-lms' ) },
		{ value: 'pending', label: __( 'Pending review', 'public-lms' ) },
		{
			value: 'publish',
			label: __( 'Published', 'public-lms' ),
			disabled: ! boot.permissions.publishCourses,
		},
		{
			value: 'private',
			label: __( 'Private', 'public-lms' ),
			disabled: ! boot.permissions.publishCourses,
		},
	];
	if ( course.status === 'future' ) {
		statusOptions.push( {
			value: 'future',
			label: __( 'Scheduled', 'public-lms' ),
		} );
	}

	const save = async ( event ) => {
		event.preventDefault();
		setIsSaving( true );
		try {
			// The preview URL is local state only.
			// eslint-disable-next-line camelcase
			const { featured_image_url, ...data } = edits;
			await updateCourse( course.id, data );
			setEdits( {} );
			notifySuccess( __( 'Course settings saved.', 'public-lms' ) );
		} catch ( error ) {
			notifyError( error );
		} finally {
			setIsSaving( false );
		}
	};

	const imageUrl =
		'featured_media' in edits
			? edits.featured_image_url
			: course.featured_image;

	return (
		<form className="publiclms-builder__settings" onSubmit={ save }>
			<Panel header={ __( 'Course settings', 'public-lms' ) }>
				<PanelBody>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Title', 'public-lms' ) }
						value={ decodeEntities( value( 'title' ) ) }
						onChange={ set( 'title' ) }
						required
					/>
					<TextareaControl
						label={ __( 'Short description', 'public-lms' ) }
						help={ __(
							'Shown on course cards and at the top of the course page.',
							'public-lms'
						) }
						value={ value( 'excerpt' ) }
						onChange={ set( 'excerpt' ) }
						rows={ 4 }
					/>
					<div className="publiclms-media-field">
						<span className="publiclms-media-field__label">
							{ __( 'Featured image', 'public-lms' ) }
						</span>
						{ imageUrl && (
							<img
								className="publiclms-media-field__preview"
								src={ imageUrl }
								alt=""
							/>
						) }
						<div className="publiclms-media-field__actions">
							<MediaUpload
								allowedTypes={ [ 'image' ] }
								value={ value( 'featured_media' ) }
								title={ __( 'Featured image', 'public-lms' ) }
								onSelect={ ( media ) =>
									setEdits( ( current ) => ( {
										...current,
										featured_media: media.id,
										featured_image_url:
											media.sizes?.medium?.url ||
											media.url,
									} ) )
								}
								render={ ( { open } ) => (
									<Button
										variant="secondary"
										onClick={ open }
									>
										{ imageUrl
											? __(
													'Replace image',
													'public-lms'
												)
											: __(
													'Choose image',
													'public-lms'
												) }
									</Button>
								) }
							/>
							{ imageUrl && (
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () =>
										setEdits( ( current ) => ( {
											...current,
											featured_media: 0,
											featured_image_url: '',
										} ) )
									}
								>
									{ __( 'Remove', 'public-lms' ) }
								</Button>
							) }
						</div>
					</div>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Category', 'public-lms' ) }
						value={ String( value( 'categories' )?.[ 0 ] ?? 0 ) }
						options={ categoryOptions }
						onChange={ ( next ) =>
							set( 'categories' )(
								Number( next ) ? [ Number( next ) ] : []
							)
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Level', 'public-lms' ) }
						value={ String( value( 'levels' )?.[ 0 ] ?? 0 ) }
						options={ levelOptions }
						onChange={ ( next ) =>
							set( 'levels' )(
								Number( next ) ? [ Number( next ) ] : []
							)
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Access', 'public-lms' ) }
						value={
							value( 'access_mode' ) === 'closed'
								? 'closed'
								: 'free'
						}
						options={ [
							{
								value: 'free',
								label: __(
									'Free: anyone with an account can enrol',
									'public-lms'
								),
							},
							{
								value: 'closed',
								label: __(
									'Invitation only: you enrol learners',
									'public-lms'
								),
							},
						] }
						onChange={ set( 'access_mode' ) }
					/>
					<TextControl
						type="number"
						__next40pxDefaultSize
						label={ __( 'Duration in minutes', 'public-lms' ) }
						help={ __(
							'Leave at 0 to add up the lesson durations.',
							'public-lms'
						) }
						min={ 0 }
						value={ value( 'duration' ) }
						onChange={ ( next ) =>
							set( 'duration' )( Number( next ) || 0 )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Status', 'public-lms' ) }
						value={ value( 'status' ) }
						options={ statusOptions }
						onChange={ set( 'status' ) }
					/>
					<div className="publiclms-form__actions">
						<Button
							variant="primary"
							type="submit"
							isBusy={ isSaving }
							disabled={ isSaving || ! isDirty }
						>
							{ __( 'Save settings', 'public-lms' ) }
						</Button>
					</div>
				</PanelBody>
			</Panel>
		</form>
	);
}
