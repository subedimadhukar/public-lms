import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	CheckboxControl,
	PanelBody,
	SelectControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const TABS = {
	'in-progress': __( 'In progress', 'public-lms' ),
	completed: __( 'Completed', 'public-lms' ),
};

export default function Edit( { attributes, setAttributes } ) {
	const { tabs, defaultTab } = attributes;
	const shown = Object.keys( TABS ).filter( ( tab ) => tabs.includes( tab ) );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Tabs', 'public-lms' ) }>
					{ Object.entries( TABS ).map( ( [ id, label ] ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ id }
							label={ label }
							checked={ tabs.includes( id ) }
							onChange={ ( checked ) =>
								setAttributes( {
									tabs: checked
										? Object.keys( TABS ).filter(
												( key ) =>
													key === id ||
													tabs.includes( key )
											)
										: tabs.filter( ( key ) => key !== id ),
								} )
							}
						/>
					) ) }
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Open first', 'public-lms' ) }
						value={ defaultTab }
						options={ shown.map( ( id ) => ( {
							value: id,
							label: TABS[ id ],
						} ) ) }
						onChange={ ( value ) =>
							setAttributes( { defaultTab: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<h2 className="wp-block-publiclms-student-dashboard__heading">
					{ __( 'Welcome back', 'public-lms' ) }
				</h2>
				<div className="wp-block-publiclms-student-dashboard__tabs">
					{ shown.map( ( id ) => (
						<span
							key={ id }
							className="wp-block-publiclms-student-dashboard__tab"
							aria-selected={ id === defaultTab }
						>
							{ TABS[ id ] }
						</span>
					) ) }
				</div>
				<p>
					{ __(
						'Each learner sees their own courses here, with progress and a link to continue.',
						'public-lms'
					) }
				</p>
			</div>
		</>
	);
}
