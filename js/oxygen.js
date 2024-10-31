/**
 * Plugin Name: Oxygen MyData
 * Plugin URI: https://wordpress.org/plugins/oxygen-mydata/
 * Description: A WordPress plugin to connect WooCommerce with Oxygen Pelatologio and MyData
 * Author: Oxygen
 * Author URI: https://pelatologio.gr/
 * Text Domain: oxygen
 * Domain Path: /languages/
 * Version: 1.0.31
 * Requires at least: 5.5
 * Tested up to: 6.6.2
 * WC requires at least: 4.7
 * WC tested up to: 9.3.3
 * License: GPL2
 *
 * Oxygen MyData for WooCommerce is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Oxygen myData for WooCommerce. If not, see  https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Oxygen
 * @version 1.0.10
 * @since  1.0.0
 */

jQuery( document ).ready(
	function ( $ ) {

		setTimeout(function() {
			$('#oxygen_payment_enabled').fadeOut('slow');
		}, 5000);

		if ($( 'input#billing_invoice' ).length > 0 ) {


			var required_html = $( 'abbr.required' ).first();
			var optional_html = $( 'span.optional' ).first();

			// console.log( required_html );

			if ( $( '#billing_vat_field' ).length > 0 ) {
				$( '#billing_vat_field label span' ).remove();
				$( '#billing_vat_field label' ).append( required_html.clone() );
			}
			if ( $( '#billing_job_field' ).length > 0 ) {
				$( '#billing_job_field label span' ).remove();
				$( '#billing_job_field label' ).append( required_html.clone() );
			}
			if ( $( '#billing_tax_office_field' ).length > 0 ) {
				$( '#billing_tax_office_field label span' ).remove();
				$( '#billing_tax_office_field label' ).append( required_html.clone() );
			}

			check_invoice_checkbox();

			$( 'input#billing_invoice' ).on(
				'change',
				function () {

					check_invoice_checkbox();

				}
			);
		}

		function check_invoice_checkbox()
		{

			if ($( 'input#billing_invoice' ).is( ':checked' ) ) {

				maybe_display_vat_fields( true );

			} else {

				maybe_display_vat_fields( false );
			}

		}

		function maybe_display_vat_fields( $show ) {

			if ($show == true) {

				$( 'input#billing_invoice' ).attr("value" , '1');

				if ($('#billing_vat_field').length > 0) {

					$('#billing_vat_field').show();
				}
				if ($('#billing_job_field').length > 0) {

					$('#billing_job_field').show();
				}
				if ($('#billing_tax_office_field').length > 0) {

					$('#billing_tax_office_field').show();
				}
				if ($('#billing_company_field').length > 0) {
					$('#billing_company_field label span').remove();
					$('#billing_company_field label').append(required_html.clone());
				}

				setTimeout(function () {
					$('#billing_company_field').show();
				}, 100);

			} else {

				$( 'input#billing_invoice' ).attr("value" , '0');

				if ($('#billing_vat_field').length > 0) {

					$('#billing_vat').attr("value",'');
					$('#billing_vat_field').hide();
				}
				if ($('#billing_job_field').length > 0) {

					$('#billing_job').attr("value",'');
					$('#billing_job_field').hide();
				}
				if ($('#billing_tax_office_field').length > 0) {

					$('#billing_tax_office').attr("value",'');
					$('#billing_tax_office_field').hide();
				}
				if ($('#billing_company_field').length > 0) {

					$('#billing_company').attr("value",'');
					$('#billing_company_field label abbr').remove();
					$('#billing_company_field label').append(optional_html.clone());
				}
				setTimeout(function () {
					$('#billing_company_field').hide();
				}, 100);
			}
		}


	}
);