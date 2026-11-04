/**
 * The `publiclms/course-query` Query Loop variation (CONTRACTS.md A5.5).
 *
 * A core Query Loop over plms_course whose cards are core blocks with
 * values from the bindings source. Level and category filters are core's
 * taxonomy controls; the variation adds "Hide courses I am enrolled in" and
 * "Show courses related to the current course" (contract 1.2.0).
 */
import { InspectorControls } from '@wordpress/block-editor';
import { registerBlockVariation } from '@wordpress/blocks';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

const NAMESPACE = 'publiclms/course-query';

registerBlockVariation( 'core/query', {
	name: NAMESPACE,
	title: __( 'Course Grid', 'public-lms' ),
	description: __(
		'A grid of courses with their image, title, level and price.',
		'public-lms'
	),
	icon: 'welcome-learn-more',
	category: 'publiclms',
	keywords: [ 'courses', 'catalogue', 'lms' ],
	isActive: [ 'namespace' ],
	scope: [ 'inserter' ],
	allowedControls: [ 'inherit', 'order', 'taxQuery', 'search', 'author' ],
	attributes: {
		namespace: NAMESPACE,
		query: {
			perPage: 9,
			pages: 0,
			offset: 0,
			postType: 'plms_course',
			order: 'desc',
			orderBy: 'date',
			author: '',
			search: '',
			exclude: [],
			sticky: '',
			inherit: false,
			publiclmsHideEnrolled: false,
			publiclmsRelated: false,
		},
		align: 'wide',
	},
	innerBlocks: [
		[
			'core/post-template',
			{ layout: { type: 'grid', columnCount: 3 } },
			[
				[
					'core/post-featured-image',
					{ isLink: true, aspectRatio: '16/9' },
				],
				[
					'core/paragraph',
					{
						metadata: {
							bindings: {
								content: {
									source: 'publiclms/course-field',
									args: { key: 'level' },
								},
							},
						},
						fontSize: 'small',
					},
				],
				[ 'core/post-title', { isLink: true, level: 3 } ],
				[ 'core/post-excerpt', { excerptLength: 20 } ],
				[
					'core/paragraph',
					{
						metadata: {
							bindings: {
								content: {
									source: 'publiclms/course-field',
									args: { key: 'price' },
								},
							},
						},
					},
				],
			],
		],
		[ 'core/query-pagination' ],
		[
			'core/query-no-results',
			{},
			[
				[
					'core/paragraph',
					{ content: __( 'No courses found.', 'public-lms' ) },
				],
			],
		],
	],
} );

const withHideEnrolledControl = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => {
		if (
			props.name !== 'core/query' ||
			props.attributes.namespace !== NAMESPACE
		) {
			return <BlockEdit { ...props } />;
		}
		const { query } = props.attributes;
		return (
			<>
				<BlockEdit { ...props } />
				<InspectorControls>
					<PanelBody title={ __( 'Courses', 'public-lms' ) }>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Hide courses the learner is enrolled in',
								'public-lms'
							) }
							checked={ !! query.publiclmsHideEnrolled }
							onChange={ ( value ) =>
								props.setAttributes( {
									query: {
										...query,
										publiclmsHideEnrolled: value,
									},
								} )
							}
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Show courses related to the current course',
								'public-lms'
							) }
							help={ __(
								'Leaves the course on screen out and lists courses from its categories first.',
								'public-lms'
							) }
							checked={ !! query.publiclmsRelated }
							onChange={ ( value ) =>
								props.setAttributes( {
									query: {
										...query,
										publiclmsRelated: value,
									},
								} )
							}
						/>
					</PanelBody>
				</InspectorControls>
			</>
		);
	},
	'withPublicLmsHideEnrolledControl'
);

addFilter(
	'editor.BlockEdit',
	'public-lms/course-query',
	withHideEnrolledControl
);
