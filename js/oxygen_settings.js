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

        if($('input#oxygen_self_fields').is(":checked")){

            $('select#oxygen_vat_metakey ,select#oxygen_working_field_metakey ,select#oxygen_tax_office , select#oxygen_issue_invoice_metakey').closest('tr').hide();

        }else{
            $('select#oxygen_vat_metakey ,select#oxygen_working_field_metakey ,select#oxygen_tax_office , select#oxygen_issue_invoice_metakey').closest('tr').show();

        }

        $('input#oxygen_self_fields').on('click', function() {

            if($(this).is(":checked")){

                $('select#oxygen_vat_metakey ,select#oxygen_working_field_metakey ,select#oxygen_tax_office , select#oxygen_issue_invoice_metakey').closest('tr').hide();

            }else{
                $('select#oxygen_vat_metakey ,select#oxygen_working_field_metakey ,select#oxygen_tax_office , select#oxygen_issue_invoice_metakey').closest('tr').show();

            }

        });
    }
);