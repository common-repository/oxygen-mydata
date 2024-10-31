/**
 * Oxygen Payment Js code for handling modal windows of payment
 *
 * @package Oxygen Payments
 * @version 1.0.0
 * @author Oxygen
 * @since 1.0.0
 *
 * This file handle oxygen payment window
 */

jQuery( document ).ready(

	function ( $ ) {

		$.blockUI( { message: '' } );

		if ($( window ).width() < 1024) {
				$( '.full_cover_mobile' ).show();
				$.unblockUI();

		} else {
			$( "#openOxyPayment" ).show();
		}

		$( ".close_payment" ).click(
			function () {
				$( "#openOxyPayment" ).hide();
				$( ".full_cover_mobile" ).hide();

				$.unblockUI();
			}
		);

		function makeRequest(code,payment_id) {

			let url            = window.location.origin + '/wp-content/plugins/oxygen-mydata/inc/request-handle-message.php';
			let formData       = {"code": code, "payment_id": payment_id};
			let urlEncodedData = '', urlEncodedDataPairs = [], name;
			for (name in formData) {
				urlEncodedDataPairs.push( encodeURIComponent( name ) + '=' + encodeURIComponent( formData[name] ) );
			}
			urlEncodedData = urlEncodedDataPairs.join( '&' ).replace( /%20/g, '+' );

			$.ajax(
				{
					url: url,
					data: urlEncodedData,
					contentType: 'application/x-www-form-urlencoded; charset=UTF-8',
					type: 'POST',
					beforeSend: function() {
						$('body').css({"pointer-events":"none"},{"overflow":"hidden"});
						$('.overlay_loader').show();
						$( '.response_payment' ).empty().append( 'Please do not refresh the page in order to complete the payment.' );
					},
					success: function (response) {
						if (response !== '' && response !== null) {
							let parsed = JSON.parse( response );

							if (parsed['status'] === 'is_paid' && parsed['redirect'] !== '') {
								let redirection_link = parsed['redirect'];
								var value = redirection_link.substring(redirection_link.lastIndexOf('&') + 1);
								redirection_link = redirection_link.replace('&'+value, "");
								window.location.replace( redirection_link );

							} else {
								$( '.response_payment' ).empty().append( 'Your payment failed.' );
								window.location.replace( window.location.origin );
							}
						}
					}
				}
			);

		}

		function handleMessage(event) {

				let message_data = JSON.parse( event.data );
				let code         = message_data['code'];
				let payment_id   = message_data['id'];

			if (code === 200) {
				makeRequest( code,payment_id );
			} else {
				console.log( 'Error code demo ' + code );
			}
		}

		window.addEventListener( 'message', handleMessage, false );

	}

);
