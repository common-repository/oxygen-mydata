<?php
/**
 * Summary. Creates all Oxygen API calls.
 *
 * @package Oxygen
 * OxygenApi Class File
 *
 * @version 1.0.0
 * @since  1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Oxygen MyData Class
 */
class OxygenApi {



	/**
	 * Singleton Instance of OxygenApi
	 *
	 * @var OxygenApi
	 **/
	private static $instance = null;

	/**
	 * API KEY string
	 *
	 * @var string
	 */
	private static $api_key = '';

	/**
	 * Oxygen API URL
	 *
	 * @var string URL
	 */
	public static $api_url = '';

	/**
	 * API authentication token variable
	 *
	 * @var string
	 */
	public static $auth_token = '';

	/**
	 * API Status option string live|test
	 *
	 * @var string
	 */
	public static $oxygen_status = '';

	/**
	 * API request headers
	 *
	 * @var array
	 */
	public static $api_headers = '';


	/**
	 * Singleton init Function.
	 *
	 * @static
	 */
	public static function init() {
		if ( ! self::$instance ) {
			self::$instance = new self();

		}

		self::$api_key       = OxygenWooSettings::get_option( 'oxygen_api_key' );
		self::$oxygen_status = OxygenWooSettings::get_option( 'oxygen_status' );

		if ( 'live' === self::$oxygen_status ) {

			self::$api_url = 'https://api.oxygen.gr/v1';

		} else {

			self::$api_url = 'https://sandbox-api.oxygen.gr/v1';
//            self::$api_url = 'http://api.pelatologio.test/v1';

        }

		self::$api_headers = array(
			'Authorization'    => 'Bearer ' . self::$api_key,
			'Accept'           => 'application/json',
			'X-Plugin-Version' => OXYGEN_PLUGIN_VERSION,
			'X-Plugin-Type'    => 'woocommerce',
		);

		return self::$instance;
	}

	/**
	 * OxygenApi Constructor.
	 */
	public function __construct() {

	}

	/**
	 *  Oxygen set new API KEY.
	 *
	 *  @param string $key API key to force.
	 *  @return void.
	 */
	public static function set_apikey( $key ) {
		self::$api_key = $key;
	}

	/**
	 *  Oxygen set API status.
	 *
	 *  @param string $status API key to force.
	 *  @return void.
	 */
	public static function set_status( $status ) {
		self::$oxygen_status = $status;
	}

	/**
	 *  Oxygen API contacts request.
	 *
	 *  @param int $page Page number of the request.
	 *  @return array|WP_Error The response or WP_Error on failure.
	 */
	public static function get_contacts( $page = 1 ) {

		$url  = self::$api_url . '/contacts';
		$args = array(
			'page'    => $page,
			'headers' => self::$api_headers,
		);

		$contacts = wp_remote_get( $url, $args );

		if ( is_array( $contacts ) && isset( $contacts['response'] ) && isset( $contacts['response']['code'] ) && 200 !== $contacts['response']['code'] ) {

			return $contacts['response'];
		}

		return $contacts;

	}

	/**
	 *  Oxygen API single contact by contact id.
	 *
	 *  @param string $contact_id Oxygen API contact ID string.
	 *  @return array The API response.
	 */
	public static function get_contact( $contact_id ) {

		$url  = self::$api_url . '/contacts/' . $contact_id;
		$args = array(
			'code'    => $contact_id,
			'headers' => self::$api_headers,
		);

		$result = wp_remote_get( $url, $args );

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return $result['response'];
		}

		return json_decode( $result['body'], true );

	}

	/**
	 *  Oxygen API single contact request by vat number.
	 *
	 *  @param string $vatnum Oxygen API vat number string.
	 *  @return array The API response.
	 */
	public static function get_contact_by_vat( $vatnum ) {

		$url  = self::$api_url . '/contacts?vat=' . $vatnum;
		$args = array(
			'headers' => self::$api_headers,
		);

		$result = wp_remote_request( $url, $args );

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return $result['response'];
		}

		$body = wp_remote_retrieve_body( $result );

		return json_decode( $body, true );

	}

	/**
	 *  Oxygen API single contact request by email.
	 *
	 *  @param string $email Oxygen API email string.
	 *  @return array|bool The API response.
	 */
	public static function get_contact_by_email( $email ) {

        if(empty($email)){

            OxygenWooSettings::debug( array("------- empty email in get_contact_by_email -------- " . $email));
            return false;
        }
		$url  = self::$api_url . '/contacts?email=' . $email;
		$args = array(
			'headers' => self::$api_headers,
		);

		$result = wp_remote_request( $url, $args );

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return $result['response'];
		}

		$body = wp_remote_retrieve_body( $result );

		return json_decode( $body, true );

	}

	/**
	 *  Oxygen API taxes request.
	 *
	 *  @param int $page Page number of the request.
	 *  @return array|WP_Error The response or WP_Error on failure.
	 */
	public static function get_taxes( $page = 1 ) {

		$url  = self::$api_url . '/taxes';
		$args = array(
			'page'    => $page,
			'headers' => self::$api_headers,
		);

		$taxes = wp_remote_get( $url, $args );

		if ( is_array( $taxes ) && isset( $taxes['response'] ) && isset( $taxes['response']['code'] ) && 200 !== $taxes['response']['code'] ) {

			return $taxes['response'];
		}

		return $taxes;

	}

	/**
	 *  Oxygen API single tax request.
	 *
	 *  @param string API $tax_id tax ID string.
	 *  @return array|WP_Error The response or WP_Error on failure.
	 */
	public static function get_tax( $tax_id ) {

		$url  = self::$api_url . '/taxes/' . $tax_id;
		$args = array(
			'tax_id'  => $tax_id,
			'headers' => self::$api_headers,
		);

		$result = wp_remote_get( $url, $args );

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return $result['response'];
		}

		return $result;

	}

	/**
	*  Oxygen API check vat.
	*
	*  @param string $vat_number
	*  @return array The API response.
	*/
	public static function do_vat_check( $vat_number ) {

		$log = array( '---------------- do greek vat check -------------',$vat_number );
		OxygenWooSettings::debug( $log );

		$url  = self::$api_url . '/vat-check/?vat=' . $vat_number;
		$args = array(
			'headers' => self::$api_headers,
		);

		$result = wp_remote_get( $url, $args );

		if ( is_a( $result, 'WP_Error' ) ) {
			$log = array( '---------------- do vat check -------------', $result['message'] );
			OxygenWooSettings::debug( $log );
			return $result;
		}

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return array('code' => $result['response']['code'],json_decode( $result['body'], true ));
		}

		return json_decode( $result['body'], true );

	}


	/**
	 *  Oxygen API check VIES.
	 *
	 *  @param string $vat_number
	 *  @param string $country_code
	 *  @return array The API response.
	 */
	public static function do_vies_check( $vat_number ,$country_code ) {

		$log = array( '---------------- do vies check -------------',$vat_number ,$country_code);
		OxygenWooSettings::debug( $log );

		$url  = self::$api_url . '/vies/?vat=' . $vat_number.'&country_code=' . $country_code;
		$args = array(
			'headers' => self::$api_headers,
		);

		$result = wp_remote_get( $url, $args );

		if ( is_a( $result, 'WP_Error' ) ) {
			$log = array( '---------------- do vies check -------------', $result['message'] );
			OxygenWooSettings::debug( $log );
			return $result;
		}

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return array('code' => $result['response']['code'],json_decode( $result['body'], true ));
		}

		return json_decode( $result['body'], true );

	}

	/**
	 *  Oxygen API add contact request.
	 *
	 *  @param array $contact_args API contact data.
	 *  @return array|WP_Error The response or WP_Error on failure.
	 */
	public static function add_contact( $contact_args ) {

        $log = array( '----------------- CONTACT ARGS ARE -----------------', $contact_args );
        OxygenWooSettings::debug( $log );

		$url  = self::$api_url . '/contacts';
		$args = array(
			'body'    => $contact_args,
			'headers' => self::$api_headers,
		);

		$result = wp_remote_post( $url, $args );

        $log = array( '----------------result of add_contact -------------', $contact_args,$result );
        OxygenWooSettings::debug( $log );

		if ( is_a( $result, 'WP_Error' ) ) {

			return $result;
		}

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return $result['body'];
		}

		return $result;

	}

	/**
	 *  Oxygen API add invoice request.
	 *
	 *  @param array $invoice_args API invoice data.
	 *  @return array|WP_Error The response or WP_Error on failure.
	 */
	public static function add_invoice( $invoice_args ) {

		$url  = self::$api_url . '/invoices';
		$args = array(
			'body'    => $invoice_args,
			'headers' => self::$api_headers,
		);

		$result = wp_remote_post( $url, $args );

        $log = array( '------------ result of add_invoice --------------', $args, $result );
        OxygenWooSettings::debug( $log, 'debug' );

		if ( is_a( $result, 'WP_Error' ) ) {

			return $result;
		}

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return $result['body'];
		}

		return $result;

	}

	/**
	 *  Oxygen API add notice request.
	 *
	 *  @param array $invoice_args API notice data.
	 *  @return array|WP_Error|false The response or WP_Error on failure.
	 */
	public static function add_notice( $invoice_args ) {

		$url  = self::$api_url . '/notices';
		$args = array(
			'body'    => $invoice_args,
			'headers' => self::$api_headers,
		);

		$result = wp_remote_post( $url, $args );

        $log = array( '------------ result of add_notice --------------', $args, $result );
        OxygenWooSettings::debug( $log, 'debug' );

		if ( is_a( $result, 'WP_Error' ) ) {

			return $result;
		}

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && ! ( 200 === $result['response']['code'] || 201 === $result['response']['code'] ) ) {

			return $result['body'];
		}

		return $result;

	}

	/**
	 *  Oxygen API invoices request.
	 *
	 *  @param int $page Page number of the request.
	 *  @return array|WP_Error The response or WP_Error on failure.
	 */
	public static function get_invoices( $page = 1 ) {

		$url  = self::$api_url . '/invoices';
		$args = array(
			'page'    => $page,
			'headers' => self::$api_headers,
		);

		$result = wp_remote_get( $url, $args );

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && ! ( 200 === $result['response']['code'] || 201 === $result['response']['code'] ) ) {

			return $result['response'];
		}

		return $result;

	}

	/**
	 *  Oxygen API single invoice request.
	 *
	 *  @param string $invoice_id API invoice ID.
	 *  @return array|WP_Error The response or WP_Error on failure.
	 */
	public static function get_invoice( $invoice_id ) {

		$url  = self::$api_url . '/invoices/' . $invoice_id;
		$args = array(
			'invoice_id' => $invoice_id,
			'headers'    => array(
				'Authorization' => 'Bearer ' . self::$api_key,
				'Accept'        => 'application/json',
			),
		);

		$result = wp_remote_get( $url, $args );

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return $result['response'];
		}

		return $result;

	}

	/**
	 *  Oxygen API invocie PDF file request.
	 *
	 *  @param string $invoice_id API invoice ID.
	 *  @param string $print_type 80mm or a4 or 0 for empty
	 *
	 *  @return array|WP_Error The response or WP_Error on failure.
	 */
	public static function get_invoice_pdf( $invoice_id, $print_type) {

		OxygenWooSettings::debug( array( '-------------- print type  settings is  ------------', $print_type) );

		$template = 'a4';
		if( !empty($print_type) && $print_type !== '0' ) {
			$template  = $print_type;
			OxygenWooSettings::debug( array( '-------------- template settings is  ------------', $template) );
		}

		$url  = self::$api_url . '/invoices/' . $invoice_id . '/pdf?template=' . urlencode($template);

		$args = array(
			'invoice_id' => $invoice_id,
			'headers'    => array(
				'Authorization' => 'Bearer ' . self::$api_key,
				'Accept'        => 'application/json',
			),
		);

		OxygenWooSettings::debug( array( '-------------- get_invoice_pdf - args of invoice pdf ------------', $args ,$url) );

		$result = wp_remote_get( $url, $args );


		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return $result['response'];
		}

		return $result;

	}

	/**
	 *  Oxygen API numbering sequences request.
	 *
	 *  @return array|WP_Error The response or WP_Error on failure.
	 */
	public static function get_sequences() {

		$url  = self::$api_url . '/numbering-sequences';
		$args = array(
			'headers' => self::$api_headers,
		);

		$result = wp_remote_get( $url, $args );

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return $result['response'];
		}

		return $result;

	}

	/**
	 *  Oxygen API single numbering sequence request.
	 *
	 *  @param int $sequence_id API sequence ID value.
	 *  @return array|WP_Error The response or WP_Error on failure.
	 */
	public static function get_sequence( $sequence_id ) {

		$url  = self::$api_url . '/numbering-sequences/' . $sequence_id;
		$args = array(
			'numbering_sequence_id' => $sequence_id,
			'headers'               => array(
				'Authorization' => 'Bearer ' . self::$api_key,
				'Accept'        => 'application/json',
			),
		);

		$result = wp_remote_get( $url, $args );

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return $result['response'];
		}

		return $result;

	}

	/**
	 *  Oxygen API logos request.
	 *
	 *  @return array|faslse|WP_Error The response false or or WP_Error on failure.
	 */
	public static function get_logos() {

		$url  = self::$api_url . '/logos';
		$args = array(
			'headers' => self::$api_headers,
		);

		$response = wp_remote_get( $url, $args );

		if ( is_array( $response ) && isset( $response['response'] ) && isset( $response['response']['code'] ) && 200 !== $response['response']['code'] ) {

			return $response['response'];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return ( isset( $body['data'] ) ? $body['data'] : false );

	}

	/**
	 *  Oxygen API single logo by logo id.
	 *
	 *  @param string $id Oxygen API logo ID string.
	 *  @return array The API response.
	 */
	public static function get_logo( $id ) {

		$url  = self::$api_url . '/logos/' . $id;
		$args = array(
			'logo_id' => $id,
			'headers' => self::$api_headers,
		);

		$result = wp_remote_get( $url, $args );

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return $result['response'];
		}

		return json_decode( $result['body'], true );

	}

	/**
	 *  Oxygen API payment methods request.
	 *
	 *  @return array|WP_Error The response or WP_Error on failure.
	 */
	public static function get_payment_methods() {

		$url  = self::$api_url . '/payment-methods';
		$args = array(
			'headers' => self::$api_headers,
		);

		$result = wp_remote_get( $url, $args );

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return $result['response'];
		}

		return $result;

	}

	/**
	 *  Oxygen API single payment method request.
	 *
	 *  @param int $payment_method_id API payment method ID value.
	 *  @return array|WP_Error The response or WP_Error on failure.
	 */
	public static function get_payment_method( $payment_method_id ) {

//		$url  = self::$api_url . '/payment-methods/' . $sequence_id;
        $url  = self::$api_url . '/payment-methods/' . $payment_method_id;
		$args = array(
			'payment_method_id' => $payment_method_id,
			'headers'           => array(
				'Authorization' => 'Bearer ' . self::$api_key,
				'Accept'        => 'application/json',
			),
		);

		$result = wp_remote_get( $url, $args );

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && 200 !== $result['response']['code'] ) {

			return $result['response'];
		}

		return $result;

	}

	/**
	 *  Oxygen API create payment.
	 *
	 *  @param array $payment_info API payment details value.
	 *  @return HTTP response | payment iFrame
	 */
	public static function create_payment( $payment_info ) {

		$url  = self::$api_url . '/oxygen-payments';
		$args = array(
			'body'    => $payment_info,
			'headers' => self::$api_headers,
		);

		$result = wp_remote_post( $url, $args );

		if ( is_a( $result, 'WP_Error' ) ) {

			return $result;
		}

		if ( is_array( $result ) && isset( $result['response'] ) && isset( $result['response']['code'] ) && ! ( 200 === $result['response']['code'] || 201 === $result['response']['code'] ) ) {

			$message = json_decode( $result['body'], true );

			if ( isset( $message['message'] ) ) {

				return $result['response']['code'] . "\n" . $message['message'];
			}

			return $result['body'];
		}

		if ( isset( $result['body'] ) ) {
			return json_decode( $result['body'], true );
		}

		return $result;
	}

	/**
	 *  Oxygen API ping and API key validation request.
	 *
	 *  @return array|WP_Error|false The response or WP_Error on failure.
	 */
	public static function check_connection() {

		$url  = self::$api_url . '/numbering-sequences';
		$args = array(
			'body'    => array(),
			'headers' => self::$api_headers,
		);

		$request = wp_remote_get( $url, $args );

		if ( is_array( $request ) && isset( $request['response'] ) ) {

			return $request['response'];
		}

		return false;

	}
}
