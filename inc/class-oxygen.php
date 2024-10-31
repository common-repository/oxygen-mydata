<?php
/**
 * Summary Main Oxygen class to include more files, load translations and add settings link in plugin list.
 *
 * @package Oxygen
 * Oxygen MyData Class File
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
class Oxygen {



	/**
	 * Singleton Instance of Oxygen
	 *
	 * @var Oxygen
	 **/
	private static $instance = null;


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
	 *  Requires files and hooks translations and settings link
	 *
	 *  @return void
	 */
	private function init_hooks() {

		require_once OXYGEN_PLUGIN_DIR . '/inc/class-oxygenwoosettings.php';
		OxygenWooSettings::init();

		require_once OXYGEN_PLUGIN_DIR . '/inc/class-oxygenorder.php';
		OxygenOrder::init();

		require_once OXYGEN_PLUGIN_DIR . '/inc/class-oxygenapi.php';
		OxygenApi::init();

        /* this is needed to run oxygen payments*/
        require_once OXYGEN_PLUGIN_DIR . '/inc/class-wc-oxygenpayment-gateway.php';

		add_action( 'init', array( $this, 'load_text_domain' ), 100 );
		add_filter( 'plugin_action_links_' . OXYGEN_PLUGIN_BASENAME, array( $this, 'add_plugin_page_settings_link' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'oxygen_load_scripts' ) );

        /* this is about checkout and oxygen settings oxygen payments - order status change */
        add_action( 'admin_enqueue_scripts', array( $this, 'oxygen_load_settings' ) );

	}

	/**
	 * Enqueue Oxygen JS
	 */
	public function oxygen_load_scripts() {

		$args = array(
			'in_footer' => true,
		);

		wp_enqueue_script( 'oxygen_js', OXYGEN_PLUGIN_URL . '/js/oxygen.js', array(), '1.0.0', $args );
	}

    /**
     * Enqueue Oxygen Settings JS
     */
    public function oxygen_load_settings() {
        wp_enqueue_script( 'oxygen_settings_js', OXYGEN_PLUGIN_URL . '/js/oxygen_settings.js', array(), '1.0.0', true );
    }

	/**
	 * Loads Translations
	 */
	public static function load_text_domain() {
		load_plugin_textdomain( 'oxygen', false, dirname( plugin_basename( OXYGEN_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 *  Adds plugins list settings link
	 *
	 *  @param Array $links array of existing plugins links.
	 *
	 *  @return Array
	 */
	public static function add_plugin_page_settings_link( $links ) {
		$newlinks[] = '<a href="' .
			admin_url( 'admin.php?page=wc-settings&tab=oxygen' ) .
			'">' . __( 'Settings' ) . '</a>';
		return array_merge( $newlinks, $links );
	}

}
