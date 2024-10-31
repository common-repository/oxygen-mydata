<?php
/**
 * OxygenOrder Class File
 *
 * @package Oxygen
 * @summary Class to add WooCommerce order hooks
 * @version 1.0.0
 * @since  1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * Oxygen MyData Class
 */
class OxygenOrder {


	/**
	 * Singleton Instance of OxygenOrder
	 *
	 * @var OxygenOrder
	 **/
	private static $instance = null;

	/**
	 * WooCommerce order ID
	 *
	 * @var int order ID
	 */
	private static $order_id = null;


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
	 * Oxygen Constructor
	 */
	private function __construct() {

		$this->init_hooks();
	}

	/**
	 *  Add all order hooks
	 *
	 *  @return void
	 */
	private function init_hooks() {

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_init', array( $this, 'oxygen_actions' ) );
		add_action( 'woocommerce_after_order_object_save', array( $this, 'save_order' ), 20, 1 );
		add_filter( 'woocommerce_email_attachments', array( $this, 'oxygen_attach_pdf_to_emails' ), 10, 4 );
		add_action( 'woocommerce_new_order', array( $this, 'on_order_create' ), 10, 1 );
		add_action( 'woocommerce_thankyou', array( $this, 'on_order_thankyou' ), 10, 1 );

		if ( 'yes' === get_option( 'oxygen_self_fields' ) ) {

			// Add VAT fields in billing address display.
			add_filter( 'woocommerce_checkout_fields', array( $this, 'override_checkout_fields' ) );
			add_filter( 'woocommerce_checkout_process', array( $this, 'validate_checkout_fields' ) );
			add_filter( 'woocommerce_address_to_edit', array( $this, 'oxygen_address_to_edit' ), 10, 2 );
			add_filter( 'woocommerce_order_formatted_billing_address', array( $this, 'oxygen_order_formatted_billing_address' ), 10, 2 );
			add_filter( 'woocommerce_my_account_my_address_formatted_address', array( $this, 'oxygen_my_account_my_address_formatted_address' ), 10, 3 );
			add_filter( 'woocommerce_formatted_address_replacements', array( $this, 'oxygen_formatted_address_replacements' ), 10, 2 );
			add_filter( 'woocommerce_admin_billing_fields', array( $this, 'oxygen_admin_billing_fields' ), 10, 1 );
			add_filter( 'woocommerce_ajax_get_customer_details', array( $this, 'oxygen_found_customer_details' ), 10, 3 );
			add_filter( 'woocommerce_customer_meta_fields', array( $this, 'oxygen_customer_meta_fields' ), 10, 1 );

			add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'my_account_my_orders_actions' ), 9999, 2 );

		}

		// add extra order list column(s).
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'shop_order_column' ), 20 );
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'shop_order_column' ), 20 ); // hpos.
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'orders_list_column_content' ), 20, 2 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'orders_list_column_content' ), 20, 2 ); // hpos.

		$oxygen_order_status = str_replace( 'wc-', '', OxygenWooSettings::get_option( 'oxygen_order_status' ) );

		if ( current_user_can( 'manage_woocommerce' ) && is_ajax()
			&& isset( $_REQUEST['action'] ) && 'woocommerce_mark_order_status' === $_REQUEST['action'] // phpcs:ignore
			&& isset( $_REQUEST['status'] ) && $_REQUEST['status'] === $oxygen_order_status // phpcs:ignore
			&& isset( $_REQUEST['order_id'] ) && intval( $_REQUEST['order_id'] ) > 0 // phpcs:ignore
		) {

			add_action( 'init', array( $this, 'run_on_woocommerce_mark_order_status' ) );

		}

		add_action( 'woocommerce_order_status_changed', array( $this, 'run_on_woocommerce_bulk_order_status' ), 10, 4 );

	}

	/**
	 *  Runs on WooCommerce on bulk action "woocommerce_order_status_changed".
	 *
	 *  @param integer $id order id.
	 *  @param string  $from_status order from status.
	 *  @param string  $new_status order to status.
	 *  @param object  $order WC_Order.
	 *
	 *  @return void
	 */
	public function run_on_woocommerce_bulk_order_status( $id, $from_status, $new_status, $order ) {

		if ( $order ) {

			$oxygen_order_status = str_replace( 'wc-', '', OxygenWooSettings::get_option( 'oxygen_order_status' ) );

			// status mismatch.
			if ( $oxygen_order_status !== $new_status ) {

				return;
			}

			$oxygen_default_document_type      = OxygenWooSettings::get_option( 'oxygen_default_document_type' );
			$_GET['notetype']                  = ( ! empty( $oxygen_default_document_type ) ? $oxygen_default_document_type : 'invoice' ); // default to invoice.
			$_GET['_oxygen_payment_note_type'] = $order->get_meta( '_oxygen_payment_note_type', true );

			$this->create_invoice( $order->get_id(), $order );

		} else {

			$log = array( '----------------- Invalid Order on bulk edit ' . gmdate( 'Y-m-d H:i:s' ) . ' -----------------', $order );
			OxygenWooSettings::debug( $log );
		}
	}

	/**
	 *  Runs on WooCommerce action "woocommerce_mark_order_status".
	 *
	 *  @return void
	 */
	public function run_on_woocommerce_mark_order_status() {

		$order = null;

		if ( isset( $_REQUEST['order_id'] ) ) { // phpcs:ignore

			$order = wc_get_order( intval( $_REQUEST['order_id'] ) ); // phpcs:ignore
		}

		if ( ! empty( $order ) ) {

			$oxygen_default_document_type      = OxygenWooSettings::get_option( 'oxygen_default_document_type' );
			$_GET['notetype']                  = ( ! empty( $oxygen_default_document_type ) ? $oxygen_default_document_type : 'invoice' ); // default to invoice.
			$_GET['_oxygen_payment_note_type'] = $order->get_meta( '_oxygen_payment_note_type', true );

			$this->create_invoice( $order->get_id(), $order );

		} else {

			$log = array( '----------------- Invalid Order ' . gmdate( 'Y-m-d H:i:s' ) . ' -----------------', $order );
			OxygenWooSettings::debug( $log );
		}
	}

	/**
	 *  Create the metabox for Oxygen order data.
	 *
	 *  @return void
	 */
	public function add_meta_box() {

		$screen = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
		? wc_get_page_screen_id( 'shop-order' )
		: 'shop_order';

		add_meta_box( 'oxygen_order_extra', __( 'Oxygen', 'oxygen' ), array( $this, 'order_metabox_content' ), $screen, 'side', 'core' );
	}

	/**
	 *  Create the content of the Oxygen order metabox.
	 *
	 *  @param object $post_or_order_object WC_Order | WP_Post .
	 *
	 *  @return void
	 */
	public function order_metabox_content( $post_or_order_object ) {

		$order = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;

		if ( ! $order ) {
			return;
		}

		global $post;

		self::$order_id = $order->get_id();

		$check = OxygenApi::check_connection();

		if ( ! $check ) {
			?>
			<a href="<?php echo esc_url( get_admin_url() . 'admin.php?page=wc-settings&tab=oxygen' ); ?>">
			<?php esc_html_e( 'Oxygen setup', 'oxygen' ); ?>
			</a>
			<?php
			return;
		}

		$nonce = wp_create_nonce( 'oxygen-' . $order->get_id() . '-nonce' );

		$_oxygen_payment_note_type = sanitize_text_field( $order->get_meta( $order->get_id(), '_oxygen_payment_note_type', true ) );

		$document_type_names = OxygenWooSettings::document_type_names();
		unset( $document_type_names['notice'] );

		$invoice_data = $order->get_meta( '_oxygen_invoice', true );
		$notice_data  = $order->get_meta( '_oxygen_notice', true );
		$pdf          = $order->get_meta( '_oxygen_invoice_pdf', true );
		$note_type    = $order->get_meta( '_oxygen_payment_note_type', true );

		if ( isset( $invoice_data['iview_url'] ) && ! empty( $invoice_data['iview_url'] ) ) {
			?>
			<div>
				<p><a href="<?php echo esc_url( $invoice_data['iview_url'] ); ?>" target="_blank" class="button wide-fat"><span class="dashicons dashicons-search"></span>
				<?php
				if ( 'apy' === $note_type ) {
					esc_html_e( 'View Receipt', 'oxygen' );
				} else {
					esc_html_e( 'View Invoice', 'oxygen' );
				}
				?>
				</a></p>
				<p><a href="<?php echo esc_url( $pdf ); ?>" target="_blank" class="button wide-fat"><span class="dashicons dashicons-pdf"></span> <?php esc_html_e( 'PDF Download', 'oxygen' ); ?></a></p>
				<p>
				<?php
				if ( 'apy' === $note_type ) {
					esc_html_e( 'Oxygen Receipt', 'oxygen' );
				} else {
					esc_html_e( 'Oxygen invoice:', 'oxygen' );
				}
				?>
					<strong><a href="<?php echo esc_url( $invoice_data['iview_url'] ); ?>" target="_blank"><?php echo esc_html( $invoice_data['sequence'] ) . esc_html( $invoice_data['number'] ); ?></a></strong>
				</p>
			</div>
			<?php
		} else {

			wp_nonce_field( 'oxygen-' . $order->get_id() . '-nonce', 'oxygen_nonce' );
			?>

			<div>
				<label for="_oxygen_payment_note_type"><?php esc_html_e( 'Payment Note Type', 'oxygen' ); ?></label>
				<p>
					<select name="_oxygen_payment_note_type" id="_oxygen_payment_note_type" class="wide wide-fat">
						<option value=""></option>
						<?php foreach ( $document_type_names as $key => $type ) { ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $_oxygen_payment_note_type, $key ); ?>><?php echo esc_html( $type ); ?></option>
						<?php } ?>

					</select><br />
				</p>
			</div>

			<div>
				<p>
				<a href="<?php echo esc_url( get_admin_url() . 'post.php?post=' . intval( $order->get_id() ) . '&action=edit&oxygen=create_invoice&_wpnonce=' . esc_attr( $nonce ) ); ?>" class="button create_invoice button-primary disabled"><?php esc_html_e( 'Create Invoice', 'oxygen' ); ?></a>
				</p>

				<?php if ( ! empty( $notice_data ) && isset( $notice_data['iview_url'] ) && ! empty( $notice_data['iview_url'] ) ) { ?>

					<p><a href="<?php echo esc_url( $notice_data['iview_url'] ); ?>" target="_blank" class="button wide-fat"><span class="dashicons dashicons-media-document" style="transform: translateY(4px)"></span> <?php esc_html_e( 'View Notice', 'oxygen' ); ?></a></p>

				<?php } ?>
					<p>
					<a href="<?php echo esc_url( get_admin_url() . 'post.php?post=' . intval( $order->get_id() ) . '&action=edit&oxygen=create_notice&_wpnonce=' . esc_attr( $nonce ) ); ?>" class="button action create_notice"><?php esc_html_e( 'Create Notice', 'oxygen' ); ?></a>
					</p>
			</div>
			<script>
			jQuery( document ).ready( function($) {

				if ( $('#_oxygen_payment_note_type').length > 0 ) {

					var $type = $('#_oxygen_payment_note_type').val();

					if ( $type == '' ) {

						$( '.create_invoice' ).addClass( 'disabled' );

					} else {

						$( '.create_invoice' ).removeClass( 'disabled' );
					}

					$('#_oxygen_payment_note_type').on( 'change', function() {

						var $dropthis = $( this );
						$type = $dropthis.val();

						if ( $dropthis.val() == '' ) {

							$( '.create_invoice' ).addClass( 'disabled' );

							return;

						} else {

							$( '.create_invoice' ).removeClass( 'disabled' );
						}

					});

					$( '.create_invoice,.create_notice' ).on( 'click', function( e ) {

						e.preventDefault();

						if ( $(this).hasClass( 'disabled' ) ) {
							return false;
						}

						$( '.create_invoice,.create_notice' ).addClass( 'disabled' );

						window.location.href = $( this ).attr( 'href' )+'&_oxygen_payment_note_type='+$type;

						return false;

					});

				}

			});
			</script>
			<?php
		}

		if ( ! isset( $_GET['oxygen'] ) || ! isset( $_GET['_wpnonce'] ) ) { // phpcs:ignore

			WC_Admin_Notices::remove_notice( 'oxygen_payment_note_missing' );
			WC_Admin_Notices::remove_notice( 'oxygen_invalid_action' );
			WC_Admin_Notices::remove_notice( 'oxygen_payment_method_missing' );
			WC_Admin_Notices::remove_notice( 'oxygen_invoice_info_missing' );
			WC_Admin_Notices::remove_notice( 'oxygen_contact_error' );
			WC_Admin_Notices::remove_notice( 'oxygen_invoice_error' );
			WC_Admin_Notices::remove_notice( 'oxygen_invoice_success' );
			WC_Admin_Notices::remove_notice( 'oxygen_notice_success' );
			WC_Admin_Notices::remove_notice( 'oxygen_no_api' );

			return;
		}
	}

	/**
	 *  Trigger Oxygen actions for invoice and notices creation.
	 *
	 *  @return void
	 */
	public function oxygen_actions() {

		if ( ! is_admin() ) {
			return;
		}

		if ( ! isset( $_GET['oxygen'] ) || ! isset( $_GET['_wpnonce'] ) ) {

			return;
		}

		$oxygen_api_key = get_option( 'oxygen_api_key' );

		if ( empty( $oxygen_api_key ) ) {
			/* translators: %s: URL to Oxygen platform */
			WC_Admin_Notices::add_custom_notice( 'oxygen_no_api', sprintf( __( '<p>The Oxygen API key is missing. <a href="%s">Click here to add one</a>.</p>', 'oxygen' ), get_admin_url() . 'admin.php?page=wc-settings&tab=oxygen' ) );
			WC_Admin_Notices::output_custom_notices();

			WC_Admin_Notices::remove_notice( 'oxygen_no_api' );

			return;
		}

		if ( isset( $_REQUEST['_wpnonce'] ) ) {
			$nonce = sanitize_key( $_REQUEST['_wpnonce'] );
		} else {
			return;
		}

		$post_id = 0;

		if ( isset( $_REQUEST['post'] ) ) {
			$post_id = intval( $_REQUEST['post'] );
		}

		$verify = wp_verify_nonce( $nonce, 'oxygen-' . $post_id . '-nonce' );

		if ( ! $verify ) {

			WC_Admin_Notices::add_custom_notice( 'oxygen', '<p>Could not verify request</p>' );
			WC_Admin_Notices::output_custom_notices();

			WC_Admin_Notices::remove_notice( 'oxygen' );

			return;
		}

		// Disable WP Obj Caching ...
		wp_using_ext_object_cache( false );
		wp_cache_flush();
		wp_cache_init();

		$order = wc_get_order( $post_id );
		if ( ! $order ) {
			return;
		}

		$oxygen_action = sanitize_text_field( wp_unslash( $_GET['oxygen'] ) );

		$_GET['notetype'] = false;

		if ( ( isset( $_GET['_oxygen_payment_note_type'] ) && ! empty( $_GET['_oxygen_payment_note_type'] ) ) || ( empty( $_GET['_oxygen_payment_note_type'] ) && 'create_notice' === $oxygen_action ) ) {

			if ( 'create_invoice' === $oxygen_action ) {

				$_GET['notetype'] = 'invoice';

				$this->create_invoice( $order->get_id(), $order );

			} elseif ( 'create_notice' === $oxygen_action ) {

				$_GET['notetype'] = 'notice';

				$this->create_invoice( $order->get_id(), $order );

			} else {

				WC_Admin_Notices::add_custom_notice( 'oxygen_invalid_action', '<p>' . __( 'Invalid Oxygen action', 'oxygen' ) . '</p>' );
			}
		} else {

				WC_Admin_Notices::add_custom_notice( 'oxygen_payment_note_missing', '<p>' . __( 'Payment Note Type has not been defined', 'oxygen' ) . '</p>' );

		}

	}

	/**
	 *  Create order invoice on Oxygen API
	 *
	 *  @param array  $order_id the WC order ID.
	 *  @param object $order WC_Order.
	 *  @return array|false
	 */
	public function create_invoice( $order_id, $order ) {

		// Disable WP Obj Caching ...
		wp_using_ext_object_cache( false );
		wp_cache_flush();
		wp_cache_init();
		$_oxygen_invoice = $order->get_meta( '_oxygen_invoice', true );

		// abort duplicate invoice.
		if ( ! empty( $_oxygen_invoice ) ) {

			$order->add_order_note( 'Duplicate oxygen document aborted' );

			return false;
		}

		// if we are NOT on the thankyou page.
		if ( ! ( is_checkout() && is_wc_endpoint_url( 'order-received' ) ) ) {

			if ( isset( $_REQUEST['_wpnonce'] ) ) {
				$nonce = sanitize_key( $_REQUEST['_wpnonce'] );
			} else {
				return;
			}

			$verify              = wp_verify_nonce( $nonce, 'oxygen-' . $order_id . '-nonce' );
			$verify_oxygen_nonce = wp_verify_nonce( $nonce, 'oxygen-nonce' );

			if ( ! $verify && 0 === $order_id ) {

				$log = array( '----------------- Could not verify request ' . gmdate( 'Y-m-d H:i:s' ) . ' -----------------', array( $order_id, __( 'Could not verify request', 'oxygen' ) ) );
				OxygenWooSettings::debug( $log );

				WC_Admin_Notices::add_custom_notice( 'oxygen', '<p>Could not verify request</p>' );
				WC_Admin_Notices::output_custom_notices();

				WC_Admin_Notices::remove_notice( 'oxygen' );

				return;
			}
		}

		$log = array( '----------------- creating_invoice ' . gmdate( 'Y-m-d H:i:s' ) . ' -----------------', array() );
		OxygenWooSettings::debug( $log, 'info' );

		$post_id = $order_id;

		if ( isset( $_REQUEST['post'] ) ) {
			$post_id = intval( $_REQUEST['post'] );
		}

		if ( 0 === $post_id && isset( $_REQUEST['post_ID'] ) ) {
			$post_id = intval( $_REQUEST['post_ID'] );
		}

		$doc_key = false;

		if ( isset( $_GET['_oxygen_payment_note_type'] ) ) {
			$doc_key = sanitize_text_field( wp_unslash( $_GET['_oxygen_payment_note_type'] ) );
		}

		$oxygen_default_receipt_doctype = get_option( 'oxygen_default_receipt_doctype' );
		$oxygen_default_invoice_doctype = get_option( 'oxygen_default_invoice_doctype' );
		$oxygen_default_document_type   = OxygenWooSettings::get_option( 'oxygen_default_document_type' );

		if ( ! isset( $_GET['notetype'] ) ) {

			$log = array( '----------------- Invalid Payment Note Type ' . gmdate( 'Y-m-d H:i:s' ) . ' -----------------', array( $order_id, __( 'Invalid Payment Note Type', 'oxygen' ) ) );
			OxygenWooSettings::debug( $log );

			WC_Admin_Notices::add_custom_notice( 'oxygen_payment_note_missing', '<p>' . __( 'Invalid Payment Note Type', 'oxygen' ) . '</p>' );

			return false;

		}

		$notetype = sanitize_text_field( wp_unslash( $_GET['notetype'] ) );

		if ( 'notice' === $_GET['notetype'] ) {
			$doc_key = 'notice';
		}

		if ( isset( $_GET['oxygen'] ) && 'notice' !== $notetype && empty( $doc_key ) ) {

			$log = array( '----------------- Invalid Payment Note Type ' . gmdate( 'Y-m-d H:i:s' ) . ' -----------------', array( $order_id, __( 'Payment Note Type has not been defined', 'oxygen' ) ) );
			OxygenWooSettings::debug( $log );

			WC_Admin_Notices::add_custom_notice( 'oxygen_payment_note_missing', '<p>' . __( 'Payment Note Type has not been defined', 'oxygen' ) . '</p>' );

			return false;

		}

		$get_billing_vat_info = self::get_billing_vat_info( $order_id );

		if ( ! isset( $_GET['oxygen'] ) ) {

			// set the default document type.
			// is it selected to create an invoice.

			$should_create_invoice = false;

			if ( isset( $get_billing_vat_info['billing_invoice'] ) && ! empty( $get_billing_vat_info['billing_invoice'] ) ) {

				if ( false !== $get_billing_vat_info['billing_invoice'] ) {

					if ( 'y' === strtolower( $get_billing_vat_info['billing_invoice'] ) || 1 === $get_billing_vat_info['billing_invoice'] || '1' === $get_billing_vat_info['billing_invoice'] || 'yes' === strtolower( $get_billing_vat_info['billing_invoice'] ) ) {

						$should_create_invoice = true;
					}
				}
			}

			if ( false !== $get_billing_vat_info && true === $should_create_invoice && ! empty( $order->get_billing_company() ) && ! empty( $oxygen_default_invoice_doctype ) ) {

				$order->update_meta_data( '_oxygen_payment_note_type', $oxygen_default_invoice_doctype );
				$doc_key = $oxygen_default_invoice_doctype;

			} else {

				if ( ! empty( $oxygen_default_receipt_doctype ) ) {

					$order->update_meta_data( '_oxygen_payment_note_type', $oxygen_default_receipt_doctype );
					$doc_key = $oxygen_default_receipt_doctype;

				}
			}
		} else {

			$order->update_meta_data( '_oxygen_payment_note_type', $doc_key );
		}

		$class_cat_subfix = '';
		if ( 'alp' === $doc_key || 'apy' === $doc_key ) {
			$class_cat_subfix = '_receipt';
		}

		$document_types  = OxygenWooSettings::document_types();
		$mydata_types    = OxygenWooSettings::mydata_document_types();
		$payment_methods = OxygenWooSettings::get_option( 'oxygen_payment_methods' );
		$oxygen_taxes    = OxygenWooSettings::oxygen_tax_options();

		if ( ! isset( $payment_methods[ $order->get_payment_method() ] ) ) {

			$log = array( '----------------- Invalid Payment Note Type ' . gmdate( 'Y-m-d H:i:s' ) . ' -----------------', array( $order_id, __( 'Payment method not found', 'oxygen' ) ) );
			OxygenWooSettings::debug( $log );

			WC_Admin_Notices::add_custom_notice( 'oxygen_payment_method_missing', '<p>' . __( 'Payment method not found', 'oxygen' ) . '</p>' );

			return false;
		}

        /* an einai timologio */
        $oxygen_customer_id = '';

        if ( 'invoice' === $notetype && ($doc_key === 'tpy' || $doc_key === 'tpda') ) {

            OxygenWooSettings::debug( array("------- you asked to create an invoice -------- ") );
            $get_billing_vat_info = self::get_billing_vat_info( $order_id );
            OxygenWooSettings::debug( array('get billing vat info --' .json_encode($get_billing_vat_info)));

            $checkout_email = $order->get_billing_email();
            $checkout_vat = $get_billing_vat_info['billing_vat'];

            if( !empty($checkout_vat)){

                $contact_by_vat = OxygenApi::get_contact_by_vat($checkout_vat);

                if( empty($contact_by_vat['data'])){ /* den yparxei h epafh */

                    /* TODO CREATE NEW CONTACT WITH BILLING EMAIL AND VAT AND MAKE THE REST */
                    $new_contact = self::create_new_contact($order, $get_billing_vat_info);
                    $oxygen_customer_id = $new_contact['id'];

                    OxygenWooSettings::debug( array('new customer id is ONE'));

                }else if(!empty($checkout_email) && $checkout_email !== $contact_by_vat['data'][0]['email'] && $checkout_vat !== $contact_by_vat['data'][0]['vat_number']) {
                    /* otan to email sto checkout einai allo apo to email tou afm poy xrhsimopoieitai gia th ekdosh na dhmiourgei neo xrhsth */

                    $new_contact = self::create_new_contact($order, $get_billing_vat_info);
                    $oxygen_customer_id = $new_contact['id'];

                    OxygenWooSettings::debug( array('------- email checkout !== email of vat NEW customer id is --------',$checkout_email ,));

                }else { /* contact vat data are filled AND checkout email same with vat email */

                    /* TODO MAKE REST WITH THAT CONTACT AND VAT OF CHECKOUT FIELD $checkout_vat */
                    $oxygen_customer_id =  $contact_by_vat['data'][0]['id'];
                    OxygenWooSettings::debug( array('------- in else contact vat data are filled -------- ' . $oxygen_customer_id));
                }
            }

        }else if('invoice' === $notetype && ($doc_key === 'alp' || $doc_key === 'apy') ){

            OxygenWooSettings::debug(array( "------- you asked to create an ALP OR APY -------- " ));
            $get_billing_vat_info = self::get_billing_vat_info( $order_id );
            OxygenWooSettings::debug( array('NOT INVOICE get billing vat info --' .json_encode($get_billing_vat_info) ));

            $checkout_email = $order->get_billing_email();

            if(!empty($checkout_email)){

                $contact_by_email = OxygenApi::get_contact_by_email($checkout_email);
                if( empty($contact_by_email['data'])){

                    /* TODO CREATE NEW CONTACT WITH BILLING EMAIL AND VAT AND MAKE THE REST */
                    $new_contact = self::create_new_contact($order, $get_billing_vat_info);
                    $oxygen_customer_id = $new_contact['id'];
                    OxygenWooSettings::debug( array("------- NOT INVOICE new customer id is -------- " . $oxygen_customer_id));

                }else {

                    /* TODO MAKE REST WITH THAT CONTACT AND VAT OF CHECKOUT FIELD $checkout_vat */
                    $oxygen_customer_id =  $contact_by_email['data'][0]['id'];
                    OxygenWooSettings::debug( array("------- NOT INVOICE in else contact vat data are filled -------- " . $oxygen_customer_id));
                }

            }else if(empty($checkout_email)){
                OxygenWooSettings::debug( array("------- empty email on checkout -------- "));

	            $new_contact = self::create_new_contact($order, $get_billing_vat_info);
	            $oxygen_customer_id = $new_contact['id'];
	            OxygenWooSettings::debug( array("------- NOT INVOICE new customer id is -------- " . $oxygen_customer_id));
            }

        }else if('notice' === $notetype){

            OxygenWooSettings::debug( array("------- you asked to create a NOTICE  -------- ") );
            $get_billing_vat_info = self::get_billing_vat_info( $order_id );
            OxygenWooSettings::debug( array('NOTICE get billing vat info from order --' .json_encode($get_billing_vat_info)));

            $checkout_email = $order->get_billing_email();

            if(!empty($get_billing_vat_info['billing_invoice'])){

                $checkout_vat = $get_billing_vat_info['billing_vat'];
                OxygenWooSettings::debug( array('an exw afm sto invoice'));

                if( !empty($checkout_vat) && !empty($checkout_email)){ /* an to checkout vat kai to checkout email einai gemata */

                    OxygenWooSettings::debug( array('an to cehcekout vat einai gemato kai to email psakse thn epafh'));

                    $contact_by_vat = OxygenApi::get_contact_by_vat($checkout_vat); /* psaxnw epafh mesw api me vat */
                    $contact_by_email = OxygenApi::get_contact_by_email($checkout_email); /* psaxnw epafh mesw api me vat */

                    if( empty($contact_by_vat['data']) && empty($contact_by_email['data'])) {

                        OxygenWooSettings::debug( array('an de vreis tipota kane nea'));

                        /* TODO CREATE NEW CONTACT WITH BILLING EMAIL AND VAT AND MAKE THE REST */
                        $new_contact = self::create_new_contact($order, $get_billing_vat_info);
                        $oxygen_customer_id = $new_contact['id'];

                        OxygenWooSettings::debug(array('NOTICE new customer id is ONE'));

                    }else{
                        if(!empty($contact_by_vat['data'])) {

                            OxygenWooSettings::debug( array('an to vat den einai keno pare thn epafh auth'));
                            $oxygen_customer_id =  $contact_by_vat['data'][0]['id'];
                        }else{
                            $oxygen_customer_id =  $contact_by_email['data'][0]['id'];
                            OxygenWooSettings::debug( array('an einai to vat keno pare thn epafh me bash to email'));

                        }
                        OxygenWooSettings::debug( array("------- NOTICE GET CONTACT ID FROM EMAIL OR VAT -------- " . $oxygen_customer_id));
                    }

                }else if(empty($checkout_email)){
	                OxygenWooSettings::debug( array("------- notice empty email on checkout -------- "));

	                $new_contact = self::create_new_contact($order, $get_billing_vat_info);
	                $oxygen_customer_id = $new_contact['id'];
	                OxygenWooSettings::debug( array("------- NOTICE new customer id is -------- " . $oxygen_customer_id));
                }

            }else{

                $contact_by_email = OxygenApi::get_contact_by_email($checkout_email); /* psaxnw epafh mesw api me email */
                if( empty($contact_by_email['data'])) {

                    /* TODO CREATE NEW CONTACT WITH BILLING EMAIL AND VAT AND MAKE THE REST */
                    $new_contact = self::create_new_contact($order, $get_billing_vat_info);
                    $oxygen_customer_id = $new_contact['id'];

                    OxygenWooSettings::debug(array('else NOTICE new customer id is ONE'));

                }else{
                    $oxygen_customer_id =  $contact_by_email['data'][0]['id'];
                    OxygenWooSettings::debug( array("------- else NOTICE GET CONTACT ID FROM EMAIL OR VAT -------- " . $oxygen_customer_id));
                }
            }
        }

		if ( 'notice' !== $notetype ) {
			if ( ( 'tpda' === $doc_key || 'tpy' === $doc_key ) && false === $get_billing_vat_info ) {

				$log = array( '----------------- Invalid Payment Note Type ' . gmdate( 'Y-m-d H:i:s' ) . ' -----------------', array( $order_id, __( 'Invoice details are missing or incomplete', 'oxygen' ) ) );
				OxygenWooSettings::debug( $log );

				WC_Admin_Notices::add_custom_notice( 'oxygen_invoice_info_missing', '<p>' . __( 'Invoice details are missing or incomplete', 'oxygen' ) . '</p>' );

				return false;

			}
		}

        /* check if order's language is checked on settings then print invoice in order's language , else on selected lang */
        $wc_order_language = get_checkout_language($order_id);
		OxygenWooSettings::debug( array('------ this is the language in site ',$wc_order_language) );

        $infobox_lang = 'Order No ';
		$shipping_lang = 'Shipping: ';
		$language_to_print  = get_option( 'oxygen_language' );
        if($language_to_print  === 'order_lang'){
            if($wc_order_language === 'el'){
                $language_to_print = 'EL';
	            $infobox_lang = 'Αριθμός παραγγελίας ';
	            $shipping_lang = 'Μεταφορικά: ';
            }else{
                $language_to_print = 'EN';
            }
        }

        if ( 'notice' === $notetype ) {

			$doc_key = 'notice';

			$args = array_filter(
				array(
					'numbering_sequence_id' => OxygenWooSettings::get_option( 'oxygen_num_sequence' . $doc_key ),
					'issue_date'            => wp_date( 'Y-m-d' ),
					'expire_date'           => wp_date( 'Y-m-d', strtotime( '+1 month' ) ),
					'contact_id'            => $oxygen_customer_id,
					'is_paid'               => ( OxygenWooSettings::get_option( 'oxygen_is_paid' ) === 'yes' ? true : false ),
					'language'              => $language_to_print,
					'logo_id'               => ( ! empty( OxygenWooSettings::get_option( 'oxygen_logo' ) ) ? OxygenWooSettings::get_option( 'oxygen_logo' ) : OxygenWooSettings::get_default_logo_id() ),
					/* translators: %s: order ID string */
					'infobox'               => $infobox_lang. sprintf(' %s' , $order_id),
				)
			);


            $log = array( '----------------- if customer id is ' . $oxygen_customer_id . ' -----------------');
            OxygenWooSettings::debug( $log, 'info' );

		} else {

			$args = array_filter(
				array(
					'numbering_sequence_id' => OxygenWooSettings::get_option( 'oxygen_num_sequence' . $doc_key ),
					'issue_date'            => wp_date( 'Y-m-d' ),
					'document_type'         => $document_types[ $doc_key ], // p or rp if physical product exists.
					'mydata_document_type'  => $mydata_types[ $doc_key ],
					'payment_method_id'     => $payment_methods[ $order->get_payment_method() ],
					'contact_id'            => $oxygen_customer_id,
					'is_paid'               => ( OxygenWooSettings::get_option( 'oxygen_is_paid' ) === 'yes' ? true : false ),
					'language'              => $language_to_print,
					'logo_id'               => ( ! empty( OxygenWooSettings::get_option( 'oxygen_logo' ) ) ? OxygenWooSettings::get_option( 'oxygen_logo' ) : OxygenWooSettings::get_default_logo_id() ),
					/* translators: %s: order ID string */
					'infobox'               => $infobox_lang. sprintf(' %s' , $order_id),
				)
			);


            $log = array( '-----------------else customer id is ' . $oxygen_customer_id . ' -----------------');
            OxygenWooSettings::debug( $log, 'info' );
		}

		$oxygen_taxes = get_option( 'oxygen_taxes' );

		$items = $order->get_items();

		foreach ( $items as $item_id => $item ) {

			$taxes = $item->get_taxes();

			$item_rate_id = false;

			foreach ( $taxes['total'] as $rate_id => $amount ) {

				if ( ! empty( $amount ) ) {

					$item_rate_id = $rate_id;
					break;
				}
			}

			if ( 'notice' === $notetype ) {
				$get_product_mydata_info = self::get_product_mydata_receipt_info( $item->get_product_id() );
			} else {
				$get_product_mydata_info = self::get_product_mydata_info( $item->get_product_id() );
			}

            $product_variation_id = $item['variation_id'];

            /* fix variation code sku if exist else use parent -- if there isn't variation product sku in app pelatologio everything is null */
            /* ---SOS--- if the SKU !== product_code_pelatologio (maybe wrong), there is any check for now */
            if ( !empty( $product_variation_id ) && $product_variation_id !== 0) {
                $item_product = wc_get_product( $product_variation_id );
                $log = array( '----------------- item with variation id-----------------', json_encode( $item_product ) );
                OxygenWooSettings::debug( $log, 'debug' );
            } else {
                $item_product = wc_get_product( $item->get_product_id() );
                $log = array( '----------------- item without variation -----------------', json_encode( $item_product ));
                OxygenWooSettings::debug( $log, 'debug' );
            }

			$args['items'][] = array(
				'code'                           => $item_product->get_sku(),
				'description'                    => strip_tags($item->get_name()), /* strip any HTML tags */
				'quantity'                       => $item->get_quantity(),
				'unit_net_value'                 => round( ( $item->get_total() / $item->get_quantity() ), 2 ),
				'tax_id'                         => $oxygen_taxes[ $rate_id ],
				'vat_amount'                     => round( $item->get_total_tax(), 2 ),
				'net_amount'                     => round( $item->get_total(), 2 ),
				'mydata_classification_category' => ( is_array( $get_product_mydata_info[ 'mydata_category' . $class_cat_subfix ] ) ? $get_product_mydata_info[ 'mydata_category' . $class_cat_subfix ][0] : $get_product_mydata_info[ 'mydata_category' . $class_cat_subfix ] ),
				'mydata_classification_type'     => ( is_array( $get_product_mydata_info[ 'mydata_classification_type' . $class_cat_subfix ] ) ? $get_product_mydata_info[ 'mydata_classification_type' . $class_cat_subfix ][0] : $get_product_mydata_info[ 'mydata_classification_type' . $class_cat_subfix ] ),
			);
		}

		$items = $order->get_items( array( 'shipping' ) );

		foreach ( $items as $item_id => $item ) {

			if ( 0 === floatval( $item->get_total() ) ) {
				continue;
			}

			$taxes = $item->get_taxes();

			$item_rate_id = false;

			foreach ( $taxes['total'] as $rate_id => $amount ) {

				if ( ! empty( $amount ) ) {

					$item_rate_id = $rate_id;
					break;
				}
			}

			$get_item_mydata_info = $item->get_data();

			$oxygen_shipping_code = self::clean( str_replace( 'wc-', '', OxygenWooSettings::get_option( 'oxygen_shipping_code' ) ) );

			$args['items'][] = array(
				'code'                           => ( ! empty( $oxygen_shipping_code ) ? $oxygen_shipping_code : 'shipping' ),
				'description'                    => $shipping_lang . $item->get_name(),
				'quantity'                       => $item->get_quantity(),
				'unit_net_value'                 => round( ( $item->get_total() / $item->get_quantity() ), 2 ),
				'tax_id'                         => $oxygen_taxes[ $rate_id ],
				'vat_amount'                     => round( $item->get_total_tax(), 2 ),
				'net_amount'                     => round( $item->get_total(), 2 ),
				'mydata_classification_category' => 'category1_5',
				'mydata_classification_type'     => 'E3_562',
			);

		}

		$items = $order->get_items( array( 'fee' ) );

		foreach ( $items as $item_id => $item ) {

			$taxes = $item->get_taxes();

			$item_rate_id = false;

			foreach ( $taxes['total'] as $rate_id => $amount ) {

				if ( ! empty( $amount ) ) {

					$item_rate_id = $rate_id;
					break;
				}
			}

			$get_item_mydata_info = $item->get_data();

			if ( 'notice' === $notetype ) {
				$get_product_mydata_info = array(
					'mydata_category'            => get_option( 'mydata_category_receipt' ),
					'mydata_classification_type' => get_option( 'mydata_classification_type_receipt' ),
				);
			} else {
				$get_product_mydata_info = array(
					'mydata_category'                    => get_option( 'mydata_category' ),
					'mydata_classification_type'         => get_option( 'mydata_classification_type' ),
					'mydata_category_receipt'            => get_option( 'mydata_category_receipt' ),
					'mydata_classification_type_receipt' => get_option( 'mydata_classification_type_receipt' ),
				);
			}

			if ( $item->get_total() < 0 ) {
				$args['items'][] = array(
					'code'                           => null,
					'description'                    => $item->get_name(),
					'quantity'                       => $item->get_quantity(),
					'unit_net_value'                 => round( ( $item->get_total() / $item->get_quantity() ), 2 ),
					'tax_id'                         => $oxygen_taxes[ $rate_id ],
					'vat_amount'                     => round( $item->get_total_tax(), 2 ),
					'net_amount'                     => round( $item->get_total(), 2 ),
					'mydata_classification_category' => ( is_array( $get_product_mydata_info[ 'mydata_category' . $class_cat_subfix ] ) ? $get_product_mydata_info[ 'mydata_category' . $class_cat_subfix ][0] : $get_product_mydata_info[ 'mydata_category' . $class_cat_subfix ] ),
					'mydata_classification_type'     => ( is_array( $get_product_mydata_info[ 'mydata_classification_type' . $class_cat_subfix ] ) ? $get_product_mydata_info[ 'mydata_classification_type' . $class_cat_subfix ][0] : $get_product_mydata_info[ 'mydata_classification_type' . $class_cat_subfix ] ),
				);
			} else {

				$args['items'][] = array(
					'code'                           => null,
					'description'                    => $item->get_name(),
					'quantity'                       => $item->get_quantity(),
					'unit_net_value'                 => round( ( $item->get_total() / $item->get_quantity() ), 2 ),
					'tax_id'                         => $oxygen_taxes[ $rate_id ],
					'vat_amount'                     => round( $item->get_total_tax(), 2 ),
					'net_amount'                     => round( $item->get_total(), 2 ),
					'mydata_classification_category' => 'category1_5',
					'mydata_classification_type'     => 'E3_562',
				);
			}
		}

		$log = array( '----------------- ' . $notetype . ' args log -----------------', $args, $order_id );
		OxygenWooSettings::debug( $log, 'debug' );

		if ( 'notice' === $notetype ) {

			$result = OxygenApi::add_notice( $args );

		} else {

			$result = OxygenApi::add_invoice( $args );

            $log = array( '----------------- Oxygen invoice creating for order ' . $order_id . ' -----------------', $result, $order_id );
			OxygenWooSettings::debug( $log, 'info' );

		}

		if ( ! array( $result ) ) {

			$log = array( '----------------- results not array -----------------', $result, $order_id );
			OxygenWooSettings::debug( $log );

		}

		if ( is_array( $result ) && isset( $result['body'] ) ) {
            if ( is_wp_error( $result ) ) {
                $log = array( '----------------- results wp error from api -----------------', $result, $order_id );
                OxygenWooSettings::debug( $log );
            } else {
                $add_invoice = json_decode($result['body'], true);
            }
		} else {
            if ( is_wp_error( $result ) ) {
                $log = array( '----------------- results wp error from api else -----------------', $result, $order_id );
                OxygenWooSettings::debug( $log );
            } else {
                $add_invoice = json_decode($result, true);
            }
		}

		if ( is_array( $add_invoice ) && isset( $add_invoice['id'] ) ) {

			if ( 'notice' === $notetype ) {

				$order->update_meta_data( '_oxygen_notice', $add_invoice );
				WC_Admin_Notices::add_custom_notice( 'oxygen_notice_success', '<p>' . __( 'Notice Created', 'oxygen' ) . '</p>' );

			} else {

				$order->update_meta_data( '_oxygen_invoice', $add_invoice );

				$upload_dir = wp_upload_dir();
				$oxyge_path = $upload_dir['basedir'] . '/oxygen';
				if ( ! is_dir( $oxyge_path ) ) {
					wp_mkdir_p( $oxyge_path );
				}

				$pdf_path = $upload_dir['basedir'] . '/oxygen/' . $add_invoice['id'] . '.pdf';
				$pdf_url  = $upload_dir['baseurl'] . '/oxygen/' . $add_invoice['id'] . '.pdf';

                $print_type = 'a4';
				if('invoice' === $notetype && ($doc_key === 'alp' || $doc_key === 'apy')){
					$print_type =  get_option( 'oxygen_receipt_print_type' );
				}
				$oxygen_pdf = OxygenApi::get_invoice_pdf( $add_invoice['id'] , $print_type);

				$file_put = false;

				if ( is_array( $oxygen_pdf ) && isset( $oxygen_pdf['body'] ) ) {
					$file_put = file_put_contents( $pdf_path, $oxygen_pdf['body'] );
				}

				if ( $file_put ) {

					$order->update_meta_data( '_oxygen_invoice_pdf', $pdf_url );
					$order->update_meta_data( '_oxygen_invoice_pdf_path', $pdf_path );
					WC_Admin_Notices::add_custom_notice( 'oxygen_invoice_success', '<p>' . __( 'Invoice Created', 'oxygen' ) . '</p>' );

				} else {

					WC_Admin_Notices::add_custom_notice( 'oxygen_invoice_error', '<p>' . __( 'Could not save PDF invoice file in ', 'oxygen' ) . $pdf_path . '</p>' );

				}

			}

			remove_action( 'woocommerce_after_order_object_save', array( $this, 'save_order' ), 20 );
			$order->save_meta_data();
			$order->save();
			add_action( 'woocommerce_after_order_object_save', array( $this, 'save_order' ), 20, 1 );

			if ( ! isset( $_GET['oxygen'] ) ) {
				return;
			}
		} else {

			$errors = array();

			if ( isset( $add_invoice['errors'] ) ) {

				foreach ( $add_invoice['errors'] as $error ) {

					$errors[] = implode( ',', $error );

				}
			} else {

				$errors = $add_invoice;

			}

			if ( 'notice' === $notetype ) {

				WC_Admin_Notices::add_custom_notice( 'oxygen_invoice_error', '<p>' . __( 'Could not create notice', 'oxygen' ) . ' | ' . implode( ',', $errors ) . '</p>' );
			} else {

				WC_Admin_Notices::add_custom_notice( 'oxygen_invoice_error', '<p>' . __( 'Could not create invoice', 'oxygen' ) . ' | ' . implode( ',', $errors ) . '</p>' );
			}

			$log = array( '----------------- ' . $notetype . ' error -----------------', $args, $add_invoice );
			OxygenWooSettings::debug( $log );
		}

		remove_action( 'woocommerce_after_order_object_save', array( $this, 'save_order' ), 20 );
		$order->save_meta_data();
		$order->save();
		add_action( 'woocommerce_after_order_object_save', array( $this, 'save_order' ), 20, 1 );

		if ( is_admin() && current_user_can( 'manage_woocommerce' ) ) {

			wp_safe_redirect( get_admin_url() . 'post.php?post=' . $order_id . '&action=edit' );
			die;
		}
	}

	/**
	 *  Get the customer billing VAT data by order ID.
	 *
	 *  @param array $order_id the WC order ID.
	 *  @return array|false
	 */
	public static function get_billing_vat_info( $order_id ) {

		$billing_vat        = false;
		$billing_job        = false;
		$billing_tax_office = false;
		$billing_invoice    = false;
		$billing_company    = false;

		$order = wc_get_order( $order_id );

        if ( ! $order_id ) {
            OxygenWooSettings::debug( 'Invalid order ID' );
            return false;
        }else{
            OxygenWooSettings::debug( array('order id is ' . $order_id) );
        }

        $log = array( '----------------- billing invoice is -----------------', $order->get_meta( '_billing_invoice', true ));
        OxygenWooSettings::debug( $log );

		if ( 'yes' === get_option( 'oxygen_self_fields' ) ) {

			$billing_vat        = $order->get_meta( '_billing_vat', true );
			$billing_job        = $order->get_meta( '_billing_job', true );
			$billing_tax_office = $order->get_meta( '_billing_tax_office', true );
			$billing_invoice    = $order->get_meta( '_billing_invoice', true );
			$billing_company    = $order->get_billing_company();

		} else {

			$oxygen_vat_metakey           = get_option( 'oxygen_vat_metakey' );
			$oxygen_working_field_metakey = get_option( 'oxygen_working_field_metakey' );
			$oxygen_tax_office            = get_option( 'oxygen_tax_office' );
			$oxygen_issue_invoice_metakey = get_option( 'oxygen_issue_invoice_metakey' );

			$billing_vat        = $order->get_meta( $oxygen_vat_metakey, true );
			$billing_job        = $order->get_meta( $oxygen_working_field_metakey, true );
			$billing_tax_office = $order->get_meta( $oxygen_tax_office, true );
			$billing_invoice    = $order->get_meta( $oxygen_issue_invoice_metakey, true );
			$billing_company    = $order->get_billing_company();

		}

		if ( empty( $billing_vat ) ) {
			$billing_vat = $order->get_meta( '_billing_vat', true );
		}
		if ( empty( $billing_job ) ) {
			$billing_job = $order->get_meta( '_billing_job', true );
		}
		if ( empty( $billing_tax_office ) ) {
			$billing_tax_office = $order->get_meta( '_billing_tax_office', true );
		}
		if ( empty( $billing_invoice ) ) {
			$billing_invoice = $order->get_meta( 'billing_invoice', true );
		}

		if ( $billing_invoice == 1 && ! empty( $billing_vat ) && ! empty( $billing_job ) && ! empty( $billing_invoice ) && ! empty( $billing_company ) ) {

			$info = array(
				'billing_vat'        => $billing_vat,
				'billing_job'        => $billing_job,
				'billing_tax_office' => $billing_tax_office,
				'billing_invoice'    => $billing_invoice,
				'billing_company'    => $billing_company,
			);

            $log = array( '----------------- TPDA OR TPY -----------------', $info );
            OxygenWooSettings::debug( $log );

			return $info;

		} else {

            if($billing_invoice == 0 || $billing_invoice == ''){
                $info = array(
                    'billing_vat'        => '',
                    'billing_job'        => '',
                    'billing_tax_office' => '',
                    'billing_invoice'    => 0,
                    'billing_company'    => '',
                );

                $log = array( '----------------- ALP OR APY -----------------', $info );
                OxygenWooSettings::debug( $log );

                return $info;
            }else{
                $info = array(
                    'billing_vat'        => $billing_vat,
                    'billing_job'        => $billing_job,
                    'billing_tax_office' => $billing_tax_office,
                    'billing_invoice'    => $billing_invoice,
                    'billing_company'    => $billing_company,
                );

                if ( empty( $billing_vat ) || empty( $billing_job ) || empty( $billing_tax_office ) || empty( $billing_invoice ) || empty( $billing_company ) ) {

                    $log = array( '----------------- Missing VAT info -----------------', $info );
                    OxygenWooSettings::debug( $log );
                }
            }
		}

		return false;
	}

    /**
     *  Create new contact
     *
     *  @param object $order
     *  @param array|bool $get_billing_vat_info
     *  @return array|boolean
     */
    public static function create_new_contact( object $order , $get_billing_vat_info)
    {

        $log = array( '----------------- START CREATING NEW CONTACT -----------------' );
        OxygenWooSettings::debug( $log );

        /* TODO CREATE NEW CONTACT */
        $billing_invoice = get_post_meta( $order->get_id(), '_billing_invoice', true );
        $billing_vat        = false;
        $billing_job        = false;
        $billing_tax_office = false;
        $customer_type      = 1;

        if ( $get_billing_vat_info ) {

            $billing_vat        = $get_billing_vat_info['billing_vat'];
            $billing_job        = $get_billing_vat_info['billing_job'];
            $billing_tax_office = $get_billing_vat_info['billing_tax_office'];
            $billing_invoice    = $get_billing_vat_info['billing_invoice'];

            // set customer type to 2 ONLY if all values are set.
            if ( !empty($billing_vat) && !empty( $order->get_billing_company() ) ) {

                $log = array( '---- billing vat & billing company is ----',$billing_vat ,$order->get_billing_company());
                OxygenWooSettings::debug( $log );
                $customer_type = 2;
            }
        }

        $contact_args = array_filter(
            array(
                'code'         => '',
                'type'         => $customer_type,
                'is_client'    => true,
                'name'         => $order->get_billing_first_name(),
                'surname'      => $order->get_billing_last_name(),
                'company_name' => $billing_invoice === '1' ? $order->get_billing_company() : '',
                'profession'   => $billing_job,
                'vat_number'   => $billing_vat,
                'tax_office'   => $billing_tax_office,
                'telephone'    => $order->get_billing_phone(),
                'mobile'       => $order->get_billing_phone(),
                'email'        => $order->get_billing_email(),
                'street'       => str_replace( '  ', ' ', preg_replace( '/\d+/', '', $order->get_billing_address_1() ) ) . ( ! empty( $order->get_billing_address_2() ) ? ', ' . $order->get_billing_address_2() : '' ),
                'number'       => intval( ( preg_replace( '/[^0-9]/', '', $order->get_billing_address_1() ) ? preg_replace( '/[^0-9]/', '', $order->get_billing_address_1() ) : 0 ) ),
                'city'         => $order->get_billing_city(),
                'zip_code'     => $order->get_billing_postcode(),
                'country'      => $order->get_billing_country(),
            )
        );

        if ( empty( $billing_vat ) ) {
            unset( $contact_args['vat_number'] );
        }

        $log = array( '----------------- NEW CONTACT -----------------', $contact_args );
        OxygenWooSettings::debug( $log );

        $contact_args['is_supplier'] = false;
        $contact =  json_decode(OxygenApi::add_contact( $contact_args ) ,true);

        $log = array( '----------------- CONTACT IS -----------------', $contact );
        OxygenWooSettings::debug( $log );

        if ( !is_array( $contact ) || !isset( $contact['id'] ) ) {

            WC_Admin_Notices::add_custom_notice( 'oxygen_contact_error', '<p>' . __( 'Could not create contact', 'oxygen' ).'</p>' );
            return false;
        }

        return $contact;

    }

	/**
	 *  Create customer billing address extra fields.
	 *
	 *  @param array $fields customer billing address data.
	 *  @return array
	 */
	public function override_checkout_fields( $fields ) {

		$fields['billing']['billing_vat']        = array(
			'type'        => 'text',
			'label'       => __( 'VAT #', 'oxygen' ),
			'placeholder' => __( 'ex. EL1234567890', 'oxygen' ),
			'priority'    => 160,
			'class'       => array('custom-vat-field')

		);
		$fields['billing']['billing_job']        = array(
			'type'        => 'text',
			'label'       => __( 'Job Description Field', 'oxygen' ),
			'placeholder' => __( 'ex. Accountant', 'oxygen' ),
			'priority'    => 161,
		);
		$fields['billing']['billing_tax_office'] = array(
			'type'        => 'text',
			'label'       => __( 'Tax Office', 'oxygen' ),
			'placeholder' => __( 'ex. D', 'oxygen' ),
			'priority'    => 162,
		);
		$fields['billing']['billing_invoice']    = array(
			'type'     => 'checkbox',
			'label'    => __( 'I need an invoice', 'oxygen' ),
			'id'       => 'billing_invoice',
			'priority' => 159,
		);

		$fields['billing']['billing_company']['priority'] = 163;

		return $fields;

	}
	/**
	 *  Validate customer billing address extra fields.
	 *
	 *  @return void
	 */
	public function validate_checkout_fields() {

		if ( isset( $_POST['billing_invoice'] ) && isset( $_POST['billing_vat'] ) && empty( $_POST['billing_vat'] ) ) { // phpcs:ignore
			wc_add_notice( __( 'Please fill VAT ID', 'oxygen' ), 'error' );
		}
		if ( isset( $_POST['billing_invoice'] ) && isset( $_POST['billing_job'] ) && empty( $_POST['billing_job'] ) ) { // phpcs:ignore
			wc_add_notice( __( 'Please fill billing job', 'oxygen' ), 'error' );
		}
		if ( isset( $_POST['billing_invoice'] ) && isset( $_POST['billing_tax_office'] ) && empty( $_POST['billing_tax_office'] ) ) { // phpcs:ignore
			wc_add_notice( __( 'Please fill billing tax office', 'oxygen' ), 'error' );
		}
		if ( isset( $_POST['billing_invoice'] ) && isset( $_POST['billing_company'] ) && empty( $_POST['billing_company'] ) ) { // phpcs:ignore
			wc_add_notice( __( 'Please fill the billing company name', 'oxygen' ), 'error' );
		}

	}

	/**
	 *  Create customer billing address extra fields.
	 *
	 *  @param array  $address customer billing address fields data.
	 *  @param string $load_address address type.
	 *  @return array
	 */
	public function oxygen_address_to_edit( $address, $load_address ) {
		global $wp_query;

		if ( isset( $wp_query->query_vars['edit-address'] ) && 'billing' !== $wp_query->query_vars['edit-address'] ) {
			return $address;
		}

		if ( ! isset( $address['billing_vat'] ) ) {
			$address['billing_vat'] = array(
				'label'       => __( 'VAT #', 'oxygen' ),
				'placeholder' => _x( 'VAT #', 'placeholder', 'oxygen' ),
				'required'    => false,
				'class'       => array( 'form-row-first' ),
				'value'       => sanitize_text_field( get_user_meta( get_current_user_id(), 'billing_vat', true ) ),
			);
		}

		if ( ! isset( $address['billing_job'] ) ) {
			$address['billing_job'] = array(
				'label'       => __( 'Job Description Field', 'oxygen' ),
				'placeholder' => _x( 'Job Description Field', 'placeholder', 'oxygen' ),
				'required'    => false,
				'class'       => array( 'form-row-last' ),
				'value'       => sanitize_text_field( get_user_meta( get_current_user_id(), 'billing_job', true ) ),
			);
		}
		if ( ! isset( $address['billing_tax_office'] ) ) {
			$address['billing_tax_office'] = array(
				'label'       => __( 'Tax Office', 'oxygen' ),
				'placeholder' => _x( 'Tax Office', 'placeholder', 'oxygen' ),
				'required'    => false,
				'class'       => array( 'form-row-first' ),
				'value'       => sanitize_text_field( get_user_meta( get_current_user_id(), 'billing_tax_office', true ) ),
			);
		}

		if ( ! isset( $address['billing_invoice'] ) ) {
			$address['billing_invoice'] = array(
				'label'       => __( 'Issue invoice', 'oxygen' ),
				'placeholder' => _x( 'Issue invoice', 'placeholder', 'oxygen' ),
				'required'    => false,
				'class'       => array( 'form-row-last' ),
				'value'       => sanitize_text_field( get_user_meta( get_current_user_id(), 'billing_invoice', true ) ),
				'type'        => 'checkbox',
			);
		}

		return $address;
	}

	/**
	 *  Create customer billing address extra fields.
	 *
	 *  @param array  $fields customer meta data array.
	 *  @param object $order WC_Order.
	 *  @return array
	 */
	public function oxygen_order_formatted_billing_address( $fields, $order ) {
		$fields['billing_vat']        = sanitize_text_field( $order->get_meta( '_billing_vat', true ) );
		$fields['billing_job']        = sanitize_text_field( $order->get_meta( '_billing_job', true ) );
		$fields['billing_tax_office'] = sanitize_text_field( $order->get_meta( '_billing_tax_office', true ) );
		$fields['billing_invoice']    = sanitize_text_field( $order->get_meta( '_billing_invoice', true ) );

		return $fields;
	}


	/**
	 *  Adding new customer billing fields to the order.
	 *
	 *  @param array  $fields customer meta data array.
	 *  @param int    $customer_id order user ID.
	 *  @param string $type address type.
	 *  @return array
	 */
	public function oxygen_my_account_my_address_formatted_address( $fields, $customer_id, $type ) {

		if ( 'billing' === $type ) {
			$fields['vat']        = sanitize_text_field( get_user_meta( $customer_id, 'billing_vat', true ) );
			$fields['job']        = sanitize_text_field( get_user_meta( $customer_id, 'billing_job', true ) );
			$fields['tax_office'] = sanitize_text_field( get_user_meta( $customer_id, 'billing_tax_office', true ) );
			$fields['invoice']    = sanitize_text_field( get_user_meta( $customer_id, 'billing_invoice', true ) );
		}

		return $fields;
	}

	/**
	 *  Adding new customer billing fields to the order.
	 *
	 *  @param array $address customer address data.
	 *  @param array $args customer address data.
	 *  @return array
	 */
	public function oxygen_formatted_address_replacements( $address, $args ) {

		$address['{vat}']        = '';
		$address['{job}']        = '';
		$address['{tax_office}'] = '';
		$address['{invoice}']    = '';

		if ( ! empty( $args['vat'] ) ) {
			$address['{vat}'] = __( 'VAT #', 'oxygen' ) . ' ' . strtoupper( $args['vat'] );
		}
		if ( ! empty( $args['job'] ) ) {
			$address['{job}'] = __( 'Job Description Field', 'oxygen' ) . ' ' . strtoupper( $args['job'] );
		}
		if ( ! empty( $args['tax_office'] ) ) {
			$address['{tax_office}'] = __( 'Tax office', 'oxygen' ) . ' ' . strtoupper( $args['tax_office'] );
		}
		if ( ! empty( $args['invoice'] ) ) {
			$address['{invoice}'] = __( 'Issue invoice', 'oxygen' ) . ' ' . strtoupper( $args['invoice'] );
		}

		return $address;
	}

	/**
	 *  Adding new customer billing fields to the order.
	 *
	 *  @param array $fields customer meta data array.
	 *  @return array
	 */
	public function oxygen_admin_billing_fields( $fields ) {

		$fields['vat']        = array(
			'label' => __( 'VAT #', 'oxygen' ),
			'show'  => true,
		);
		$fields['job']        = array(
			'label' => __( 'Job Description Field', 'oxygen' ),
			'show'  => true,
		);
		$fields['tax_office'] = array(
			'label' => __( 'Tax office', 'oxygen' ),
			'show'  => true,
		);
		$fields['invoice']    = array(
			'label'             => __( 'Issue invoice', 'oxygen' ),
			'show'              => true,
			'type'              => 'number',
			'description'       => __( '1 is on, 0 is off', 'oxygen' ),
			'custom_attributes' => array(
				'min' => 0,
				'max' => 1,
			),
		);

		return $fields;
	}

	/**
	 *  Fetching customer meta data fields from the order.
	 *
	 *  @param array  $customer_data customer meta data array.
	 *  @param object $customer WC_Customer.
	 *  @param int    $user_id the user ID.
	 *  @return array
	 */
	public function oxygen_found_customer_details( $customer_data, $customer, $user_id ) {

		$customer_data['billing_vat']        = sanitize_text_field( get_user_meta( $user_id, 'billing_vat', true ) );
		$customer_data['billing_job']        = sanitize_text_field( get_user_meta( $user_id, 'billing_job', true ) );
		$customer_data['billing_tax_office'] = sanitize_text_field( get_user_meta( $user_id, 'billing_tax_office', true ) );
		$customer_data['billing_invoice']    = sanitize_text_field( get_user_meta( $user_id, 'billing_invoice', true ) );

		$customer_data['billing']['vat']        = $customer_data['billing_vat'];
		$customer_data['billing']['job']        = $customer_data['billing_job'];
		$customer_data['billing']['tax_office'] = $customer_data['billing_tax_office'];
		$customer_data['billing']['invoice']    = ( empty( $customer_data['billing_invoice'] ) ? 0 : $customer_data['billing_invoice'] );

		return $customer_data;
	}

	/**
	 *  Adding new customer meta data fields to the order.
	 *
	 *  @param array $fields customer meta data array.
	 *  @return array
	 */
	public function oxygen_customer_meta_fields( $fields ) {
		$fields['billing']['fields']['billing_vat'] = array(
			'label'       => __( 'VAT #', 'oxygen' ),
			'description' => '',
		);

		$fields['billing']['fields']['billing_job'] = array(
			'label'       => __( 'Job Description Field', 'oxygen' ),
			'description' => '',
		);

		$fields['billing']['fields']['billing_tax_office'] = array(
			'label'       => __( 'Tax Office', 'oxygen' ),
			'description' => '',
		);

		$fields['billing']['fields']['billing_invoice'] = array(
			'label'       => __( 'Issue invoice', 'oxygen' ),
			'description' => '',
			'class'       => '',
			'type'        => 'checkbox',
		);

		return $fields;
	}

	/**
	 *  Adding 1 new column with their titles (keeping "Total" and "Actions" columns at the end).
	 *
	 *  @param array $columns the order list admin columns array.
	 *  @return array
	 */
	public function shop_order_column( $columns ) {

		$reordered_columns = array();

		// Inserting columns to a specific location.
		foreach ( $columns as $key => $column ) {
			$reordered_columns[ $key ] = $column;
			if ( 'order_status' === $key ) {
				// Inserting after "Status" column.
				$reordered_columns['oxygen'] = __( 'Oxygen', 'oxygen' );
			}
		}
		return $reordered_columns;
	}

	/**
	 *  Adding custom fields meta data on new column(s)
	 *
	 *  @param string $column name of the column.
	 *  @param int    $post_id post ID.
	 *  @return void
	 */
	public function orders_list_column_content( $column, $post_id ) {

		$order = wc_get_order( $post_id );

		switch ( $column ) {
			case 'oxygen':
				// Get custom post meta data.
				$invoice_data = $order->get_meta( '_oxygen_invoice', true );
				$notice_data  = $order->get_meta( '_oxygen_notice', true );
				$pdf          = $order->get_meta( '_oxygen_invoice_pdf', true );
				$note_type    = $order->get_meta( '_oxygen_payment_note_type', true );

				if ( isset( $invoice_data['iview_url'] ) && ! empty( $invoice_data['iview_url'] ) ) {
					?>
					<div>
						<p>
						<a href="<?php echo esc_url( $invoice_data['iview_url'] ); ?>" target="_blank"><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'View Invoice', 'oxygen' ); ?></a> /
						<a href="<?php echo esc_url( $pdf ); ?>" target="_blank"><span class="dashicons dashicons-pdf"></span> <?php esc_html_e( 'PDF Download', 'oxygen' ); ?></a> /
						<?php echo '#<a href="' . esc_url( $invoice_data['iview_url'] ) . '" target="_blank">' . esc_html( $invoice_data['sequence'] . $invoice_data['number'] ) . '</a></strong>'; ?><br />
						<?php
						if ( 'apy' === $note_type ) {
							echo '<span class="wp-ui-notification" style="padding: 3px 10px; margin-top: 2px; display: inline-block; border-radius: 3px;">' . esc_html( __( 'Receipt', 'oxygen' ) ) . '</span>';
						} else {
							echo '<span class="wp-ui-highlight" style="padding: 3px 10px; margin-top: 2px; display: inline-block; border-radius: 3px;">' . esc_html( __( 'Invoice', 'oxygen' ) ) . '</span>';
						}
						?>
						</p>
					</div>
					<?php
				}
				if ( isset( $notice_data['iview_url'] ) && ! empty( $notice_data['iview_url'] ) ) {
					?>
					<div>
						<p>
						<a href="<?php echo esc_url( $notice_data['iview_url'] ); ?>" target="_blank"><span class="dashicons dashicons-media-document"></span> <?php esc_html_e( 'View Notice', 'oxygen' ); ?></a><br />
						<span class="wp-ui-primary" style="padding: 3px 10px; margin-top: 2px; display: inline-block; border-radius: 3px;"><?php echo esc_html( __( 'Notice', 'oxygen' ) ); ?></span>
						</p>
					</div>
					<?php
				}

				break;
		}
	}

	/**
	 *  On order create actions
	 *
	 *  @param int $order_id the ID of the order.
	 *  @return void
	 */
	public function on_order_create( $order_id ) {

		$order = wc_get_order( $order_id );

		$oxygen_default_receipt_doctype = get_option( 'oxygen_default_receipt_doctype' );
		$oxygen_default_invoice_doctype = get_option( 'oxygen_default_invoice_doctype' );

		$get_billing_vat_info = self::get_billing_vat_info( $order_id );

        $log = array( '----------------- on order create -----------------', json_encode($get_billing_vat_info) );
        OxygenWooSettings::debug( $log );

		if ( false !== $get_billing_vat_info && ! empty( $order->get_billing_company() ) && ! empty( $oxygen_default_invoice_doctype ) ) {

			$order->update_meta_data( '_oxygen_payment_note_type', $oxygen_default_receipt_doctype );
		}
		if ( ! empty( $oxygen_default_invoice_doctype ) ) {

			$order->update_meta_data( '_oxygen_payment_note_type', $oxygen_default_invoice_doctype );
		}

		remove_action( 'woocommerce_after_order_object_save', array( $this, 'save_order' ), 20 );
		$order->save_meta_data();
		$order->save();
		add_action( 'woocommerce_after_order_object_save', array( $this, 'save_order' ), 20, 1 );

	}

	/**
	 *  On order thankyou actions
	 *
	 *  @param int $order_id the ID of the order.
	 *  @return void
	 */
	public function on_order_thankyou( $order_id ) {

		// Disable WP Obj Caching ...
		wp_using_ext_object_cache( false );
		wp_cache_flush();
		wp_cache_init();

		$order = wc_get_order( $order_id );

		$oxygen_order_status = str_replace( 'wc-', '', OxygenWooSettings::get_option( 'oxygen_order_status' ) );
		$_oxygen_invoice     = $order->get_meta( '_oxygen_invoice', true );

		if ( empty( $_oxygen_invoice ) ) {

			if ( $order->get_status() === $oxygen_order_status ) {

				$oxygen_default_document_type      = OxygenWooSettings::get_option( 'oxygen_default_document_type' );
				$_GET['notetype']                  = ( ! empty( $oxygen_default_document_type ) ? $oxygen_default_document_type : 'invoice' ); // default to invoice.
				$_GET['_oxygen_payment_note_type'] = $order->get_meta( '_oxygen_payment_note_type', true );

				$this->create_invoice( $order_id, $order );
			}
		}

	}

	/**
	 *  On order save actions
	 *
	 *  @param object $order WC_Order.
	 *  @return void
	 */
	public function save_order( $order ) {

		if ( ! is_admin() ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['oxygen_nonce'] ) ) {
			return;
		}

		$post_id = $order->get_id();

		if ( ! wp_verify_nonce( sanitize_key( $_POST['oxygen_nonce'] ), 'oxygen-' . $post_id . '-nonce', 'oxygen_nonce' ) ) {
			return;
		}

		// Disable WP Obj Caching ...
		wp_using_ext_object_cache( false );
		wp_cache_flush();
		wp_cache_init();

		if ( isset( $_POST['_oxygen_payment_note_type'] ) ) {

			$order->update_meta_data( '_oxygen_payment_note_type', sanitize_text_field( wp_unslash( $_POST['_oxygen_payment_note_type'] ) ) );
		}

		$oxygen_order_status = str_replace( 'wc-', '', OxygenWooSettings::get_option( 'oxygen_order_status' ) );
		$_oxygen_invoice     = $order->get_meta( '_oxygen_invoice', true );

		if ( empty( $_oxygen_invoice ) && isset( $_POST ) && isset( $_POST['order_status'] ) ) {

			if ( sanitize_text_field( wp_unslash( $_POST['order_status'] ) ) === 'wc-' . $oxygen_order_status ) {

				$_GET['notetype']                  = 'invoice';
				$_GET['_oxygen_payment_note_type'] = $order->get_meta( '_oxygen_payment_note_type', true );

				add_action( 'woocommerce_order_status_' . $oxygen_order_status, array( $this, 'create_invoice' ), 10, 2 );
			}
		}

		remove_action( 'woocommerce_after_order_object_save', array( $this, 'save_order' ), 20 );
		$order->save_meta_data();
		$order->save();
		add_action( 'woocommerce_after_order_object_save', array( $this, 'save_order' ), 20, 1 );

	}


	/**
	 *  On order email action
	 *
	 *  @param array  $attachments array of attachments absolute path.
	 *  @param string $email_id name of the email.
	 *  @param object $order WC_Order.
	 *  @param object $email WC_Email.
	 *  @return array of attachments absolute path.
	 */
	public function oxygen_attach_pdf_to_emails( $attachments, $email_id, $order, $email ) {

		$order_id = $order->get_id();

		$log = array( '-----------email id on attachement is-------',$email_id );
		OxygenWooSettings::debug( $log );

		$oxygen_order_status     = str_replace( 'wc-', '', OxygenWooSettings::get_option( 'oxygen_order_status' ) );
		$oxygen_order_attachment = str_replace( 'wc-', '', OxygenWooSettings::get_option( 'oxygen_order_attachment' ) );
		$_oxygen_invoice         = $order->get_meta( '_oxygen_invoice_pdf_path', true );

		if ( empty( $_oxygen_invoice ) ) {

			if ( $order->get_status() === $oxygen_order_status ) {

				$oxygen_default_document_type      = OxygenWooSettings::get_option( 'oxygen_default_document_type' );
				$_GET['notetype']                  = ( ! empty( $oxygen_default_document_type ) ? $oxygen_default_document_type : 'invoice' ); // default to invoice.
				$_GET['_oxygen_payment_note_type'] = $order->get_meta( '_oxygen_payment_note_type', true );

				$this->create_invoice( $order_id, $order );
			}
			$_oxygen_invoice = $order->get_meta( '_oxygen_invoice_pdf_path', true );
		}

		if ( $order->get_status() === $oxygen_order_status ) {

			if ( ! empty( $_oxygen_invoice ) && 'yes' === $oxygen_order_attachment ) {

				$attachments[] = $_oxygen_invoice;

			}
		}

		return $attachments;
	}

	/**
	 *  Fetches product myData settings
	 *
	 *  @param int $product_id the ID of the product.
	 *  @return array
	 */
	private static function get_product_mydata_info( $product_id ) {

		$meta = get_post_meta( $product_id );

		if ( ! empty( $meta['mydata_category'] ) && ! empty( $meta['mydata_classification_type'] ) ) {

			return array(
				'mydata_category'                    => $meta['mydata_category'],
				'mydata_classification_type'         => $meta['mydata_classification_type'],
				'mydata_category_receipt'            => $meta['mydata_category_receipt'],
				'mydata_classification_type_receipt' => $meta['mydata_classification_type_receipt'],
			);

		}

		$categories = get_the_terms( $product_id, 'product_cat' );

		if ( ! empty( $categories ) && is_array( $categories ) ) {

			foreach ( $categories as $cat ) {

				$mydata_category                    = get_term_meta( $cat->term_id, 'mydata_category', true );
				$mydata_classification_type         = get_term_meta( $cat->term_id, 'mydata_classification_type', true );
				$mydata_category_receipt            = get_term_meta( $cat->term_id, 'mydata_category_receipt', true );
				$mydata_classification_type_receipt = get_term_meta( $cat->term_id, 'mydata_classification_type_receipt', true );

				if ( ! empty( $mydata_category ) && ! empty( $mydata_classification_type ) ) {

					return array(
						'mydata_category'            => $mydata_category,
						'mydata_classification_type' => $mydata_classification_type,
						'mydata_category_receipt'    => $mydata_category_receipt,
						'mydata_classification_type_receipt' => $mydata_classification_type_receipt,
					);

				}
			}
		}

		return array(
			'mydata_category'                    => get_option( 'mydata_category' ),
			'mydata_classification_type'         => get_option( 'mydata_classification_type' ),
			'mydata_category_receipt'            => get_option( 'mydata_category_receipt' ),
			'mydata_classification_type_receipt' => get_option( 'mydata_classification_type_receipt' ),
		);

	}

	/**
	 *  Fetches product myData receipt settings
	 *
	 *  @param int $product_id the ID of the product.
	 *  @return array
	 */
	private static function get_product_mydata_receipt_info( $product_id ) {

		$meta = get_post_meta( $product_id );

		if ( ! empty( $meta['mydata_category'] ) && ! empty( $meta['mydata_classification_type'] ) ) {

			return array(
				'mydata_category'            => $meta['mydata_category_receipt'],
				'mydata_classification_type' => $meta['mydata_classification_type_receipt'],
			);

		}

		$categories = get_the_terms( $product_id, 'product_cat' );

		if ( ! empty( $categories ) && is_array( $categories ) ) {

			foreach ( $categories as $cat ) {

				$mydata_category            = get_term_meta( $cat->term_id, 'mydata_category_receipt', true );
				$mydata_classification_type = get_term_meta( $cat->term_id, 'mydata_classification_type_receipt', true );

				if ( ! empty( $mydata_category ) && ! empty( $mydata_classification_type ) ) {

					return array(
						'mydata_category'            => $mydata_category,
						'mydata_classification_type' => $mydata_classification_type,
					);

				}
			}
		}

		return array(
			'mydata_category'            => get_option( 'mydata_category_receipt' ),
			'mydata_classification_type' => get_option( 'mydata_classification_type_receipt' ),
		);

	}

	/**
	 *  New user actions on orders list table
	 *
	 *  @param array  $actions Array of user actions.
	 *  @param object $order WC_Order.
	 *  @return array
	 */
	public function my_account_my_orders_actions( $actions, $order ) {

		$invoice_data = $order->get_meta( '_oxygen_invoice', true );
		$notice_data  = $order->get_meta( '_oxygen_notice', true );
		$pdf          = $order->get_meta( '_oxygen_invoice_pdf', true );

		if ( isset( $invoice_data['iview_url'] ) && ! empty( esc_url( $invoice_data['iview_url'] ) ) ) {
			?>
			<div>
				<p>
				<a href="<?php echo esc_url( $invoice_data['iview_url'] ); ?>" target="_blank" title="<?php esc_attr_e( 'View Invoice', 'oxygen' ); ?>" class="woocommerce-button button" style="margin-bottom: 2px;"><?php esc_html_e( 'View Invoice', 'oxygen' ); ?> <span class="dashicons dashicons-search"></span></a><br />
				<a href="<?php echo esc_url( $pdf ); ?>" target="_blank" title="<?php esc_html_e( 'PDF Download', 'oxygen' ); ?>" class="woocommerce-button button"  style="margin-bottom: 2px;"><?php esc_html_e( 'PDF Download', 'oxygen' ); ?> <span class="dashicons dashicons-pdf"></span></a><br />
				</p>
			</div>
			<?php
		}
		if ( isset( $notice_data['iview_url'] ) && ! empty( $notice_data['iview_url'] ) ) {
			?>
			<div>
				<p>
				<a href="<?php echo esc_url( $notice_data['iview_url'] ); ?>" target="_blank"><span class="dashicons dashicons-media-document"></span> <?php esc_html_e( 'View Notice', 'oxygen' ); ?></a>
				</p>
			</div>
			<?php
		}

		return $actions;
	}


	/**
	 *  Helper method to allow only latin and numbers on strings
	 *
	 *  @param string $string the text to be cleaned.
	 *  @return string
	 */
	private static function clean( $string ) {

		$string = str_replace( ' ', '-', $string ); // Replaces all spaces with hyphens.

		return preg_replace( '/[^A-Za-z0-9\-]/', '', $string ); // Removes special chars.
	}

}


add_action('woocommerce_checkout_process', 'check_invoice_fields');

function check_invoice_fields() {

    $posted_data = WC()->checkout()->get_posted_data();

    if(!empty($posted_data)){

        $billing_country = $posted_data['billing_country'];
        $billing_invoice = $posted_data['billing_invoice'];
        $billing_vat = $posted_data['billing_vat'];
        $billing_job = $posted_data['billing_job'];
        $billing_tax_office = $posted_data['billing_tax_office'];

        if($billing_invoice === 1 ){ /* an exw epilexei ekdosh timologio */
            if(strcmp($billing_country, 'GR') === 0){ /* kai eimai ellada tote ola ta pedia einai ypoxrewtika */

                if(empty($billing_vat)){
                    wc_add_notice(__('The VAT number field is mandatory for issuing an invoice.','oxygen'), 'error');
                }else{
                    $result  = checkMod($billing_vat);
                    if($result === 0) {
                        wc_add_notice(__('The VAT number is incorrect.','oxygen'), 'error');
                    }
                }

                if(empty($billing_job)){
                    wc_add_notice(__('The Profession field is mandatory for invoicing.','oxygen'), 'error');
                }

                if(empty($billing_tax_office)){
                    wc_add_notice(__('The DOU field is mandatory for issuing an invoice.','oxygen'), 'error');
                }
            }

        }
    }
}

/* save check option for invoice or not --- using in oxygen payments */
add_action('woocommerce_checkout_update_order_meta', 'save_billing_invoice_meta');
function save_billing_invoice_meta($order_id) {
    // Check if the billing_invoice field is set
    if (isset($_POST['billing_invoice']) && $_POST['billing_invoice'] === '1') {
        update_post_meta($order_id, '_billing_invoice', '1'); // Save as 'yes' if checked
        $log = array( '------------ if billing invoice checkbox updated --------------', $order_id . ' '.get_post_meta( $order_id, '_billing_invoice', true ) );
        OxygenWooSettings::debug( $log );
    } else {
        update_post_meta($order_id, '_billing_invoice', '0'); // Save as 'no' if not checked
        $log = array( '------------ else billing invoice checkbox updated -------------', $order_id . ' '. get_post_meta( $order_id, '_billing_invoice', true ));
        OxygenWooSettings::debug( $log );
    }

}


/**
 * Make the actual check
 *
 * a. Get the first 8 digits
 * b. Calculate sum of product digit * 2^(8-digit index[0..8])
 * c. Calculate sum mod11 mod10
 * d. Result must be the same as last (9th) digit
 *
 * @param $value    string VAT ID
 *
 * @return integer
 */
function checkMod($value){
    $digits = str_split(substr($value, 0, -1));
    $sum    = 0;
    foreach ($digits as $index => $digit) {
        $sum += $digit * pow(2, 8 - $index);
    }
    //== (int) $value[8]
    if( $sum % 11 % 10 == (int) $value[8]){
        return 1;
    }
    return 0;
}

/**
 * Check vat number via api call to vat_check
 *
 *
 * @return
 */
function handle_check_vat_action() {

	$log = array( '---------------- COUNTRY CODE -------------' ,$_POST['country_code'] );
	OxygenWooSettings::debug( $log );

	if ( isset( $_POST['vat_number'] ) && !isset($_POST['country_code'])){

		$vat_number = sanitize_text_field( $_POST['vat_number'] );
		$response = OxygenApi::do_vat_check($vat_number);

		$log = array( '---------------- handle vat search greek -------------' );
		OxygenWooSettings::debug( $log );

	}else if(isset( $_POST['vat_number'] ) && isset($_POST['country_code'])) {

		$log = array( '---------------- handle vat search VIES -------------' );
		OxygenWooSettings::debug( $log );

		$vat_number = sanitize_text_field( $_POST['vat_number'] );
		$country_code = sanitize_text_field( $_POST['country_code'] );
		$response = OxygenApi::do_vies_check($vat_number,$country_code);

	}else{
		$response = array( 'message' => 'handle_check_vat_action - Vat number is empty' );
	}

	// Send a response back to the AJAX call
	wp_send_json_success( $response );
}

// Hook into WordPress' AJAX system for both logged in and non-logged in users
add_action( 'wp_ajax_check_vat_action', 'handle_check_vat_action' );
add_action( 'wp_ajax_nopriv_check_vat_action', 'handle_check_vat_action' );

function enqueue_vat_check_script() {
	// Enqueue your custom JS file
	wp_enqueue_script( 'check_vat', OXYGEN_PLUGIN_URL . '/js/check_vat.js', array(), '1.0.0' );

	// Pass WooCommerce parameters (like ajax_url and nonce) to your JS file
	if ( is_checkout() ) {

		wp_localize_script( 'check_vat', 'handle_check_vat_action', array(
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
		) );

	}
}

if( 'yes' === get_option('oxygen_fetch_vat_fields')) {
	add_action( 'wp_enqueue_scripts', 'enqueue_vat_check_script' );
}

function get_checkout_language($order_id) {

	$order = wc_get_order($order_id);
    $order_meta_data = $order->get_meta_data();

	$trp_language = null;
	foreach ( $order_meta_data as $meta ) {
		if ( $meta->key === 'trp_language' ) {
			$trp_language = $meta->value;
			break;
		}
	}

	OxygenWooSettings::debug( array('------ trp_language ----- ', $trp_language) );

	if ( function_exists( 'icl_object_id' ) ) {
		return apply_filters( 'wpml_current_language', NULL );
	}elseif ( function_exists( 'pll_current_language' ) ) {
		return pll_current_language();
	}elseif ( $trp_language !== '' ) {
	    OxygenWooSettings::debug( array('------ translate press lang is ', $trp_language) );
	    return $trp_language;
	}

	return 'el';
}
