<?php
/**
 * WC oxygen payment gateway
 *
 * @package Oxygen Payments
 * @version 1.0.0
 * @author Oxygen
 * @since 1.0.0
 *
 * This file creates oxygen payment gateway and add it in available payments of wc
 * Construct function creates all necessary fields and process payment -- throws a modal with everypay form
 */

/**
 * Check for active plugins --- if wc is enabled then oxygen payments are available
 */

$active_plugins = (array) get_option( 'active_plugins', array() );
/* if woocommerce is disabled not run this code for payment */
if ( ! in_array( 'woocommerce/woocommerce.php', $active_plugins ) ) {
	return;
}

add_action( 'plugins_loaded', 'init_oxygen_payment_gateway', 11 );

/**
 * This function initialize payment way
 */
function init_oxygen_payment_gateway() {

	if ( class_exists( 'WC_Payment_Gateway' ) ) {

		/**
		 * This function adds the new payment in wc available gateways
		 */
		class WC_OxygenPayment_Gateway extends WC_Payment_Gateway {

			/**
			 * This function constructs the oxygen payment required fields and settings
			 * Let enable payments only if there are oxygen payments in admin's account in pelatologio app
			 * Throws messages for enable/disable and write status in debug.log file
			 */
			public function __construct() {

				$this->id          = 'oxygen_payment';
				$this->title       = $this->get_option( 'title' );
				$this->description = $this->get_option( 'description' );

				$this->icon               = apply_filters( 'woocommerce_payment_icon', plugins_url( '../assets/icon_payment.png', __FILE__ ) );
				$this->has_fields         = false;
				$this->method_title       = __( 'Oxygen Payments (Debit/Credit card)', 'oxygen-pay-woo' );
				$this->method_description = __( 'Activate Oxygen Payments plugin for online card payment', 'oxygen-pay-woo' );

				$this->init_form_fields();
				$this->init_settings();

				$oxygen_status = OxygenWooSettings::get_option( 'oxygen_status' );
				$api_key       = OxygenWooSettings::get_option( 'oxygen_api_key' );

				if ( ! empty( $api_key ) ) {

					if ( 'live' === $oxygen_status ) {
						$api_url = 'https://api.oxygen.gr/v1';
					} else {
                        $api_url = 'https://sandbox-api.oxygen.gr/v1';
//						$api_url = 'http://api.pelatologio.test/v1';

					}
					$endpoint = $api_url . '/oxygen-payments';

					$api_headers = array(
						'Authorization' => 'Bearer ' . $api_key,
						'Accept'        => 'application/json',
					);

					$options = array(
						'headers' => $api_headers,
					);

					$response = wp_remote_get( $endpoint, $options );

					if ( is_wp_error( $response ) ) {
						$error_message = $response->get_error_message();
						OxygenWooSettings::debug( 'Something get wrong with api request to pelatologio '.$error_message , 'warning' );
					} else {
						$data = $response['http_response'];

						if ( ! empty( $data ) ) {
							$response_obj = $data->get_response_object();
							$status       = $response_obj->status_code;

							if ( 200 !== $status ) {

								WC_Admin_Notices::add_custom_notice( 'oxygen_payments_saved',   sprintf(
									'<p>%s <a href="https://app.pelatologio.gr/settings_marketplace.php?m=500" target="_blank">%s</a></p>',
									__('Oxygen Payments are not activated. Sign up here to enable them.', 'text-domain'),
									__('Oxygen Payments', 'text-domain')
								));
								$this->enabled = 'no';
							} else {

								$this->enabled = $this->get_option( 'enabled' );

								add_action( 'admin_notices', 'display_oxygen_payment_notice_once' );
								/* adds message for enabled oxygen payments only ONCE */
							}
						}
					}
				}

				/*
				This get admin s options settings
				$options_payment = array( $this,'process_admin_options');
				$array_obj = (array) $options_payment[0];
				$settings_payment = (array) $array_obj["settings"];
				$enabled =$settings_payment["enabled"];
				*/

				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			}

			/**
			 * This function init oxygen payment required fields and settings on admin panel
			 */
			public function init_form_fields() {
				$this->form_fields = apply_filters(
					'woo_oxygen_pay_fields',
					array(
						'enabled'      => array(
							'title'   => __( 'Enabled/Disabled', 'oxygen-pay-woo' ),
							'type'    => 'checkbox',
							'label'   => __( 'Enable or Disable Oxygen Payment Gateway'),
							'default' => 'no',
						),
						'title'        => array(
							'title'       => __( 'Title', 'oxygen-pay-woo' ),
							'type'        => 'text',
							'description' => __( 'Add a new title for Oxygen Payments (Debit/Credit card)', 'oxygen-pay-woo'),
							'default'     => __( 'Oxygen Payments (Debit/Credit card)', 'oxygen-pay-woo'),
							'desc_tip'    => true,
						),
						'description'  => array(
							'title'       => __( 'Description', 'oxygen-pay-woo' ),
							'type'        => 'textarea',
							'css'         => 'width:500px;',
							'default'     => __('You will be taken to a secure payment environment. Stay until the transaction is complete.', 'oxygen-pay-woo'),
							'desc_tip'    => true,
							'description' => __( 'Add description for Oxygen Payment Gateway that customers will see when they are in checkout page.', 'oxygen-pay-woo'),
						),
						'instructions' => array(
							'title'       => __( 'Instructions', 'oxygen-pay-woo' ),
							'type'        => 'textarea',
							'css'         => 'width:500px;',
							'desc_tip'    => true,
							'description' => __( 'Instructions for how to pay via oxygen payments', 'oxygen-pay-woo'),
						),
					)
				);
			}

			/**
			 * This function process oxygen payment from the moment that make an order and press place order button
			 * at woo checkout page.
			 * Get order id,amount,customer data (process_customer_data)
			 * Make a request with post_to_oxygen_payment_api at pelatologio api in order to create a payment link
			 * Concat ?type=eshop variable to payment link in order to print the right everypay form in modal via pay file
			 * Set redirect url to thank you page session variable
			 * Build modal according to width window (desktop or mobile)
			 * Returns a message that include custom_payment.js file to handle on frontend payment
			 *
			 * @param Number $order_id this current is order id.
			 * @return Array|Null
			 */
			public function process_payment( $order_id ) {

				$order        = wc_get_order( $order_id );
				$amount       = $order->get_total();
				$order_number = $order->get_order_number();

				$customer_api_id = process_customer_data( $order );
				$response        = post_to_oxygen_payment_api( $order_id, $amount, $customer_api_id );

				OxygenWooSettings::debug( array('customer_api_id  '.$customer_api_id));

				if ( ! is_wp_error( $response ) ) {

					$data = $response['body'];
					OxygenWooSettings::debug( array('payment data  '.json_encode($data)));

					if ( ! empty( $data ) ) {

						$array_data   = json_decode( $data, true );

						if (isset($array_data['message'])) {
							$message = $array_data['message'];
							OxygenWooSettings::debug( array('Error from pelatologio api  '.$message) );
							return;
						}
						$payment_link = $array_data['url'];

						OxygenWooSettings::debug( array('payment link is '.$payment_link) );

						$payment_link = str_replace( '?form=eshop', '/', $payment_link );
						$payment_link = $payment_link . '?form=eshop';
						$payment_link = preg_replace( '/^http:/i', 'https:', $payment_link );

						if ( ! empty( $payment_link ) ) {

							WC()->session->set( 'payment_link', $payment_link );

							$checkout_url = $this->get_return_url( $order );
							$redirect_url = add_query_arg(
								array(
									'order-pay' => $order_id,
									'key'       => $order->get_order_key(),
								),
								$checkout_url
							);

							WC()->session->set( 'thankyou_link', $redirect_url );
							WC()->session->set( 'order_id', $order_id );

							$modal_payment = '<div class="my_modal" id="openOxyPayment" tabindex="-1" role="dialog" aria-labelledby="openOxyPaymentTitle" aria-hidden="true" style="display:none;z-index: 9999;">
                                                <div class="overlay_loader" style="display: none;">
                                                    <div class="overlay__inner">
                                                        <div class="overlay__content">
                                                            <span class="spinner"></span>
                                                            <div class="response_payment"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                                  <div class="my_modal-dialog" role="document">
                                                    <div class="my_modal-content">
                                                      <div class="my_modal-body">
                                                        <div class="close_payment"><img src="' . OXYGEN_PLUGIN_URL . 'assets/icon-delete-active.svg" /></div>
                                                        <div class="left_side_oxy_woo">
                                                        	<div style="padding:20px 64px;">
                                                                <div class="mainlogo" style="padding-bottom: 48px;">
                                                                    <a href="https://www.pelatologio.gr" target="_blank"><img src="' . OXYGEN_PLUGIN_URL . 'assets/logo-oxygen-payments-negative.svg"></a>
                                                                </div>
                                                            	<div style="font-weight:normal;font-size:14px;">'.__('Order\'s Number:', 'oxygen').'</div>
                                                                <div style="font-weight:bold;font-size:14px;padding-bottom:40px;box-shadow: inset 0 -1px 0 0 #fcfcfc;">' . $order_number . '</div>
                                                                <div style="font-weight:800;display:flex; justify-content:space-between;padding:40px 0;"">
                                                               		<p>'.__( 'Total' , 'oxygen').'</p>
                                                                   <p>' . $amount . '€</p>
                                                                </div>
                                                            </div>
                                                            <div class="payment_footer parent">
                                                                <div class="payment_footer">Powered by <a href="https://www.pelatologio.gr" target="_blank">
                                                                    <img src="' . OXYGEN_PLUGIN_URL . 'assets/logo-horizontal-negative.webp" style="margin-left:5px;max-width:80px!important;width: 80px!important;"/></a>
                                                                </div>
                                                                <div class="payment_footer">
                                                                    <a href="https://www.pelatologio.gr/page.php?id=71&sub1=4&sub2=70&lang=1" target="_blank"><p style="margin-right:10px;">Terms</p></a>
                                                                    <a href="https://www.pelatologio.gr/page.php?id=78&sub1=4&sub2=70&lang=1" target="_blank"><p>Privacy Policy</p></a>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <iframe id="iframe" src="' . $payment_link . '" width="100%" height="auto" style="min-height:608px;" allow="payment"></iframe>
                                                      </div>
                                                    </div>
                                                  </div>
                                                </div>
                                                <div class="full_cover_mobile" style="display:none;">
                                                    <div class="overlay_loader" style="display: none;">
                                                        <div class="overlay__inner">
                                                            <div class="overlay__content">
                                                                <span class="spinner"></span>
                                                                <div class="response_payment"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="mainlogo">
                                                        <a href="https://www.pelatologio.gr" target="_blank"><img src="' . OXYGEN_PLUGIN_URL . 'assets/logo-oxygen-payments-negative.svg"></a>
                                                        <div class="close_payment"><img src="' . OXYGEN_PLUGIN_URL . 'assets/icon-delete-active-white.svg" /></div>
                                                    </div>
                                                    <iframe id="iframe" allow="payment" src="' . $payment_link . '" width="100%" height="auto" style="min-height:800px;"></iframe>
                                                    <div class="left_side_oxy_woo">
                                                        <div class="first_left">
                                                            <div style="font-weight:normal;font-size:14px;">'.__('Order\'s Number:', 'oxygen').'</div>
                                                            <div style="font-weight:bold;font-size:14px;padding-bottom:40px;box-shadow: inset 0 -1px 0 0 #fcfcfc;">' . $order_number . '</div>
                                                            <div style="font-weight:800;display:flex; justify-content:space-between;padding:40px 0;">
                                                               <p>'.__( 'Total' , 'oxygen').'</p>
                                                               <p>' . $amount . '€</p>
                                                            </div>
                                                             <div class="payment_footer mob">
                                                                <div class="payment_footer">
                                                                    Powered by <a href="https://www.pelatologio.gr" target="_blank">
                                                                    <img alt="OXYEGN" src="' . OXYGEN_PLUGIN_URL . 'assets/logo-horizontal-negative.webp" style="margin-left:5px;width:80px; margin-top: 3px;"/></a>
                                                                </div>
                                                                <div class="payment_footer">
                                                                    <a href="https://www.pelatologio.gr/page.php?id=71&sub1=4&sub2=70&lang=1" target="_blank"><p style="margin-right:10px;">Terms</p></a>
                                                                    <a href="https://www.pelatologio.gr/page.php?id=78&sub1=4&sub2=70&lang=1" target="_blank"><p>Privacy Policy</p></a>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>';

							return array(
								'result'   => 'success',
								'messages' => '<script src=' . OXYGEN_PLUGIN_URL . 'js/bootstrap.min.js></script><script src=' . OXYGEN_PLUGIN_URL . 'js/custom_payment.js></script>' . $modal_payment,
							);

						} else {
							wc_add_notice( 'Not valid payment link', 'error' );
						}
					} else {
						wc_add_notice( 'Please try again.', 'error' );
						return;
					}
				} else {
					wc_add_notice( 'Connection error.', 'error' );
					return;
				}

				return array(
					'fail' => 'failure!',
				);
			}
		}
	}
}

add_filter( 'woocommerce_payment_gateways', 'add_to_woo_oxygen_payment_gateway' );

/**
 * This function adds oxygen payment to wc payments ways
 *
 * @param Array $gateways array of selected gateways.
 * @return Array
 */
function add_to_woo_oxygen_payment_gateway( $gateways ) {

	$gateways[] = 'WC_OxygenPayment_Gateway';
	return $gateways;
}

/**
 * This function return_api_url_by_status
 */
function return_api_url_by_status() {

	$oxygen_status = OxygenWooSettings::get_option( 'oxygen_status' );

	if ( 'live' === $oxygen_status ) {
		$api_url = 'https://api.oxygen.gr/v1';
	} else {
        $api_url = 'https://sandbox-api.oxygen.gr/v1';
//		$api_url = 'http://api.pelatologio.test/v1';

	}

	return $api_url;
}

/**
 * This function post_to_oxygen_payment_api
 *
 * @param Number $order_id order id.
 * @param Number $amount amount of order.
 * @param String $customer_api_id customer id connected with pelatologio app.
 * @return array|WP_Error
 */
function post_to_oxygen_payment_api( $order_id, $amount, string $customer_api_id ) {

	$api_key = OxygenWooSettings::get_option( 'oxygen_api_key' );
	$api_url = return_api_url_by_status();

	$endpoint     = $api_url . '/oxygen-payments';
	$expired_date = gmdate( 'Y-m-d', strtotime( 'tomorrow' ) );
	$order = wc_get_order( $order_id );
	$email = $order->get_billing_email();
	$store_name = get_bloginfo('name');

	$body = array(
		'form'        => 'eshop',
		'description' => 'Νέα πληρωμή No' . $order_id, /* add order id to request */
		'comments'    => 'Παραγγελία από eshop '.$store_name. ' - '.$email.' No '.$order_id,
		'amount'      => $amount,
		'expire_date' => $expired_date,
		'contact_id'  => $customer_api_id,
		'logo_id'     => ( ! empty( OxygenWooSettings::get_option( 'oxygen_logo' ) ) ? OxygenWooSettings::get_option( 'oxygen_logo' ) : OxygenWooSettings::get_default_logo_id() ),
		'language'    => 'el',
	);

	$api_headers = array(
		'Authorization' => 'Bearer ' . $api_key,
		'Accept'        => 'application/json',
	);

	$options = array(
		'headers' => $api_headers,
		'body'    => $body,
	);

	$response = wp_remote_post( $endpoint, $options );

	return $response;
}

/**
 * This function process_customer_data
 *
 * @param Number $order this is selected order.
 * @return String
 */
function process_customer_data( $order ): string
{
	$oxygen_customer_id = '';
	$is_invoice = get_post_meta($order->get_id(), '_billing_invoice', true);

	OxygenWooSettings::debug( array('process_customer_data '));

	if ($is_invoice === '1') {
		OxygenWooSettings::debug( array("------- you asked to create an invoice -------- ") );
		$get_billing_vat_info = OxygenOrder::get_billing_vat_info( $order->get_id() );
		OxygenWooSettings::debug( array('get billing vat info --' .json_encode($get_billing_vat_info)));

		$checkout_email = $order->get_billing_email();
		$checkout_vat = $get_billing_vat_info['billing_vat'];

		if( !empty($checkout_vat) && !empty($checkout_email)){

			$contact_by_vat = OxygenApi::get_contact_by_vat($checkout_vat);

			if( empty($contact_by_vat['data'])){

				$new_contact = OxygenOrder::create_new_contact($order, $get_billing_vat_info);
				$oxygen_customer_id = $new_contact['id'];

				OxygenWooSettings::debug( array('new customer id is ONE'));

			}else if($checkout_email !== $contact_by_vat['data'][0]['email'] && $checkout_vat !== $contact_by_vat['data'][0]['vat_number']) {
				/* otan to email sto checkout einai allo apo to email tou afm poy xrhsimopoieitai gia th ekdosh na dhmiourgei neo xrhsth */

				$new_contact = OxygenOrder::create_new_contact($order, $get_billing_vat_info);
				$oxygen_customer_id = $new_contact['id'];

				OxygenWooSettings::debug( array('------- email checkout !== email of vat NEW customer id is --------'));

			}else { /* contact vat data are filled AND checkout email same with vat email */

				$oxygen_customer_id =  $contact_by_vat['data'][0]['id'];
				OxygenWooSettings::debug( array('------- in else contact vat data are filled -------- ' . $oxygen_customer_id));
			}

		}
	} else {

		if ($is_invoice === '0') {
			OxygenWooSettings::debug(array("------- you asked to create an ALP OR APY -------- "));
			$get_billing_vat_info = OxygenOrder::get_billing_vat_info($order->get_id());
			OxygenWooSettings::debug(array('NOT INVOICE get billing vat info --' . json_encode($get_billing_vat_info)));

			$checkout_email = $order->get_billing_email();

			if (!empty($checkout_email)) {
				$contact_by_email = OxygenApi::get_contact_by_email($checkout_email);

				if (empty($contact_by_email['data'])) {
					$new_contact = OxygenOrder::create_new_contact($order, $get_billing_vat_info);
					$oxygen_customer_id = $new_contact['id'];
					OxygenWooSettings::debug(
						array("------- NOT INVOICE new customer id is -------- " . $oxygen_customer_id)
					);
				} else {
					$oxygen_customer_id = $contact_by_email['data'][0]['id'];
					OxygenWooSettings::debug(
						array("------- NOT INVOICE in else contact vat data are filled -------- " . $oxygen_customer_id)
					);
				}
			}else{

				$new_contact = OxygenOrder::create_new_contact($order, $get_billing_vat_info);
				$oxygen_customer_id = $new_contact['id'];
				OxygenWooSettings::debug( array("------- ALP OR APY OXYGEN PAYMETNS NEW CONTACT "));
			}
		}
	}
	return $oxygen_customer_id;

}



add_filter( 'woocommerce_gateway_title', 'add_icon_to_gateway_title', 20, 2 );

function add_icon_to_gateway_title( $title, $payment_id ) {

	// Check if we are on the WooCommerce order edit or WooCommerce payment settings page
	if ( is_admin()) {

		$screen = get_current_screen();

		if ( $screen && $screen->id === 'woocommerce_page_wc-settings' ) {
			if ('oxygen_payment' === $payment_id) {
				$icon_html = '<img src="' . OXYGEN_PLUGIN_URL . 'assets/banks/visa.svg" alt="Visa" style="width:38px;height: 24px;margin-right: 8px;border: 1px solid #ddd;border-radius: 3px;">
                <img src="' . OXYGEN_PLUGIN_URL . 'assets/banks/master.svg" alt="Mastercard" style="width:38px;height: 24px;margin-right: 8px;border: 1px solid #ddd;border-radius: 3px;">
                <img src="' . OXYGEN_PLUGIN_URL . 'assets/banks/apple_pay.svg" alt="Apple Pay" style="width:38px;height: 24px;margin-right: 8px;border: 1px solid #ddd;border-radius: 3px;">';
				$title = $icon_html;
			}
		}
	}
	return $title;
}


/**
 * Add order-pay variable in redirection url after payment
 *
 * @param Array $vars this is array of vars.
 * @return Array
 */
function my_add_query_vars( $vars ) {
	$vars[] = 'order-pay';
	return $vars;
}

add_filter( 'query_vars', 'my_add_query_vars' );

function display_oxygen_payment_notice_once() {
	// Check if the option exists, meaning the notice hasn't been displayed yet.
	if ( ! get_option( 'oxygen_payments_notice_shown' ) ) {

		// Add the custom notice.
		WC_Admin_Notices::add_custom_notice( 'oxygen_payments_saved', '<p id="oxygen_payments_enabled">Η επιλογή πληρωμής μέσω Oxygen Payments ενεργοποιήθηκε επιτυχώς.</p>' );

		// Set a flag in the database to prevent the notice from displaying again.
		update_option( 'oxygen_payments_notice_shown', true );
	}
}

/**
 * Payment oxygen load scripts css
 */
function payment_oxygen_load_scripts() {
	wp_enqueue_style( 'my_style_css', OXYGEN_PLUGIN_URL . 'assets/my_styles.css', array(), null );
}

add_action( 'wp_enqueue_scripts', 'payment_oxygen_load_scripts' );
