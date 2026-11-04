import apiFetch from '@wordpress/api-fetch';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useCourseInfo } from '../shared/use-course';

const SAMPLE = [
	{
		id: 1,
		title: __( 'Getting started', 'public-lms' ),
		items: [
			{ id: 11, title: __( 'Welcome to the course', 'public-lms' ) },
			{ id: 12, title: __( 'How this course works', 'public-lms' ) },
		],
	},
	{
		id: 2,
		title: __( 'Core ideas', 'public-lms' ),
		items: [ { id: 21, title: __( 'The first big idea', 'public-lms' ) } ],
	},
];

export default function Edit( { attributes, setAttributes, context } ) {
	const { courseId, collapsible, showLockedState, headingLevel } = attributes;
	const info = useCourseInfo( context, courseId );
	const resolvedId = info?.course_id || 0;
	const [ sections, setSections ] = useState( null );

	useEffect( () => {
		if ( ! resolvedId ) {
			setSections( null );
			return;
		}
		apiFetch( { path: `/publiclms/v1/courses/${ resolvedId }/curriculum` } )
			.then( ( data ) => setSections( data.sections ) )
			.catch( () => setSections( null ) );
	}, [ resolvedId ] );

	const shown = sections && sections.length ? sections : SAMPLE;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'public-lms' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Collapsible sections', 'public-lms' ) }
						checked={ collapsible }
						onChange={ ( value ) =>
							setAttributes( { collapsible: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show locked lessons', 'public-lms' ) }
						checked={ showLockedState }
						onChange={ ( value ) =>
							setAttributes( { showLockedState: value } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Section heading level', 'public-lms' ) }
						value={ String( headingLevel ) }
						options={ [ 2, 3, 4, 5, 6 ].map( ( level ) => ( {
							value: String( level ),
							label: `H${ level }`,
						} ) ) }
						onChange={ ( value ) =>
							setAttributes( { headingLevel: Number( value ) } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						label={ __( 'Course ID', 'public-lms' ) }
						help={ __(
							'Leave at 0 to use the course of the page.',
							'public-lms'
						) }
						value={ courseId }
						onChange={ ( value ) =>
							setAttributes( { courseId: Number( value ) || 0 } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<nav { ...useBlockProps() }>
				<ol className="wp-block-publiclms-curriculum__sections">
					{ shown.map( ( section ) => (
						<li
							key={ section.id }
							className="wp-block-publiclms-curriculum__section"
						>
							<div className="wp-block-publiclms-curriculum__summary">
								<span className="wp-block-publiclms-curriculum__section-title">
									{ decodeEntities( section.title ) }
								</span>
								<span className="wp-block-publiclms-curriculum__count">
									{ sprintf(
										/* translators: %d: number of lessons. */
										_n(
											'%d lesson',
											'%d lessons',
											section.items.length,
											'public-lms'
										),
										section.items.length
									) }
								</span>
							</div>
							<ol className="wp-block-publiclms-curriculum__items">
								{ section.items.map( ( item ) => (
									<li
										key={ item.id }
										className="wp-block-publiclms-curriculum__item"
									>
										<span
											className="wp-block-publiclms-curriculum__status"
											aria-hidden="true"
										/>
										<span className="wp-block-publiclms-curriculum__title">
											{ decodeEntities( item.title ) }
										</span>
									</li>
								) ) }
							</ol>
						</li>
					) ) }
				</ol>
			</nav>
		</>
	);
}
