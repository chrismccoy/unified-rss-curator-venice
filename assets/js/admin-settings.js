/**
 * Admin Settings JS
 *
 * Handles Venice.ai API Key Verification and Modal Interactions
 * for the plugin settings page.
 *
 * @package    UnifiedCurator
 * @version    1.0.0
 * @author     UnifiedCurator
 */

/**
 * Initialize Settings Scripts.
 */
jQuery( document ).ready( function( $ ) {
	'use strict';

	/**
	 * Handle API Key Verification Click.
	 *
	 * Sends the currently entered API Key to the backend via AJAX
	 * to verify connectivity with Venice.ai.
	 */
	$( '#urf-verify-btn' ).on( 'click', function( e ) {
		e.preventDefault();

		const $btn   = $( this );
		const $msg   = $( '#urf-verify-msg' );
		const apiKey = $( '#urf_venice_api_key' ).val();

		$msg.text( 'Testing Venice connection...' ).css( 'color', '#666' );
		$btn.prop( 'disabled', true );

		$.ajax( {
			url: urf_settings.ajax_url,
			type: 'POST',
			data: {
				action: 'urf_verify_venice',
				nonce: urf_settings.nonce,
				api_key: apiKey
			},
			/**
			 * Handle successful AJAX response.
			 */
			success: function( res ) {
				if ( res.success ) {
					$msg.html( '<span class="dashicons dashicons-yes"></span> Verified' ).css( 'color', 'green' );
				} else {
					$msg.html( '<span class="dashicons dashicons-warning"></span> ' + res.data ).css( 'color', '#d63638' );
				}
			},
			/**
			 * Handle AJAX error.
			 */
			error: function( xhr, status, error ) {
				$msg.html( '<span class="dashicons dashicons-warning"></span> Connection failed: ' + error ).css( 'color', '#d63638' );
			},
			complete: function() {
				$btn.prop( 'disabled', false );
			}
		} );
	} );

	const $modal = $( '#urf-restore-modal' );
	const $body  = $( 'body' );

	/**
	 * Open the Restore Prompt Modal.
	 */
	$( '#urf-trigger-modal' ).on( 'click', function( e ) {
		e.preventDefault();
		$body.addClass( 'urf-modal-active' );
	} );

	/**
	 * Close the Modal with Cancel Button.
	 */
	$( '#urf-modal-cancel' ).on( 'click', function( e ) {
		e.preventDefault();
		$body.removeClass( 'urf-modal-active' );
	} );

	/**
	 * Close the Modal outside the area
	 */
	$modal.on( 'click', function( e ) {
		if ( $( e.target ).is( '#urf-restore-modal' ) ) {
			$body.removeClass( 'urf-modal-active' );
		}
	} );

	/**
	 * Confirm Restore Action.
	 */
	$( '#urf-modal-confirm' ).on( 'click', function( e ) {
		e.preventDefault();

		// Perform Restore
		$( '#urf_ai_prompt' ).val( urf_settings.default_prompt );

		const $triggerBtn  = $( '#urf-trigger-modal' );
		const originalText = $triggerBtn.text();

		$triggerBtn.text( 'Restored!' ).css( 'color', 'green' );

		$body.removeClass( 'urf-modal-active' );

		setTimeout( () => {
			$triggerBtn.text( originalText ).css( 'color', '' );
		}, 2000 );
	} );

} );
