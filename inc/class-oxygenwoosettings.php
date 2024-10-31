<?php
/**
 * Oxygen MyData Class File
 *
 * @package Oxygen
 * @summary Class to add WooCommerce settings tab and fields, WooCOmmerce categories fields
 * @version 1.0.0
 * @since  1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Oxygen Woo Settings Class
 */
class OxygenWooSettings {


	/**
	 * Singleton Instance of Oxygen Woo Settings
	 *
	 * @var OxygenWooSettings
	 **/
	private static $instance = null;

	/**
	 * Debug settings
	 *
	 * @var int 0|1
	 **/
	private static $debug = 0;


	/**
	 * Singleton init Function
	 *
	 * @static
	 */
	public static function init() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * OxygenWooSettings Constructor
	 */
	private function __construct() {

		self::$debug = get_option( 'oxygen_debug', 0 );

		add_action( 'woocommerce_update_options_oxygen', __CLASS__ . '::update_settings', 1 );
		add_filter( 'woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50 );
		add_action( 'woocommerce_settings_tabs_oxygen', __CLASS__ . '::settings_tab' );
		add_action( 'woocommerce_admin_field_html', __CLASS__ . '::html_field', 10, 1 );

		add_action( 'product_cat_add_form_fields', __CLASS__ . '::add_term_fields' );
		add_action( 'product_cat_edit_form_fields', __CLASS__ . '::edit_term_fields', 10, 2 );
		add_action( 'created_product_cat', __CLASS__ . '::save_term_fields' );
		add_action( 'edited_product_cat', __CLASS__ . '::save_term_fields' );

		add_action( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_data_tabs' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'product_data_panels' ) );

		add_action( 'woocommerce_new_product', array( __CLASS__, 'product_save' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'product_save' ), 10, 1 );

        add_action('admin_init', array( __CLASS__,'download_oxygen_settings'), 10, 1 );
        add_action('wp_ajax_download_wc_log', [$this, 'download_wc_log']);

	}

	/**
	 * Add a new settings tab to the WooCommerce settings tabs array.
	 *
	 * @param array $settings_tabs Array of WooCommerce setting tabs & their labels, excluding the Subscription tab.
	 * @return array $settings_tabs Array of WooCommerce setting tabs & their labels, including the Subscription tab.
	 */
	public static function add_settings_tab( $settings_tabs ) {

		$settings_tabs['oxygen'] = __( 'Oxygen', 'oxygen' );
		return $settings_tabs;

	}

	/**
	 * Uses the WooCommerce admin fields API to output settings via the @see woocommerce_admin_fields() function.
	 *
	 * @uses woocommerce_admin_fields()
	 * @uses self::get_settings()
	 */
	public static function settings_tab() {

		OxygenApi::init();

		$check = OxygenApi::check_connection();

		// if connection can not be made.

		if ( ! $check || ( is_array( $check ) && isset( $check['code'] ) && 401 === $check['code'] ) ) {
			WC_Admin_Settings::add_error( esc_html__( 'Could not connect to Oxygen.', 'oxygen' ) );
			WC_Admin_Settings::add_error( sanitize_text_field( $check['message'] ) );
		} elseif ( is_array( $check ) && isset( $check['code'] ) && 200 === $check['code'] && 'OK' === $check['message'] ) {
			WC_Admin_Settings::add_message( esc_html__( 'Connected to Oxygen', 'oxygen' ) );
		}
		$settings = self::get_settings();

		$api_checks = self::api_checks();

		if ( false !== $api_checks ) {

			foreach ( $api_checks as $error ) {

				WC_Admin_Settings::add_error( sanitize_text_field( $error ) );

			}
		}

		WC_Admin_Settings::show_messages();

		woocommerce_admin_fields( $settings );
		?>
		<style>
		.alert {
			display: inline-block;
			padding: 3px 6px;
			border-radius: 4px;
		}
		.danger {
			background-color:var(--wc-red);
			color:var(--wc-highligh-text);
		}
		.success {
			background-color:var(--wc-highlight);
			color:var(--wc-highligh-text);
		}
		</style>
		<?php
	}

	/**
	 * Uses the WooCommerce options API to save settings via the @see woocommerce_update_options() function.
	 *
	 * @uses woocommerce_update_options()
	 * @uses self::get_settings()
	 */
	public static function update_settings() {
		woocommerce_update_options( self::get_settings() );
	}

	/**
	 * Get all the settings for this plugin for @see woocommerce_admin_fields() function.
	 *
	 * @return array Array of settings for @see woocommerce_admin_fields() function.
	 */
	public static function get_settings() {

		// user_roles.
		$args  = array(
			'role__in'    => array( 'Administrator', 'shop_manager' ),
			'orderby'     => 'user_nicename',
			'order'       => 'ASC',
			'count_total' => false,
		);
		$users = get_users( $args );

		$default_users = array();

		foreach ( $users as $user ) {
			$default_users[ $user->ID ] = $user->display_name;
		}

		// tax rates.
		$all_tax_rates    = self::woo_tax_rates();
		$oxygen_taxes     = self::oxygen_tax_options();
		$oxygen_payments  = self::oxygen_payment_methods();
		$oxygen_sequences = self::oxygen_sequences_options();
		$user_meta_keys   = self::get_user_meta_keys();
		$get_logos        = self::get_logos();
		$default_logo_id  = self::get_default_logo_id();

		$oxygen_document_types      = self::document_types();
		$oxygen_document_type_names = self::document_type_names();

		$available_payment_methods = WC()->payment_gateways->payment_gateways();

		$settings                  = array();
		$settings['section_title'] = array(
			'name' => __( 'Oxygen Settings', 'oxygen' ),
			'type' => 'title',
			'desc' => __( 'The following options are used to configure the Oxygen Pelatologio', 'oxygen' ),
			'id'   => 'oxygen',
		);

		$settings['oxygen_api_key'] = array(
			'name'     => __( 'Oxygen API Key', 'oxygen' ),
			'desc_tip' => __( 'API key provided by Oxygen Pelatologio', 'oxygen' ),
			'id'       => 'oxygen_api_key',
			'type'     => 'text',
			'desc'     => __( 'API key provided by <a href="https://www.pelatologio.gr/" target="_blank" title="Oxygen Pelatologio">Oxygen Pelatologio</a>', 'oxygen' ),
		);

		$settings['oxygen_status'] = array(
			'name'    => __( 'Enviroment', 'oxygen' ),
			'id'      => 'oxygen_status',
			'type'    => 'select',
			'options' => array(
				'live' => __( 'Live', 'oxygen' ),
				'test' => __( 'Sandbox', 'oxygen' ),
			),

		);
		if ( 'test' === get_option( 'oxygen_status' ) ) {

			$settings['oxygen_status']['desc'] = __( '<span class="alert danger">You are on SANDBOX mode</span>', 'oxygen' );

		} elseif ( 'live' === get_option( 'oxygen_status' ) ) {

			$settings['oxygen_status']['desc'] = __( '<span class="alert success">You are on LIVE mode</span>', 'oxygen' );

		}
		$settings['mydata_category']                    = array(
			'name'    => __( 'Default myData Category', 'oxygen' ),
			'id'      => 'mydata_category',
			'type'    => 'select',
			'options' => self::mydata_classification_categories(),
		);
		$settings['mydata_classification_type']         = array(
			'name'    => __( 'Default myData Classification Type', 'oxygen' ),
			'id'      => 'mydata_classification_type',
			'type'    => 'select',
			'options' => self::mydata_classification_types(),
		);
		$settings['mydata_category_receipt']            = array(
			'name'    => __( 'Default myData Category Receipt', 'oxygen' ),
			'id'      => 'mydata_category_receipt',
			'type'    => 'select',
			'options' => self::mydata_classification_categories(),
		);
		$settings['mydata_classification_type_receipt'] = array(
			'name'    => __( 'Default myData Classification Type Receipt', 'oxygen' ),
			'id'      => 'mydata_classification_type_receipt',
			'type'    => 'select',
			'options' => self::mydata_classification_types(),
		);

		/*
		$settings['oxygen_default_customer']      = array(
			'name'    => __( 'Default Customer', 'oxygen' ),
			'id'      => 'oxygen_default_customer',
			'type'    => 'select',
			'options' => $default_users,
		);
		*/
		$settings['oxygen_vat_metakey']           = array(
			'name'    => __( 'Customer VAT/TIN number Key', 'oxygen' ),
			'id'      => 'oxygen_vat_metakey',
			'type'    => 'select',
			'options' => array( '0' => '' ) + $user_meta_keys,
		);
		$settings['oxygen_working_field_metakey'] = array(
			'name'    => __( 'Customer Job Description Field Key', 'oxygen' ),
			'id'      => 'oxygen_working_field_metakey',
			'type'    => 'select',
			'options' => array( '0' => '' ) + $user_meta_keys,
		);
		$settings['oxygen_tax_office']            = array(
			'name'    => __( 'Customer Tax Office Field Key', 'oxygen' ),
			'id'      => 'oxygen_tax_office',
			'type'    => 'select',
			'options' => array( '0' => '' ) + $user_meta_keys,
		);
		$settings['oxygen_issue_invoice_metakey'] = array(
			'name'    => __( 'Auto-issue an invoice or notice when this field is checked', 'oxygen' ),
			'id'      => 'oxygen_issue_invoice_metakey',
			'type'    => 'select',
			'options' => array( '0' => '' ) + $user_meta_keys,
		);
		$settings['oxygen_default_document_type'] = array(
			'name'    => __( 'Default document type', 'oxygen' ),
			'id'      => 'oxygen_default_document_type',
			'type'    => 'select',
			'options' => array(
				'0'       => '',
				'invoice' => __( 'Invoice / Receipt', 'oxygen' ),
				'notice'  => __( 'Notice', 'oxygen' ),
			),
		);

		$settings['oxygen_logo'] = array(
			'name'    => __( 'Documents logo', 'oxygen' ),
			'id'      => 'pxygen_logo',
			'type'    => 'select',
			'options' => $get_logos,
			'default' => $default_logo_id,
		);

		$settings['oxygen_self_fields'] = array(
			'name'     => __( 'Create the checkout fields for me', 'oxygen' ),
			'desc_tip' => __( 'The Oxygen plugin will create the fields for the customer VAT/TIN number and Job Description Field for you', 'oxygen' ),
			'id'       => 'oxygen_self_fields',
			'type'     => 'checkbox',
		);

		$settings['oxygen_fetch_vat_fields'] = array(
			'name'     => __( 'Fetch invoice data via vat number (VIES) on checkout page', 'oxygen' ),
			'desc_tip' => __( 'The Oxygen plugin will search through TAXISNET for Greek vat numbers or through VIES for intra-EU vat numbers and fill in the necessary fields for pricing on the checkout page', 'oxygen' ),
			'id'       => 'oxygen_fetch_vat_fields',
			'type'     => 'checkbox',
		);

		$settings['oxygen_print_type_header'] = array(
			'name' => '',
			'id'   => 'oxygen_print_type_header',
			'type' => 'html',
			'html' => '<h4>' . __( 'Oxygen print type for receipts', 'oxygen' ) . '</h4>',
		);

		$settings['oxygen_receipt_print_type'] = array(
			'name'    => __( 'Select print type for receipts', 'oxygen' ),
			'id'      => 'oxygen_receipt_print_type',
			'type'    => 'select',
			'options' => array( '0' => __('Select print type (optional)', 'oxygen') , 'a4' => 'A4' ,'80mm' => __('80mm ( for thermal printer)', 'oxygen') ),
			'desc_tip' => __( 'Select 80mm if you want to print your receipts exclusive on 80mm (thermal printer)', 'oxygen' ),
		);

		$settings['oxygen_default_doctype_header'] = array(
			'name' => '',
			'id'   => 'oxygen_default_doctype_header',
			'type' => 'html',
			'html' => '<h4>' . __( 'Default Document Type', 'oxygen' ) . '</h4>',
		);

		$settings['oxygen_default_receipt_doctype'] = array(
			'name'    => __( 'Default Receipt Document Type', 'oxygen' ),
			'id'      => 'oxygen_default_receipt_doctype',
			'type'    => 'select',
			'options' => array( '0' => '' ) + $oxygen_document_type_names,
			'desc'    => __( 'Type of receipt document to create when the below order status is met', 'oxygen' ),
		);

		$settings['oxygen_default_invoice_doctype'] = array(
			'name'    => __( 'Default Invoice Document Type', 'oxygen' ),
			'id'      => 'oxygen_default_invoice_doctype',
			'type'    => 'select',
			'options' => array( '0' => '' ) + $oxygen_document_type_names,
			'desc'    => __( 'Type of invoice document to create when the below order status is met', 'oxygen' ),
		);

		$settings['oxygen_shipping_code'] = array(
			'name' => __( 'Document shipping code', 'oxygen' ),
			'id'   => 'oxygen_shipping_code',
			'type' => 'text',
			'desc' => __( 'Please use latin letters and numbers only. Do NOT use spaces and special characters.', 'oxygen' ),
		);

		$settings['oxygen_order_status'] = array(
			'name'    => __( 'Default Order Status', 'oxygen' ),
			'id'      => 'oxygen_order_status',
			'type'    => 'select',
			'options' => array( '0' => __( 'Do not autocreate', 'oxygen' ) ) + wc_get_order_statuses(),
			'desc'    => __( 'Order status to autocreate the invoice', 'oxygen' ),
		);

		$settings['oxygen_order_attachment'] = array(
			'name' => __( 'Attach Invoice/Receipt on order email for the above order status', 'oxygen' ),
			'id'   => 'oxygen_order_attachment',
			'type' => 'checkbox',
			'desc' => __( 'The document will be auto attached on the order email to the customer', 'oxygen' ),
		);

		$settings['oxygen_language'] = array(
			'name'    => __( 'Invoice/Receipt Language', 'oxygen' ),
			'id'      => 'oxygen_language',
			'type'    => 'select',
			'options' => array(
				'EL' => __( 'Greek', 'oxygen' ),
				'EN' => __( 'English', 'oxygen' ),
                'order_lang' => __( 'Use order\'s language', 'oxygen' ),
			),
			'desc'    => __( 'Select the language of your invoices or receipts.', 'oxygen' ),
		);

		$settings['oxygen_is_paid'] = array(
			'name'    => __( 'Invoice/Receipt Paid Status', 'oxygen' ),
			'id'      => 'oxygen_is_paid',
			'type'    => 'select',
			'options' => array(
				'yes' => __( 'Paid', 'oxygen' ),
				'no'  => __( 'Unpaid', 'oxygen' ),
			),
			'desc'    => __( 'Select the default payment status (paid or not paid).', 'oxygen' ),
		);

		$settings['oxygen_num_sequences_header'] = array(
			'name' => '',
			'id'   => 'oxygen_num_sequences_header',
			'type' => 'html',
			'html' => '<h4>' . __( 'Numbering Sequences (Series)', 'oxygen' ) . '</h4>',
		);

		if ( ! empty( $oxygen_sequences ) ) {

			foreach ( $oxygen_document_types as $doc_key => $doc_type ) {

				$settings[ 'oxygen_num_sequence' . $doc_key ] = array(
					'name'    => $oxygen_document_type_names[ $doc_key ],
					'id'      => 'oxygen_num_sequence' . $doc_key,
					'type'    => 'select',
					'options' => ( isset( $oxygen_sequences[ $doc_type ] ) ? array( '0' => '' ) + $oxygen_sequences[ $doc_type ] : array() ),
				);
			}
		}

		$settings['oxygen_taxes_header'] = array(
			'name' => '',
			'id'   => 'oxygen_taxes_header',
			'type' => 'html',
			'html' => '<h4>' . __( 'Taxes Configuration', 'oxygen' ) . '</h4>',
		);

		if ( ! empty( $all_tax_rates ) ) {

			foreach ( $all_tax_rates as $tax_rate ) {

				$settings[ 'oxygen_taxes[' . $tax_rate->tax_rate_id . ']' ] = array(
					'name'    => $tax_rate->tax_rate_name . ' ' . round( $tax_rate->tax_rate, 2 ),
					'id'      => 'oxygen_taxes[' . $tax_rate->tax_rate_id . ']',
					'type'    => 'select',
					'options' => $oxygen_taxes,
				);

			}
		}

		$settings['oxygen_payment_methods_header'] = array(
			'name' => '',
			'id'   => 'oxygen_payment_methods_header',
			'type' => 'html',
			'html' => '<h4>' . __( 'Payment Methods', 'oxygen' ) . '</h4>',
		);

		if ( ! empty( $available_payment_methods ) ) {

			foreach ( $available_payment_methods as $key => $payment_method ) {

                if($payment_method->enabled == 'yes') {
                    $settings['oxygen_payment_methods[' . $key . ']'] = array(
                        'name' => $payment_method->title,
                        'id' => 'oxygen_payment_methods[' . $key . ']',
                        'type' => 'select',
                        'options' => $oxygen_payments,
                    );
                }

			}
		}

        /* an ta oxygen payments einai enabled tote emfanise ta parakatw settings */
        if ( ! empty( $available_payment_methods ) && $available_payment_methods['oxygen_payment']->enabled == 'yes' ) {

            $settings['oxygen_payment_order_status_header'] = array(
                'name' => '',
                'id' => 'oxygen_payment_order_status_header',
                'type' => 'html',
                'html' => '<h4>' . __('Oxygen Payments - Order Status', 'oxygen') . '</h4>',
            );

            /* Κατάσταση παραγγελίας μετά την επιτυχημένη πληρωμή μέσω Oxygen Payments --- default -> σε επεξεργασία */

            $settings['oxygen_payment_order_status'] = array(
                'name' => __('Default Oxygen Payment order status', 'oxygen'),
                'id' => 'oxygen_payment_order_status',
                'type' => 'select',
                'options' => wc_get_order_statuses(),
                'default' => 'wc-processing',
                'desc' => __('Order status after the successful payment with Oxygen Payments', 'oxygen'),
            );
        }

		$settings['oxygen_dev_header'] = array(
			'name' => '',
			'id'   => 'oxygen_dev_header',
			'type' => 'html',
			'html' => '<h4>' . __( 'Developer Settings', 'oxygen' ) . '</h4>',
		);

		$settings['oxygen_debug'] = array(
			'name'    => __( 'Enable oxygen debug', 'oxygen' ),
			'id'      => 'oxygen_debug',
			'type'    => 'select',
			'options' => array(
				'0' => esc_attr( 'no' ),
				'1' => esc_attr( 'yes' ),
			),
			'desc'    => __( 'This will add WooCommerce logs for Oxygen plugin actions', 'oxygen' ),
		);

        $settings['section_end'] = array(
            'type' => 'sectionend',
            'id'   => 'wc_oxygen_section_end',
        );

        /* button for export plugins settings in json */
        $download_url = add_query_arg('download_oxygen_settings', 'true', admin_url('admin.php?page=wc-settings&tab=oxygen'));

        // Add the button to the settings array
        $settings['oxygen_settings_export'] = array(
            'name' => __( 'Download Oxygen Settings', 'oxygen' ),
            'type' => 'title',
            'desc' => '<a href="' . esc_url($download_url) . '" class="button button-primary">' . __( 'Download Oxygen Settings', 'oxygen' ) . '</a>',
            'id'   => 'oxygen_download_button',
        );
        /* end ---button for export plugins settings in json */

        /* button to download oxygen latest log file from wc-logs */
        $most_recent_file = self::find_most_recent_oxygen_log();

        // Check if a file was found
        if ($most_recent_file) {
            // Create the download URL
            $download_url = add_query_arg('file', basename($most_recent_file), admin_url('admin-ajax.php?action=download_wc_log'));

            // Add a custom button in the settings
            $settings['oxygen_log_file_export'] = array(
                'name' => __( 'Download Oxygen Log File', 'oxygen' ),
                'type' => 'title', // Use title to add custom HTML
                'desc' => '<a href="' . esc_url($download_url) . '" class="button button-primary">' . __( 'Download Most Recent Oxygen Log', 'oxygen' ) . '</a>',
                'id'   => 'oxygen_log_download_button',
            );
        } else {
            // If no file is found, display a message
            $settings['oxygen_log_file_export'] = array(
                'name' => __( 'Download Oxygen Log File', 'oxygen' ),
                'type' => 'title',
                'desc' => '<p>' . __( 'No Oxygen log file found for today.', 'oxygen' ) . '</p>',
                'id'   => 'oxygen_log_download_button',
            );
        }
        /* button to download oxygen latest log file from wc-logs */


		return apply_filters( 'oxygen_tab_settings', $settings );
	}

	/**
	 *  Get_option wrapper
	 *
	 *  @param string $option meta key.
	 *  @return option value
	 */
	public static function get_option( $option ) {

		return WC_Admin_Settings::get_option( $option );

	}

	/**
	 *  Output HTML on WooCommerce settings
	 *
	 *  @param array $value WooCommerce settings fields array.
	 *  @return void
	 */
	public static function html_field( $value ) {

		?>
		<tr valign="top">
			<th class="forminp forminp-html" id="<?php echo esc_attr( $value['id'] ); ?>">
				<label><?php echo esc_attr( $value['title'] ); ?>
					<?php echo wp_kses_post( isset( $value['desc_tip'] ) && ! empty( $value['desc_tip'] ) ? wc_help_tip( sanitize_text_field( $value['desc_tip'] ) ) : '' ); ?>
				</label>
			</th>
			<td class="forminp"><?php echo wp_kses_post( $value['html'] ); ?></td>
		</tr>
		<?php

	}

	/**
	 *  Get all WooCommerce Tax Rates array.
	 *
	 *  @return array Taxes data
	 */
	public static function woo_tax_rates() {

		$all_tax_rates = array();

		$tax_classes = WC_Tax::get_tax_classes(); // Retrieve all tax classes.
		if ( ! in_array( '', $tax_classes ) ) { // Make sure "Standard rate" (empty class name) is present.
			array_unshift( $tax_classes, '' );
		}
		foreach ( $tax_classes as $tax_class ) { // For each tax class, get all rates.
			$taxes         = WC_Tax::get_rates_for_tax_class( $tax_class );
			$all_tax_rates = array_merge( $all_tax_rates, $taxes );
		}

		return $all_tax_rates;

	}

	/**
	 *  Get Oxygen Tax Rates array.
	 *
	 *  @return array of tax data fetched by Oxygen API
	 */
	public static function oxygen_tax_options() {

		$oxygen_get_taxes  = OxygenApi::get_taxes();
		$oxygen_taxes_json = '';
		$oxygen_taxes      = array();

		if ( is_array( $oxygen_get_taxes ) && isset( $oxygen_get_taxes['body'] ) && ! empty( $oxygen_get_taxes['body'] ) ) {

			$oxygen_taxes_json = json_decode( $oxygen_get_taxes['body'], true );

			foreach ( $oxygen_taxes_json['data'] as $oxygen_tax ) {

				$oxygen_taxes[ $oxygen_tax['id'] ] = $oxygen_tax['title'] . ' ' . $oxygen_tax['rate'];

			}
		}

		return array( '0' => '' ) + $oxygen_taxes;

	}

	/**
	 *  Get Oxygen Numbering Sequences array.
	 *
	 *  @return array of sequence data fetched by Oxygen API
	 */
	public static function oxygen_sequences_options() {

		$oxygen_get_sequences  = OxygenApi::get_sequences();
		$oxygen_sequences_json = '';
		$oxygen_sequences      = array();

		if ( is_array( $oxygen_get_sequences ) && isset( $oxygen_get_sequences['code'] ) && 200 !== $oxygen_get_sequences['code'] ) {
			return false;
		}

		if ( is_array( $oxygen_get_sequences ) && isset( $oxygen_get_sequences['body'] ) && ! empty( $oxygen_get_sequences['body'] ) ) {

			$oxygen_sequences_json = json_decode( $oxygen_get_sequences['body'], true );

			foreach ( $oxygen_sequences_json['data'] as $oxygen_sequence ) {

				if ( 0 === $oxygen_sequence['status'] ) {
					continue;
				}

				$oxygen_sequences[ $oxygen_sequence['document_type'] ][ $oxygen_sequence['id'] ] = $oxygen_sequence['title'] . ' ' . $oxygen_sequence['name'] . ' ' . ( 0 === $oxygen_sequence['status'] ? ' - ' . __( 'Inactive', 'oxygen' ) : '' );

			}
		}

		return array( '0' => '' ) + $oxygen_sequences;

	}

	/**
	 *  Get Oxygen Payment Methods array.
	 *
	 *  @return array of payment methods data fetched by Oxygen API
	 */
	public static function oxygen_payment_methods() {

		$oxygen_get_payment_methods  = OxygenApi::get_payment_methods();
		$oxygen_payment_methods_json = '';
		$oxygen_payment_methods      = array();

		if ( is_array( $oxygen_get_payment_methods ) && isset( $oxygen_get_payment_methods['code'] ) && 200 !== $oxygen_get_payment_methods['code'] ) {
			return false;
		}

		if ( is_array( $oxygen_get_payment_methods ) && isset( $oxygen_get_payment_methods['body'] ) && ! empty( $oxygen_get_payment_methods['body'] ) ) {

			$oxygen_payment_methods_json = json_decode( $oxygen_get_payment_methods['body'], true );

			foreach ( $oxygen_payment_methods_json['data'] as $oxygen_pm ) {

				if ( true !== $oxygen_pm['status'] ) {
					continue;
				}

				$oxygen_payment_methods[ $oxygen_pm['id'] ] = $oxygen_pm['title_gr'];

			}
		}

		return array( '0' => '' ) + $oxygen_payment_methods;

	}

	/**
	 *  Get Oxygen remote logos array.
	 *
	 *  @return array of logos data fetched by Oxygen API
	 */
	public static function get_logos() {

		$logos          = OxygenApi::get_logos();
		$logos_settings = array();

		if ( empty( $logos ) ) {
			return false;
		}

		if ( is_array( $logos ) ) {

			foreach ( $logos as $logo ) {

				if ( is_array( $logo ) && isset( $logo['id'] ) ) {
					$logos_settings[ $logo['id'] ] = $logo['title'];
				}
			}
		}

		return array( '0' => '' ) + $logos_settings;
	}

	/**
	 *  Get Oxygen default logo id.
	 *
	 *  @return string | false the default logo id or false if not set
	 */
	public static function get_default_logo_id() {

		$logos = OxygenApi::get_logos();

		if ( empty( $logos ) || ! is_array( $logos ) ) {
			return false;
		}

		$default = array_search( true, array_column( $logos, 'is_default' ) );

		if ( false === $default ) {
			return false;
		}

		return $logos[ $default ]['id'];
	}

	/**
	 *  Cache user meta keys.
	 *
	 *  @return array of user meta keys
	 */
	public static function generate_user_meta_keys() {
		global $wpdb;

		$query     = "
			SELECT DISTINCT($wpdb->usermeta.meta_key) 
			FROM $wpdb->usermeta 
			WHERE $wpdb->usermeta.meta_key != '' 
			AND $wpdb->usermeta.meta_key NOT RegExp '(^[0-9]+$)'
		";
		$meta_keys = $wpdb->get_col( $query ); // phpcs:ignore unprepared SQL OK.
		set_transient( 'oxygen_user_meta_keys', $meta_keys, 60 * 60 * 24 ); // create 1 Day Expiration.
		return $meta_keys;
	}

	/**
	 *  Get cached ot cache user meta keys.
	 *
	 *  @return array of user meta keys
	 */
	public static function get_user_meta_keys() {
		$cache     = get_transient( 'oxygen_user_meta_keys' );
		$meta_keys = $cache ? $cache : self::generate_user_meta_keys();
		return array_combine( $meta_keys, $meta_keys );
	}

	/**
	 *  Add new WooCommerce product tab
	 *
	 *  @param array $tabs of WooCommerce tabs.
	 *
	 *  @return array of WooCommerce tabs
	 */
	public static function product_data_tabs( $tabs ) {

		$tabs['oxygen'] = array(
			'label'    => _x( 'Oxygen options', 'Oxygen product settings', 'woocommerce' ),
			'target'   => 'oxygen_settings',
			'class'    => array(),
			'priority' => 990,
		);

		return $tabs;
	}

	/**
	 *  Add new WooCommerce product tab content
	 *
	 *  @return void
	 */
	public static function product_data_panels() {

		global $post;

		?>
		<div id="oxygen_settings" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<?php
				woocommerce_wp_select(
					array(
						'id'          => 'mydata_category',
						'value'       => get_post_meta( $post->ID, 'mydata_category', true ),
						'label'       => __( 'Product myData Category', 'oxygen' ),
						'desc_tip'    => true,
						'description' => __( 'This will override the category classification value', 'oxygen' ),
						'options'     => array( '0' => '' ) + self::mydata_classification_categories(),
					)
				);

				?>
				<?php
				woocommerce_wp_select(
					array(
						'id'          => 'mydata_classification_type',
						'value'       => get_post_meta( $post->ID, 'mydata_classification_type', true ),
						'label'       => __( 'Product myData Classification Type', 'oxygen' ),
						'desc_tip'    => true,
						'description' => __( 'This will override the category value', 'oxygen' ),
						'options'     => array( '0' => '' ) + self::mydata_classification_types(),
					)
				);

				?>
				<?php
				woocommerce_wp_select(
					array(
						'id'          => 'mydata_category_receipt',
						'value'       => get_post_meta( $post->ID, 'mydata_category_receipt', true ),
						'label'       => __( 'Product myData Category Receipt', 'oxygen' ),
						'desc_tip'    => true,
						'description' => __( 'This will override the category receipt classification value', 'oxygen' ),
						'options'     => array( '0' => '' ) + self::mydata_classification_categories(),
					)
				);

				?>
				<?php
				woocommerce_wp_select(
					array(
						'id'          => 'mydata_classification_type_receipt',
						'value'       => get_post_meta( $post->ID, 'mydata_classification_type_receipt', true ),
						'label'       => __( 'Product myData Classification Type Receipt', 'oxygen' ),
						'desc_tip'    => true,
						'description' => __( 'This will override the category receipt value', 'oxygen' ),
						'options'     => array( '0' => '' ) + self::mydata_classification_types(),
					)
				);

				?>
			</div>
		</div>
		<?php

	}

	/**
	 *  Add new WooCommerce product categories fields
	 *
	 *  @return void
	 */
	public static function add_term_fields() {

		?>
		<div class="form-field">
			<label for="mydata_category"><?php esc_html_e( 'myData Category', 'oxygen' ); ?></label>
			<select name="mydata_category">
				<option value=""></option>

		<?php
			$mydata_classification_categories = self::mydata_classification_categories();
		foreach ( $mydata_classification_categories as $key => $classification ) {
			?>
			<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $classification ); ?></option>
			<?php
		}
		?>
			</select>
		</div>

		<div class="form-field">
			<label for="mydata_classification_type"><?php esc_html_e( 'myData Type', 'oxygen' ); ?></label>
			<select name="mydata_classification_type">
				<option value=""></option>

		<?php
			$mydata_classification_types = self::mydata_classification_types();
		foreach ( $mydata_classification_types as $key => $type ) {
			?>
			<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_attr( $type ); ?></option>
			<?php
		}
		?>

			</select>
		</div>
		<div class="form-field">
			<label for="mydata_category_receipt"><?php esc_html_e( 'myData Category Receipt', 'oxygen' ); ?></label>
			<select name="mydata_category_receipt">
				<option value=""></option>

		<?php
			$mydata_classification_categories = self::mydata_classification_categories();
		foreach ( $mydata_classification_categories as $key => $classification ) {
			?>
			<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $classification ); ?></option>
			<?php
		}
		?>
			</select>
		</div>

		<div class="form-field">
			<label for="mydata_classification_type_receipt"><?php esc_html_e( 'myData Type Receipt', 'oxygen' ); ?></label>
			<select name="mydata_classification_type_receipt">
				<option value=""></option>

		<?php
			$mydata_classification_types = self::mydata_classification_types();
		foreach ( $mydata_classification_types as $key => $type ) {
			?>
			<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_attr( $type ); ?></option>
			<?php
		}
		?>

			</select>
		</div>
		<?php
			wp_nonce_field( 'oxygen_term_nonce', 'oxygen_nonce' );
	}

	/**
	 *  Edit WooCommerce product categories fields
	 *
	 *  @param object $term the term object.
	 *  @param object $taxonomy the taxonomy object.
	 *  @return void
	 */
	public static function edit_term_fields( $term, $taxonomy ) {

		$mydata_category                    = get_term_meta( $term->term_id, 'mydata_category', true );
		$mydata_classification_type         = get_term_meta( $term->term_id, 'mydata_classification_type', true );
		$mydata_category_receipt            = get_term_meta( $term->term_id, 'mydata_category_receipt', true );
		$mydata_classification_type_receipt = get_term_meta( $term->term_id, 'mydata_classification_type_receipt', true );
		?>
		<tr class="form-field">
			<th>
				<label for="mydata_category"><?php esc_html_e( 'myData Category', 'oxygen' ); ?></label>
			</th>
			<td>
			<select name="mydata_category">
				<option value=""></option>
		<?php
			$mydata_classification_categories = self::mydata_classification_categories();
		foreach ( $mydata_classification_categories as $key => $classification ) {
			?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php echo selected( esc_attr( $mydata_category ), esc_attr( $key ) ); ?>><?php echo esc_attr( $classification ); ?></option>;
			<?php
		}
		?>
			</select>
			</td>
		</tr>
		<tr class="form-field">
			<th>
				<label for="mydata_classification_type"><?php esc_html_e( 'myData Type' ); ?></label>
			</th>
			<td>
			<select name="mydata_classification_type">
				<option value=""></option>
			<?php
			$mydata_classification_types = self::mydata_classification_types();
			foreach ( $mydata_classification_types as $key => $type ) {
				?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php echo selected( esc_attr( $mydata_classification_type ), esc_attr( $key ) ); ?>><?php echo esc_attr( $type ); ?></option>
				<?php
			}
			?>
				</select>
				<?php wp_nonce_field( 'oxygen_term_nonce', 'oxygen_nonce' ); ?>
			</td>
		</tr>
		<tr class="form-field">
			<th>
				<label for="mydata_category_receipt"><?php esc_html_e( 'myData Category Receipt', 'oxygen' ); ?></label>
			</th>
			<td>
				<select name="mydata_category_receipt">
				<option value=""></option>
		<?php
			$mydata_classification_categories = self::mydata_classification_categories();
		foreach ( $mydata_classification_categories as $key => $classification ) {
			?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php echo selected( esc_attr( $mydata_category_receipt ), esc_attr( $key ) ); ?>><?php echo esc_attr( $classification ); ?></option>;
			<?php
		}
		?>
			</select>
			</td>
		</tr>
		<tr class="form-field">
			<th>
				<label for="mydata_classification_type_receipt"><?php esc_html_e( 'myData Type Receipt' ); ?></label>
			</th>
			<td>
			<select name="mydata_classification_type_receipt">
				<option value=""></option>
			<?php
			$mydata_classification_types = self::mydata_classification_types();
			foreach ( $mydata_classification_types as $key => $type ) {
				?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php echo selected( esc_attr( $mydata_classification_type_receipt ), esc_attr( $key ) ); ?>><?php echo esc_attr( $type ); ?></option>
				<?php
			}
			?>
				</select>
				<?php wp_nonce_field( 'oxygen_term_nonce', 'oxygen_nonce' ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 *  Save new WooCommerce product categories fields
	 *
	 *  @param int $term_id the term id of the fields that is being saved.
	 *
	 *  @return void|int
	 */
	public static function save_term_fields( $term_id ) {

		// Check permisions.
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return $term_id;
		}

		if ( ! isset( $_POST['oxygen_nonce'] ) ) {
			return $term_id;
		}

		// Security nonce check.
		$nonce = sanitize_key( wp_unslash( $_POST['oxygen_nonce'] ) );
		if ( empty( sanitize_key( $nonce ) ) || ! wp_verify_nonce( $nonce, 'oxygen_term_nonce' ) ) {

			return $term_id;
		}

		if ( isset( $_POST['mydata_category'] ) && ! empty( $_POST['mydata_category'] ) ) {
			update_term_meta(
				$term_id,
				'mydata_category',
				sanitize_text_field( wp_unslash( $_POST['mydata_category'] ) )
			);
		} else {
			delete_term_meta( $term_id, 'mydata_category' );
		}
		if ( isset( $_POST['mydata_classification_type'] ) && ! empty( $_POST['mydata_classification_type'] ) ) {
			update_term_meta(
				$term_id,
				'mydata_classification_type',
				sanitize_text_field( wp_unslash( $_POST['mydata_classification_type'] ) )
			);
		} else {
			delete_term_meta( $term_id, 'mydata_classification_type' );
		}
		if ( isset( $_POST['mydata_category_receipt'] ) && ! empty( $_POST['mydata_category_receipt'] ) ) {
			update_term_meta(
				$term_id,
				'mydata_category_receipt',
				sanitize_text_field( wp_unslash( $_POST['mydata_category_receipt'] ) )
			);
		} else {
			delete_term_meta( $term_id, 'mydata_category_receipt' );
		}
		if ( isset( $_POST['mydata_classification_type_receipt'] ) && ! empty( $_POST['mydata_classification_type_receipt'] ) ) {
			update_term_meta(
				$term_id,
				'mydata_classification_type_receipt',
				sanitize_text_field( wp_unslash( $_POST['mydata_classification_type_receipt'] ) )
			);
		} else {
			delete_term_meta( $term_id, 'mydata_classification_type_receipt' );
		}

	}

	/**
	 *  Save WooCommerce product custom fields
	 *
	 *  @param int $product_id the ID of the product.
	 *  @return void
	 */
	public static function product_save( $product_id ) {

		if ( ! is_admin() ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}

		// Security nonce check.
		$nonce = sanitize_key( wp_unslash( $_POST['_wpnonce'] ) );
		if ( empty( sanitize_key( $nonce ) ) || ! wp_verify_nonce( $nonce, "update-post_$product_id" ) ) {
			return;
		}

		if ( isset( $_POST['mydata_category'] ) ) {

			if ( '0' === $_POST['mydata_category'] ) {

				delete_post_meta( $product_id, 'mydata_category' );

			} else {

				update_post_meta( $product_id, 'mydata_category', sanitize_text_field( wp_unslash( $_POST['mydata_category'] ) ) );
			}
		}

		if ( isset( $_POST['mydata_classification_type'] ) ) {

			if ( '0' === $_POST['mydata_classification_type'] ) {

				delete_post_meta( $product_id, 'mydata_classification_type' );

			} else {

				update_post_meta( $product_id, 'mydata_classification_type', sanitize_text_field( wp_unslash( $_POST['mydata_classification_type'] ) ) );
			}
		}

		if ( isset( $_POST['mydata_category_receipt'] ) ) {

			if ( '0' === $_POST['mydata_category_receipt'] ) {

				delete_post_meta( $product_id, 'mydata_category_receipt' );

			} else {

				update_post_meta( $product_id, 'mydata_category_receipt', sanitize_text_field( wp_unslash( $_POST['mydata_category_receipt'] ) ) );
			}
		}

		if ( isset( $_POST['mydata_classification_type_receipt'] ) ) {

			if ( '0' === $_POST['mydata_classification_type_receipt'] ) {

				delete_post_meta( $product_id, 'mydata_classification_type_receipt' );

			} else {

				update_post_meta( $product_id, 'mydata_classification_type_receipt', sanitize_text_field( wp_unslash( $_POST['mydata_classification_type_receipt'] ) ) );
			}
		}

	}

	/**
	 *  Oxygen API myData Classification Categories
	 *
	 *  @return array
	 */
	private static function mydata_classification_categories() {

		return array(
			'category1_1' => 'category1_1 - Έσοδα από Πώληση Εμπορευμάτων',
			'category1_2' => 'category1_2 - Έσοδα από Πώληση Προϊόντων',
			'category1_3' => 'category1_3 - Έσοδα από Παροχή Υπηρεσιών',
		);

	}

	/**
	 *  Oxygen API myData Classification Types
	 *
	 *  @return array
	 */
	public static function mydata_classification_types() {

		return array(
			'E3_561_001' => 'E3_561_001 - Αγορές Εμπορευμάτων',
			'E3_561_001' => 'E3_561_001 - Πωλήσεις αγαθών και υπηρεσιών Χονδρικές - Επιτηδευματιών',
			'E3_561_002' => 'E3_561_002 - Πωλήσεις αγαθών και υπηρεσιών Χονδρικές βάσει άρθρου 39α παρ 5 του Κώδικα Φ.Π.Α. (Ν.2859/2000)',
			'E3_561_003' => 'E3_561_003 - Πωλήσεις αγαθών και υπηρεσιών Λιανικές - Ιδιωτική Πελατεία',
			'E3_561_004' => 'E3_561_004 - Πωλήσεις αγαθών και υπηρεσιών Λιανικές βάσει άρθρου 39α παρ 5 του Κώδικα Φ.Π.Α. (Ν.2859/2000)',
			'E3_561_005' => 'E3_561_005 - Πωλήσεις αγαθών και υπηρεσιών Εξωτερικού Ενδοκοινοτικές',
			'E3_561_006' => 'E3_561_006 - Πωλήσεις αγαθών και υπηρεσιών Εξωτερικού Τρίτες Χώρες',
			'E3_561_007' => 'E3_561_007 - Πωλήσεις αγαθών και υπηρεσιών Λοιπά',
			'E3_562'     => 'E3_562 - Λοιπά συνήθη έσοδα',

		);

	}

	/**
	 *  Oxygen API Document Types
	 *
	 *  @return array
	 */
	public static function document_types() {

		return array(
			'tpy'    => 's',
			'tpda'   => 'p',
			'apy'    => 'rs',
			'alp'    => 'rp',
			'notice' => 'notice',
		);

	}

	/**
	 *  Oxygen API myData Document Types
	 *
	 *  @return array
	 */
	public static function mydata_document_types() {

		return array(
			'tpy'    => '2.1',
			'tpda'   => '1.1',
			'apy'    => '11.2',
			'alp'    => '11.1',
			'notice' => 'notice',
		);

	}

	/**
	 *  Oxygen API Document Types Names
	 *
	 *  @return array
	 */
	public static function document_type_names() {

		return array(
			'tpy'    => 'Τιμολόγιο Παροχής Υπηρεσιών - ΤΠΥ',
			'tpda'   => 'Τιμολόγιο Πώλησης - Δελτίο αποστολής - ΤΠΔΑ',
			'apy'    => 'Απόδειξη παροχής υπηρεσιών - ΑΠΥ',
			'alp'    => 'Απόδειξη πώλησης αγαθών (Απόδειξη Λιανικής πώλησης) - ΑΛΠ',
			'notice' => 'Ειδοποιήσεις - Παραγγελίες',
		);

	}

	/**
	 *  Oxygen API Checks
	 *
	 *  @return array|bool
	 */
	public static function api_checks() {

		$oxygen_document_types              = self::document_types();
		$oxygen_document_type_names         = self::document_type_names();
		$oxygen_taxes                       = get_option( 'oxygen_taxes' );
		$oxygen_payment_methods             = get_option( 'oxygen_payment_methods' );
		$mydata_category                    = get_option( 'mydata_category' );
		$mydata_classification_type         = get_option( 'mydata_classification_type' );
		$mydata_category_receipt            = get_option( 'mydata_category_receipt' );
		$mydata_classification_type_receipt = get_option( 'mydata_classification_type_receipt' );

		$errors = array();

		if ( ! empty( $oxygen_document_types ) ) {

			foreach ( $oxygen_document_types as $doc_key => $doc_type ) {

				if ( empty( get_option( 'oxygen_num_sequence' . $doc_key ) ) ) {

					$errors[] = esc_attr__( 'The numbering sequences values are empty', 'oxygen' );
					break;
				}
			}
		}

		if ( empty( $oxygen_taxes ) || ( is_array( $oxygen_taxes ) && array_filter( $oxygen_taxes ) !== $oxygen_taxes ) ) {

			$errors[] = esc_attr__( 'The taxes configuration has not been setup', 'oxygen' );

		}

		if ( empty( $oxygen_payment_methods )) {

			$errors[] = esc_attr__( 'The payment methods have not been setup', 'oxygen' );

		}

		if ( empty( $mydata_category ) ) {

			$errors[] = esc_attr__( 'The default myData category has not been setup', 'oxygen' );

		}

		if ( empty( $mydata_classification_type ) ) {

			$errors[] = esc_attr__( 'The default myData classification type has not been setup', 'oxygen' );

		}

		if ( empty( $mydata_category_receipt ) ) {

			$errors[] = esc_attr__( 'The default myData category receipt has not been setup', 'oxygen' );

		}

		if ( empty( $mydata_classification_type_receipt ) ) {

			$errors[] = esc_attr__( 'The default myData classification receipt type has not been setup', 'oxygen' );

		}

		if ( ! empty( $errors ) ) {

			return $errors;

		}

		return false;

	}

	/**
	 *  Oxygen Debug
	 *
	 *  @param array  $log Array of strings to log.
	 *  @param string $type String of log type.
	 *
	 *  @return void
	 */
	public static function debug( $log = array(), $type = 'error' ) {

		if ( 1 === intval( self::$debug ) && function_exists( 'wc_get_logger' ) && ( is_array( $log ) && ! empty( $log ) ) ) {

			$logger = wc_get_logger();

			foreach ( $log as $l ) {

				$log_string = print_r( $l, true );

				if ( 'info' === $type ) {

					$logger->info( $log_string, array( 'source' => 'oxygen' ) );

				} elseif ( 'alert' === $type ) {

					$logger->alert( $log_string, array( 'source' => 'oxygen' ) );

				} elseif ( 'notice' === $type ) {

					$logger->notice( $log_string, array( 'source' => 'oxygen' ) );

				} elseif ( 'debug' === $type ) {

					$logger->debug( $log_string, array( 'source' => 'oxygen' ) );

				} elseif ( 'emergency' === $type ) {

					$logger->emergency( $log_string, array( 'source' => 'oxygen' ) );

				} elseif ( 'critical' === $type ) {

					$logger->critical( $log_string, array( 'source' => 'oxygen' ) );

				} elseif ( 'warning' === $type ) {

					$logger->warning( $log_string, array( 'source' => 'oxygen' ) );

				} else {

					$logger->error( $log_string, array( 'source' => 'oxygen' ) );
				}
			}
		}
	}

    public static function retrieve_oxygen_settings()
    {
        // Retrieve the settings
        $settings = array(
            'oxygen_api_key'                    => get_option('oxygen_api_key'),
            'oxygen_status'                     => get_option('oxygen_status'),
            'mydata_category'                   => get_option('mydata_category'),
            'mydata_classification_type'        => get_option('mydata_classification_type'),
            'mydata_category_receipt'           => get_option('mydata_category_receipt'),
            'mydata_classification_type_receipt'=> get_option('mydata_classification_type_receipt'),
            'oxygen_vat_metakey'                => get_option('oxygen_vat_metakey'),
            'oxygen_working_field_metakey'      => get_option('oxygen_working_field_metakey'),
            'oxygen_tax_office'                 => get_option('oxygen_tax_office'),
            'oxygen_issue_invoice_metakey'      => get_option('oxygen_issue_invoice_metakey'),
            'oxygen_default_document_type'      => get_option('oxygen_default_document_type'),
            'oxygen_logo'                       => get_option('oxygen_logo'),
            'oxygen_self_fields'                => get_option('oxygen_self_fields'),
            'oxygen_default_receipt_doctype'    => get_option('oxygen_default_receipt_doctype'),
            'oxygen_default_invoice_doctype'    => get_option('oxygen_default_invoice_doctype'),
            'oxygen_shipping_code'              => get_option('oxygen_shipping_code'),
            'oxygen_order_status'               => get_option('oxygen_order_status'),
            'oxygen_order_attachment'           => get_option('oxygen_order_attachment'),
            'oxygen_language'                   => get_option('oxygen_language'),
            'oxygen_is_paid'                    => get_option('oxygen_is_paid'),
            'oxygen_debug'                      => get_option('oxygen_debug')
        );

        // Convert to JSON
        return json_encode($settings, JSON_PRETTY_PRINT);
    }

    public static function download_oxygen_settings(): void
    {

        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['download_oxygen_settings']) && $_GET['download_oxygen_settings'] == 'true') {

            $settings = self::retrieve_oxygen_settings();
            // Set headers to force download
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="oxygen-settings.json"');
            header('Content-Length: ' . strlen($settings));

            // Output the settings and exit
            echo $settings;
            exit;
        }
    }

    public static function find_most_recent_oxygen_log() {
        // Define the path to WooCommerce log files
        // Ensure WC_LOG_DIR is defined
        if ( ! defined( 'WC_LOG_DIR' ) ) {
            define( 'WC_LOG_DIR', WP_CONTENT_DIR . '/uploads/wc-logs/' );
        }

        // Define the path to WooCommerce log files
        $log_dir = WC_LOG_DIR;

        // Define the pattern to match files that start with 'oxygen-'
        $file_pattern = $log_dir . 'oxygen-*';

        // Get only the files that start with 'oxygen-'
        $log_files = glob($file_pattern);

        // Initialize a variable to store the most recent file
        $most_recent_file = null;
        $most_recent_time = 0;

        // Iterate through the log files and find the most recent match
        foreach ($log_files as $file) {
            $file_time = filemtime($file); // Get the last modification time
            if ($file_time > $most_recent_time) {
                $most_recent_time = $file_time;
                $most_recent_file = $file;
            }
        }

        // If a file is found, return its URL
        if ($most_recent_file) {
            return $most_recent_file;
        }

        $log = array( '----------------- no oxygen log file found ----------------', $most_recent_file);
        OxygenWooSettings::debug( $log );
        return false;
    }

    public function download_wc_log() {
        // Check if the file parameter exists
        if (isset($_GET['file'])) {
            $file_name = sanitize_file_name($_GET['file']);
            $file_path = WC_LOG_DIR . $file_name;

            // Verify the file exists
            if (file_exists($file_path)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
                header('Content-Length: ' . filesize($file_path));
                flush(); // Flush system output buffer
                readfile($file_path); // Read the file and output its contents
                exit;
            } else {
                wp_die(__('Oxygen log file not found.', 'oxygen'));
            }
        } else {
            wp_die(__('No oxygen log file specified.', 'oxygen'));
        }
    }

}
