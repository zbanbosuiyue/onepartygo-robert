<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );
/**
 * Plugin Name: OpenTickets - Table Service
 * Plugin URI:  http://opentickets.com/
 * Description: Enables the admin user to force users to select a list of additional products, after a seat has been selected.
 * Version:     0.9.4
 * Author:      Quadshot Software LLC
 * Author URI:  http://quadshot.com/
 * Text Domain: qsot-table-service
 * Domain Path: /langs/
 * Copyright:   Copyright (C) 2009-2015 Quadshot Software LLC
 * License:     OpenTickets Software License Agreement
 * License URI: http://opentickets.com/opentickets-enterprise-software-license-agreement/
 */

// launcher for the table-service plugin
class QSOT_Table_Service_Launcher {
	protected static $requires = array(
		'woocommerce_version' => array(
			array( 'woocommerce', 'woocommerce.php', 'wc_missing' ),
			array( '2.6.4', 'wc_min_version' ),
		),
		'opentickets_community_edition_version' => array(
			array( 'opentickets-community-edition', 'launcher.php', 'ot_missing' ),
			array( '2.4.4', 'ot_min_version' ),
		),
	);
	// make it a singleton
	protected static $_instance = null;
	public static function instance() { return self::$_instance = ( self::$_instance instanceof self ) ? self::$_instance : new self; }
	protected function __construct() {
		// setup self
		$this->me = plugin_basename( __FILE__ );
		$this->plugin_url = plugin_dir_url( __FILE__ );
		$this->plugin_dir = plugin_dir_path( __FILE__ );

		// validate that we meet all the requirements
		if ( $this->_meet_requirements() ) {
			// add our class dirs to OTCE load
			add_filter( 'qsot-load-includes-dirs', array( &$this, 'inform_core_about_us' ), 10 );
			// add template dirs to OTCE templater
			add_filter( 'qsot-template-dirs', array( &$this, 'add_template_directory' ), 10000, 4 );

			if ( is_admin() )
				$this->_check_version();

			// register our own activation function
			register_activation_hook( __FILE__, array( &$this, 'on_activation' ) );

			// once we are about to render the page, do one last check to see if shortcodes are allowed in text widgets
			add_action( 'init', array( &$this, 'shortcode_in_text_widgets' ) );
		} else {
			add_action( 'admin_notices', array( &$this, 'show_init_errors' ) );
		}

		// load the translation files later in the load so that ML plugins can work
		add_action( 'plugins_loaded', array( &$this, 'load_text_domain' ), 5 );
	}

	// containers for all self describing data
	protected $me = '';
	protected $name = '';
	protected $plugin_url = '';
	protected $plugin_dir = '';
	protected $version = '0.9.4';
	protected $_version_key = 'qsot_table_service_version';

	// initialization error container
	protected $init_errors = array();

	// functions for self describing data
	public function me() { return $this->me; }
	public function name() { return $this->name ? $this->name : ( $this->name = __( 'QSOT Table Service', 'qsot-table-service' ) ); }
	public function version() { return $this->version; }
	public function plugin_url() { return $this->plugin_url; }
	public function plugin_dir() { return $this->plugin_dir; }

	// util functions
	// load the text domain for this plugin, after all plugins are loaded, so that this plugin works with qTranslate
	public function load_text_domain() {
		load_plugin_textdomain( 'qsot-seating', false, dirname( $this->me ) . '/langs/' );
	}

	// show itinialization errors
	public function show_init_errors() {
		// if there are no errors to show, then bail
		if ( ! $this->init_errors )
			return;

		echo '<div class="error errors">';

		// cycle through the erros, and create a message for each one
		foreach ( $this->init_errors as $error ) {
			switch ( $error ) {
				// activation status
				case 'wc_missing':
					echo '<p class="error">' . sprintf( __( 'WooCommerce must be installed and activated, before you can use %s.', 'qsot-table-service' ), $this->name ) . '</p>';
				break;

				case 'ot_missing':
					echo '<p class="error">' . sprintf( __( 'OpenTickets Community Edition must be installed and activated, before you can use %s.', 'qsot-table-service' ), $this->name ) . '</p>';
				break;

				// min versions
				case 'wc_min_version':
					echo '<p class="error">' . sprintf( __( 'WooCommerce must be at least version %s, before you can use %s.', 'qsot-table-service' ), self::$requires['woocommerce_version'][1][0], $this->name ) . '</p>';
				break;

				case 'ot_min_version':
					echo '<p class="error">' . sprintf( __( 'OpenTickets Community Edition must be at least version %s, before you can use %s.', 'qsot-table-service' ), self::$requires['opentickets_community_edition_version'][1][0], $this->name ) . '</p>';
				break;
			}
		}

		echo '</div>';
	}

	// let the core OT plugin know where ot find our files for loading
	public function inform_core_about_us( $plugin_directories ) {
		array_unshift( $plugin_directories, trailingslashit( dirname( __FILE__ ) ) . 'table-service/' );
		return array_unique( $plugin_directories );
	}

	// let the core OT plugin know where to find the templates for our plugin
	public function add_template_directory( $list, $qsot_path='', $woo_path='', $type=false ) {
		// if the subdir requested is the admin templates, construct a proper path for that
		if ( $qsot_path == 'templates/admin/' )
			array_unshift( $list, $this->plugin_dir . 'templates/admin/' );
		// or if it is for woocommerce specific templates
		else if ( $type == 'woocommerce' )
			array_unshift( $list, $this->plugin_dir . 'templates/woocommerce/' );
		// otherwise, use the basic template path
		else
			array_unshift( $list, $this->plugin_dir . 'templates/' );
		return $list;
	}

	// check and possibly update the version number. this is mainly done for the benefit of other plugins, since this is not used anywhere in our code
	protected function _check_version() {
		$version = get_option( $this->_version_key, '0.0.0' );
		if ( $version != $this->version )
			update_option( $this->_version_key, $this->version );
	}

	// are all the requirements met
	protected function _meet_requirements() {
		$good = true;
		// check plugin activation status
		foreach ( self::$requires as $key => $args ) {
			if ( ! $this->_active_plugin( $args[0][0], $args[0][1] ) ) {
				$this->init_errors[] = $args[0][2];
				$good = false;
			}
		}

		// check plugin min version requirements
		foreach ( self::$requires as $key => $args ) {
			if ( ! $this->_plugin_min_version( $args[1][0], $key ) ) {
				$this->init_errors[] = $args[1][1];
				$good = false;
			}
		}

		return $good;
	}

	// determine if plugin meets version requirements
	protected function _plugin_min_version( $min_version, $key ) {
		// get the current version of the plugin
		$current_version = get_option( $key, '0.0.0' );

		// compare the two versions
		return version_compare( $min_version, $current_version ) < 1;
	}

	// determine if a given plugin is active
	protected function _active_plugin( $plugin_dir, $plugin_file ) {
		static $active = false;
		if ( false == $active ) {
			// aggregate a complete list of active plugins, including those that could be active on the network level
			$active = get_option( 'active_plugins', array() );
			$network = defined( 'MULTISITE' ) && MULTISITE ? get_site_option( 'active_sitewide_plugins' ) : array();
			$active = is_array( $active ) ? $active : array();
			$network = is_array( $network ) ? $network : array();
			$active = array_merge( array_keys( $network ), $active );
		}

		// check if the regular plugin is active. now DIRECTORY_SEPARATOR here, because wp translates it to '/' for the active plugin arrays
		$is_active = in_array( $plugin_dir . '/' . $plugin_file, $active );

		// if the regular plugin is not acitve, check for known direcotry formats for github zip downloads
		if ( ! $is_active ) {
			foreach ( $active as $active_plugin ) {
				if ( preg_match( '#^' . preg_quote( $plugin_dir, '#' ) . '-(master|[\d\.]+(-(alpha|beta|RC\d+)(-\d+)?)?)[\/\\\\]' . preg_quote( $plugin_file, '#' ). '$#', $active_plugin ) ) {
					$is_active = true;
					break;
				}
			}
		}

		return $is_active;
	}

	// add shortcodes to text widgets if they are not already there
	public function shortcode_in_text_widgets() {
		if ( ! has_filter( 'widget_text', 'do_shortcode' ) )
			add_filter( 'widget_text', 'do_shortcode' );
	}

	// upon activation run some code
	public function on_activation() {
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) ) {
	function QSTS() { return QSOT_Table_Service_Launcher::instance(); }
	QSTS();
}
