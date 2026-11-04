/**
 * The Orders screen: every order, newest first, filtered by status or
 * searched by number, email or payment reference. An order opens in a
 * panel with its lines, payments, history and actions (mark paid, cancel,
 * refund). Amounts arrive formatted by the server for the admin's locale.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Modal,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs, getQueryArg } from '@wordpress/url';

import ConfirmDialog from '../components/confirm-dialog';
import { notifyError, notifySuccess } from '../components/notices';

const STATUSES = () => [
	{ value: '', label: __( 'All', 'public-lms' ) },
	{ value: 'on-hold', label: __( 'On hold', 'public-lms' ) },
	{ value: 'pending', label: __( 'Waiting for payment', 'public-lms' ) },
	{ value: 'completed', label: __( 'Paid', 'public-lms' ) },
	{ value: 'failed', label: __( 'Failed', 'public-lms' ) },
	{ value: 'cancelled', label: __( 'Cancelled', 'public-lms' ) },
	{ value: 'refunded', label: __( 'Refunded', 'public-lms' ) },
];

/**
 * Formats an ISO date for the admin's locale.
 *
 * @param {string} value ISO 8601 date.
 * @return {string} Date and time.
 */
const when = ( value ) => ( value ? new Date( value ).toLocaleString() : '' );

/**
 * One order in a modal, with its actions.
 *
 * @param {Object}                  props
 * @param {number}                  props.orderId   Order ID.
 * @param {() => void}              props.onClose   Close the panel.
 * @param {(order: Object) => void} props.onChanged Called with the order after an action.
 */
function OrderPanel( { orderId, onClose, onChanged } ) {
	const [ order, setOrder ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ confirm, setConfirm ] = useState( null );

	useEffect( () => {
		apiFetch( { path: `/publiclms/v1/orders/${ orderId }` } )
			.then( setOrder )
			.catch( ( error ) => {
				notifyError( error );
				onClose();
			} );
	}, [ orderId, onClose ] );

	const act = async ( action, data, message ) => {
		setBusy( true );
		try {
			const next = await apiFetch( {
				path: `/publiclms/v1/orders/${ orderId }/${ action }`,
				method: 'POST',
				data,
			} );
			setOrder( next );
			onChanged( next );
			notifySuccess( message );
		} catch ( error ) {
			notifyError( error );
		} finally {
			setBusy( false );
			setConfirm( null );
		}
	};

	const title = sprintf(
		/* translators: %d: order number. */
		__( 'Order #%d', 'public-lms' ),
		orderId
	);

	return (
		<Modal
			title={ title }
			onRequestClose={ onClose }
			size="medium"
			className="publiclms-order-panel"
		>
			{ ! order && <Spinner /> }
			{ order && (
				<>
					<dl className="publiclms-order-panel__facts">
						<div>
							<dt>{ __( 'Status', 'public-lms' ) }</dt>
							<dd>{ order.status_label }</dd>
						</div>
						<div>
							<dt>{ __( 'Date', 'public-lms' ) }</dt>
							<dd>{ when( order.created_at ) }</dd>
						</div>
						<div>
							<dt>{ __( 'Buyer', 'public-lms' ) }</dt>
							<dd>
								{ order.customer.name || '—' }
								<br />
								{ order.billing_email }
							</dd>
						</div>
						<div>
							<dt>{ __( 'Payment method', 'public-lms' ) }</dt>
							<dd>
								{ order.gateway_title }
								{ order.gateway_order_id && (
									<>
										<br />
										<code>{ order.gateway_order_id }</code>
									</>
								) }
							</dd>
						</div>
					</dl>
					<table className="widefat">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Course', 'public-lms' ) }
								</th>
								<th scope="col">
									{ __( 'Price', 'public-lms' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ order.items.map( ( item ) => (
								<tr key={ `${ item.type }-${ item.id }` }>
									<td>{ decodeEntities( item.name ) }</td>
									<td>{ item.subtotal_formatted }</td>
								</tr>
							) ) }
						</tbody>
						<tfoot>
							{ order.discount_total > 0 && (
								<tr>
									<th scope="row">
										{ sprintf(
											/* translators: %s: coupon code. */
											__( 'Coupon %s', 'public-lms' ),
											order.coupon_code
										) }
									</th>
									<td>−{ order.discount_formatted }</td>
								</tr>
							) }
							<tr>
								<th scope="row">
									{ __( 'Total', 'public-lms' ) }
								</th>
								<td>
									<strong>{ order.total_formatted }</strong>
								</td>
							</tr>
						</tfoot>
					</table>

					{ order.transactions.length > 0 && (
						<>
							<h3>
								{ __( 'Payments and refunds', 'public-lms' ) }
							</h3>
							<ul className="publiclms-order-panel__list">
								{ order.transactions.map( ( txn ) => (
									<li key={ txn.id }>
										{ txn.type === 'refund'
											? __( 'Refund', 'public-lms' )
											: __(
													'Payment',
													'public-lms'
												) }{ ' ' }
										{ txn.amount_formatted } —{ ' ' }
										{ when( txn.created_at ) }
										{ txn.gateway_transaction_id && (
											<>
												{ ' ' }
												<code>
													{
														txn.gateway_transaction_id
													}
												</code>
											</>
										) }
									</li>
								) ) }
							</ul>
						</>
					) }

					{ order.history.length > 0 && (
						<>
							<h3>{ __( 'History', 'public-lms' ) }</h3>
							<ul className="publiclms-order-panel__list">
								{ order.history.map( ( entry, index ) => (
									<li key={ index }>
										<time dateTime={ entry.at }>
											{ when( entry.at ) }
										</time>
										: { entry.text }
									</li>
								) ) }
							</ul>
						</>
					) }

					<div className="publiclms-form__actions">
						{ order.actions.mark_paid && (
							<Button
								variant="primary"
								isBusy={ busy }
								disabled={ busy }
								onClick={ () =>
									setConfirm( {
										title: __(
											'Mark this order paid?',
											'public-lms'
										),
										body: __(
											'Do this once the money has arrived. The buyer is enrolled in the courses and gets an email.',
											'public-lms'
										),
										label: __( 'Mark paid', 'public-lms' ),
										run: () =>
											act(
												'complete',
												{},
												__(
													'Order marked paid.',
													'public-lms'
												)
											),
									} )
								}
							>
								{ __( 'Mark paid', 'public-lms' ) }
							</Button>
						) }
						{ order.actions.refund && (
							<Button
								variant="secondary"
								isDestructive
								disabled={ busy }
								onClick={ () =>
									setConfirm( {
										title: __(
											'Refund this order?',
											'public-lms'
										),
										body: order.actions.refund_at_gateway
											? sprintf(
													/* translators: 1: amount, 2: payment method. */
													__(
														'%1$s goes back to the buyer through %2$s, and the courses from this order close for them.',
														'public-lms'
													),
													order.total_formatted,
													order.gateway_title
												)
											: __(
													'Return the money to the buyer yourself first. This records the refund and closes the courses from this order for them.',
													'public-lms'
												),
										label: __( 'Refund', 'public-lms' ),
										destructive: true,
										run: () =>
											act(
												'refund',
												{
													manual: ! order.actions
														.refund_at_gateway,
												},
												__(
													'Order refunded.',
													'public-lms'
												)
											),
									} )
								}
							>
								{ order.actions.refund_at_gateway
									? __( 'Refund', 'public-lms' )
									: __( 'Record a refund', 'public-lms' ) }
							</Button>
						) }
						{ order.actions.cancel && (
							<Button
								variant="tertiary"
								isDestructive
								disabled={ busy }
								onClick={ () =>
									act(
										'cancel',
										{},
										__( 'Order cancelled.', 'public-lms' )
									)
								}
							>
								{ __( 'Cancel order', 'public-lms' ) }
							</Button>
						) }
					</div>
				</>
			) }
			{ confirm && (
				<ConfirmDialog
					title={ confirm.title }
					message={ confirm.body }
					confirmLabel={ confirm.label }
					isDestructive={ !! confirm.destructive }
					onConfirm={ confirm.run }
					onCancel={ () => setConfirm( null ) }
				/>
			) }
		</Modal>
	);
}

export default function OrdersScreen() {
	const [ status, setStatus ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ rows, setRows ] = useState( null );
	const [ total, setTotal ] = useState( 0 );
	const [ next, setNext ] = useState( '' );
	const [ open, setOpen ] = useState(
		Number( getQueryArg( window.location.href, 'order' ) ) || 0
	);

	const fetchPage = useCallback(
		async ( before ) => {
			const response = await apiFetch( {
				path: addQueryArgs( '/publiclms/v1/orders', {
					status,
					search,
					before,
					per_page: 30,
				} ),
				parse: false,
			} );
			setTotal( Number( response.headers.get( 'X-WP-Total' ) || 0 ) );
			setNext( response.headers.get( 'X-PublicLMS-Next-Cursor' ) || '' );
			return response.json();
		},
		[ status, search ]
	);

	const reload = useCallback( () => {
		setRows( null );
		fetchPage( 0 )
			.then( setRows )
			.catch( ( error ) => {
				notifyError( error );
				setRows( [] );
			} );
	}, [ fetchPage ] );

	useEffect( reload, [ reload ] );

	const close = useCallback( () => setOpen( 0 ), [] );
	const changed = useCallback(
		( order ) =>
			setRows( ( current ) =>
				( current || [] ).map( ( row ) =>
					row.id === order.id ? order : row
				)
			),
		[]
	);

	return (
		<div className="publiclms-screen publiclms-orders">
			<div className="publiclms-screen__header">
				<h2 className="publiclms-screen__title">
					{ __( 'Orders', 'public-lms' ) }
				</h2>
			</div>
			<div className="publiclms-bank__filters">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Show', 'public-lms' ) }
					value={ status }
					options={ STATUSES() }
					onChange={ setStatus }
				/>
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search orders', 'public-lms' ) }
					placeholder={ __(
						'Number, email or payment reference',
						'public-lms'
					) }
					value={ search }
					onChange={ setSearch }
				/>
			</div>
			{ rows === null && <Spinner /> }
			{ rows?.length === 0 && (
				<p className="publiclms-builder__empty">
					{ __( 'No orders yet.', 'public-lms' ) }
				</p>
			) }
			{ rows?.length > 0 && (
				<>
					<p className="publiclms-screen__summary">
						{ sprintf(
							/* translators: %d: number of orders. */
							_n( '%d order', '%d orders', total, 'public-lms' ),
							total
						) }
					</p>
					<table className="widefat striped">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Order', 'public-lms' ) }
								</th>
								<th scope="col">
									{ __( 'Date', 'public-lms' ) }
								</th>
								<th scope="col">
									{ __( 'Buyer', 'public-lms' ) }
								</th>
								<th scope="col">
									{ __( 'Courses', 'public-lms' ) }
								</th>
								<th scope="col">
									{ __( 'Total', 'public-lms' ) }
								</th>
								<th scope="col">
									{ __( 'Payment method', 'public-lms' ) }
								</th>
								<th scope="col">
									{ __( 'Status', 'public-lms' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr key={ row.id }>
									<td>
										<Button
											variant="link"
											onClick={ () => setOpen( row.id ) }
										>
											{ sprintf(
												/* translators: %d: order number. */
												__( 'Order #%d', 'public-lms' ),
												row.id
											) }
										</Button>
									</td>
									<td>{ when( row.created_at ) }</td>
									<td>
										{ row.customer.name || '—' }
										<br />
										<small>{ row.billing_email }</small>
									</td>
									<td>
										{ row.items
											.map( ( item ) =>
												decodeEntities( item.name )
											)
											.join( ', ' ) }
									</td>
									<td>{ row.total_formatted }</td>
									<td>{ row.gateway_title }</td>
									<td>
										<span
											className={ `publiclms-status is-${ row.status }` }
										>
											{ row.status_label }
										</span>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					{ next && (
						<Button
							variant="secondary"
							onClick={ () =>
								fetchPage( next )
									.then( ( more ) =>
										setRows( ( current ) => [
											...current,
											...more,
										] )
									)
									.catch( notifyError )
							}
						>
							{ __( 'Load more', 'public-lms' ) }
						</Button>
					) }
				</>
			) }
			{ open > 0 && (
				<OrderPanel
					orderId={ open }
					onClose={ close }
					onChanged={ changed }
				/>
			) }
		</div>
	);
}
