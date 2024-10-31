<?php
/**
 * Request Handle Message for Oxygen Payment
 *
 * @package Oxygen Payments
 * @version 1.0.0
 * @author Oxygen
 * @since 1.0.0
 *
 * This file handle oxygen payment response and returns a response status
 */

/**
 * Load wp
 */
require '../../../../wp-load.php';

$nonce = wp_create_nonce( 'payment_id' );


if ( isset( $_POST['payment_id'] ) && wp_verify_nonce( $nonce, 'payment_id' ) ) {
	$payment_id = sanitize_key( $_POST['payment_id'] );
} else {
	$response = array( 'status' => 'given payment id is null' );
	echo wp_json_encode( $response );
}

if ( ! empty( $payment_id ) ) {

	$api_key = OxygenWooSettings::get_option( 'oxygen_api_key' );
    $selected_status  = OxygenWooSettings::get_option( 'oxygen_payment_order_status', 'wc-processing' );
    $api_url = return_api_url_by_status();

	$endpoint = $api_url . '/oxygen-payments/' . $payment_id;

	$api_headers = array(
		'Authorization' => 'Bearer ' . $api_key,
		'Accept'        => 'application/json',

	);

	$options = array(
		'headers'   => $api_headers,
		'sslverify' => false,
	);

	$response_from_api = wp_remote_get( $endpoint, $options );

	if ( ! is_wp_error( $response_from_api ) ) {
		$data = $response_from_api['body'];

		if ( ! empty( $data ) ) {
			/* I NEED TO ADD HERE GET REQUEST FOR GETTING THE RIGHT PAYMENT LINK AND THEN MAKE PAYMENT COMPLETE */
			$array_data = json_decode( $data, true );
			$is_paid    = $array_data['is_paid'];
			if ( $is_paid ) {

				$order_id = WC()->session->get( 'order_id' );
				$my_order = wc_get_order( $order_id );
				$my_order->payment_complete();
				$my_order->update_status( 'wc-processing' );

                if(!empty($selected_status)){
                    $my_order->update_status( $selected_status);
                }

				$thankyou = WC()->session->get( 'thankyou_link' );

				$response = array(
					'status'   => 'is_paid',
					'redirect' => $thankyou,
				);
				echo wp_json_encode( $response );

			} else {
				$response = array( 'status' => 'not_paid' );
				echo wp_json_encode( $response );
			}
		} else {
			$response = array( 'status' => 'null_data' );
			echo wp_json_encode( $response );

		}
	} else {
		$error_message = $response_from_api->get_error_message();
		$response      = array(
			'status'        => 'error',
			'error_message' => $error_message,
		);
		echo wp_json_encode( $response );
	}
} else {
	echo 'empty payment id';
}
