/**
 * The Settings screen.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Panel,
	PanelBody,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

import { boot } from '../boot';
import { notifyError, notifySuccess } from '../components/notices';

export default function SettingsScreen() {
	const [ settings, setSettings ] = useState( null );
	const [ edits, setEdits ] = useState( {} );
	const [ isSaving, setIsSaving ] = useState( false );
	const pages = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'postType', 'page', {
				per_page: 100,
				status: 'publish',
				_fields: 'id,title',
			} ) ?? [],
		[]
	);

	useEffect( () => {
		apiFetch( { path: '/publiclms/v1/settings' } )
			.then( setSettings )
			.catch( notifyError );
	}, [] );

	if ( ! settings ) {
		return (
			<div className="publiclms-screen publiclms-screen--loading">
				<Spinner />
			</div>
		);
	}

	const value = ( key ) => ( key in edits ? edits[ key ] : settings[ key ] );
	const set = ( key ) => ( next ) =>
		setEdits( ( current ) => ( { ...current, [ key ]: next } ) );

	const save = async ( event ) => {
		event.preventDefault();
		setIsSaving( true );
		try {
			setSettings(
				await apiFetch( {
					path: '/publiclms/v1/settings',
					method: 'POST',
					data: edits,
				} )
			);
			setEdits( {} );
			notifySuccess( __( 'Settings saved.', 'public-lms' ) );
		} catch ( error ) {
			notifyError( error );
		} finally {
			setIsSaving( false );
		}
	};

	const createPage = async () => {
		setIsSaving( true );
		try {
			setSettings(
				await apiFetch( {
					path: '/publiclms/v1/settings/dashboard-page',
					method: 'POST',
				} )
			);
			notifySuccess( __( 'Dashboard page created.', 'public-lms' ) );
		} catch ( error ) {
			notifyError( error );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<form className="publiclms-screen publiclms-settings" onSubmit={ save }>
			<div className="publiclms-screen__header">
				<h2 className="publiclms-screen__title">
					{ __( 'Settings', 'public-lms' ) }
				</h2>
			</div>
			<Panel>
				<PanelBody title={ __( 'Learner pages', 'public-lms' ) }>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Student dashboard page', 'public-lms' ) }
						help={ __(
							"This page uses the Student Dashboard template and lists each learner's courses.",
							'public-lms'
						) }
						value={ String( value( 'dashboard_page_id' ) ) }
						options={ [
							{
								value: '0',
								label: __( '— Select a page —', 'public-lms' ),
							},
							...pages.map( ( page ) => ( {
								value: String( page.id ),
								label:
									decodeEntities(
										page.title?.rendered || ''
									) || `#${ page.id }`,
							} ) ),
						] }
						onChange={ ( next ) =>
							set( 'dashboard_page_id' )( Number( next ) )
						}
					/>
					{ settings.dashboard_page_url ? (
						<p>
							<a href={ settings.dashboard_page_url }>
								{ __(
									'Open the dashboard page',
									'public-lms'
								) }
							</a>
						</p>
					) : (
						<Button
							variant="secondary"
							onClick={ createPage }
							disabled={ isSaving }
						>
							{ __( 'Create a dashboard page', 'public-lms' ) }
						</Button>
					) }
				</PanelBody>
				{ boot.permissions.manageOrders && (
					<PanelBody title={ __( 'Prices', 'public-lms' ) }>
						<p>
							{ __(
								'The currency, checkout page and payment methods are under Payments.',
								'public-lms'
							) }{ ' ' }
							<a
								href={ `${ boot.adminUrl }admin.php?page=publiclms-payments` }
							>
								{ __( 'Open Payments', 'public-lms' ) }
							</a>
						</p>
					</PanelBody>
				) }
				{ boot.permissions.deletePlugins && (
					<PanelBody title={ __( 'Data', 'public-lms' ) }>
						<ToggleControl
							label={ __(
								'Delete all Public LMS data when the plugin is deleted',
								'public-lms'
							) }
							help={ __(
								'Courses, lessons, enrolments, progress and orders are removed for good. Leave off unless you are sure.',
								'public-lms'
							) }
							checked={ !! value( 'remove_data_on_uninstall' ) }
							onChange={ set( 'remove_data_on_uninstall' ) }
						/>
					</PanelBody>
				) }
			</Panel>
			<div className="publiclms-form__actions">
				<Button
					variant="primary"
					type="submit"
					isBusy={ isSaving }
					disabled={ isSaving || Object.keys( edits ).length === 0 }
				>
					{ __( 'Save settings', 'public-lms' ) }
				</Button>
			</div>
		</form>
	);
}
