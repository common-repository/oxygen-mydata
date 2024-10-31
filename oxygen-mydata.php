<?php
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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OXYGEN_PLUGIN_VERSION', '1.0.31' );
define( 'OXYGEN_PLUGIN_FILE', __FILE__ );
define( 'OXYGEN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OXYGEN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OXYGEN_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once OXYGEN_PLUGIN_DIR . '/inc/class-oxygen.php';

add_action( 'woocommerce_loaded', array( 'Oxygen', 'init' ) );

/**
 * Declare WooCommerce HPOS compatibility
 */
function oxygen_hpos_compatibility() {

	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'oxygen_hpos_compatibility' );

// Increase timeout for connection to our api
add_filter( 'http_request_timeout', 'increase_http_request_timeout' );
function increase_http_request_timeout( $timeout ) {
    return 15;
}
