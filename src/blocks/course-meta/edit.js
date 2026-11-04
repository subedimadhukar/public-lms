import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	CheckboxControl,
	PanelBody,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useCourseInfo } from '../shared/use-course';

const FIELDS = {
	lessons: {
		label: __( 'Lessons', 'public-lms' ),
		key: 'lesson_count',
		sample: sprintf(
			/* translators: %d: number of lessons. */
			_n( '%d lesson', '%d lessons', 12, 'public-lms' ),
			12
		),
	},
	duration: {
		label: __( 'Duration', 'public-lms' ),
		key: 'duration',
		/* translators: 1: hours, 2: minutes. */
		sample: sprintf( __( '%1$dh %2$dm', 'public-lms' ), 3, 20 ),
	},
	level: {
		label: __( 'Level', 'public-lms' ),
		key: 'level',
		sample: __( 'Beginner', 'public-lms' ),
	},
	instructor: {
		label: __( 'Instructor', 'public-lms' ),
		key: 'instructor',
		sample: __( 'Alex Morgan', 'public-lms' ),
	},
	learners: {
		label: __( 'Learners', 'public-lms' ),
		key: 'enrolled_count',
		sample: sprintf(
			/* translators: %d: number of learners. */
			_n( '%d learner', '%d learners', 128, 'public-lms' ),
			128
		),
	},
	price: {
		label: __( 'Price', 'public-lms' ),
		key: 'price',
		sample: __( 'Free', 'public-lms' ),
	},
	updated: {
		label: __( 'Last updated', 'public-lms' ),
		key: '',
		sample: __( 'Last month', 'public-lms' ),
	},
};

export default function Edit( { attributes, setAttributes, context } ) {
	const { courseId, fields, showLabels, layout } = attributes;
	const info = useCourseInfo( context, courseId );

	const toggleField = ( id, checked ) =>
		setAttributes( {
			fields: checked
				? Object.keys( FIELDS ).filter(
						( key ) => key === id || fields.includes( key )
					)
				: fields.filter( ( key ) => key !== id ),
		} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Details to show', 'public-lms' ) }>
					{ Object.entries( FIELDS ).map( ( [ id, field ] ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ id }
							label={ field.label }
							checked={ fields.includes( id ) }
							onChange={ ( checked ) =>
								toggleField( id, checked )
							}
						/>
					) ) }
				</PanelBody>
				<PanelBody title={ __( 'Layout', 'public-lms' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show labels', 'public-lms' ) }
						checked={ showLabels }
						onChange={ ( value ) =>
							setAttributes( { showLabels: value } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Arrangement', 'public-lms' ) }
						value={ layout }
						options={ [
							{
								value: 'list',
								label: __( 'List', 'public-lms' ),
							},
							{
								value: 'inline',
								label: __( 'Inline', 'public-lms' ),
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { layout: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<dl { ...useBlockProps( { className: `is-layout-${ layout }` } ) }>
				{ fields
					.filter( ( id ) => FIELDS[ id ] )
					.map( ( id ) => (
						<div
							key={ id }
							className="wp-block-publiclms-course-meta__item"
						>
							{ showLabels && (
								<dt className="wp-block-publiclms-course-meta__label">
									{ FIELDS[ id ].label }
								</dt>
							) }
							<dd className="wp-block-publiclms-course-meta__value">
								{ ( FIELDS[ id ].key &&
									info?.fields?.[ FIELDS[ id ].key ] ) ||
									FIELDS[ id ].sample }
							</dd>
						</div>
					) ) }
			</dl>
		</>
	);
}
