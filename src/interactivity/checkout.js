/**
 * Checkout, part of the `publiclms/learning` store (ADR-046).
 *
 * The server prices everything; this module only asks it. "Apply" sends
 * the coupon to POST /orders/quote and shows the new totals; "Place order"
 * sends the form to POST /orders/checkout and follows the redirect to the
 * payment provider or to the order. Without JavaScript the same form posts
 * to admin-post.php.
 */
import { getContext, getElement, store } from '@wordpress/interactivity';

import { post } from './api';

const { state } = store( 'publiclms/learning', {
	actions: {
		/**
		 * Applies (or removes) the coupon typed in the form.
		 *
		 * @param {MouseEvent} event Click on "Apply".
		 */
		*applyCoupon( event ) {
			event.preventDefault();
			const context = getContext();
			if ( context.isBusy ) {
				return;
			}
			const form = getElement().ref.form;
			const data = new window.FormData( form );
			const code = String( data.get( 'coupon' ) || '' ).trim();
			context.isBusy = true;
			context.couponMessage = '';
			try {
				const quote = yield post( 'orders/quote', {
					courses: context.courses,
					coupon: code,
					email: String( data.get( 'email' ) || '' ),
				} );
				context.coupon = quote.coupon;
				context.total = quote.total_formatted;
				context.discount = `−${ quote.discount_formatted }`;
				context.hasDiscount = quote.discount > 0;
				context.needsPayment = quote.total > 0;
				context.couponMessage = code
					? state.i18n.couponApplied
					: state.i18n.couponRemoved;
			} catch ( error ) {
				context.couponMessage = error.message || state.i18n.error;
			} finally {
				context.isBusy = false;
			}
		},

		/**
		 * Places the order and goes to the provider or the order.
		 *
		 * @param {SubmitEvent} event Submit event.
		 */
		*placeOrder( event ) {
			event.preventDefault();
			const context = getContext();
			const form = getElement().ref;
			if ( context.isBusy || ! form.reportValidity() ) {
				return;
			}
			const data = new window.FormData( form );
			context.isBusy = true;
			context.message = '';
			try {
				const result = yield post( 'orders/checkout', {
					courses: context.courses,
					gateway: String( data.get( 'gateway' ) || '' ),
					coupon: String(
						data.get( 'coupon' ) || context.coupon || ''
					),
					email: String( data.get( 'email' ) || '' ),
					name: String( data.get( 'name' ) || '' ),
				} );
				context.message = state.i18n.redirecting;
				window.location.assign( result.redirect );
			} catch ( error ) {
				context.message = error.message || state.i18n.error;
				context.isBusy = false;
			}
		},
	},
} );
