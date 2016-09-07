<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );
/**
 * Plugin Name: OpenTickets - Coupons & Passes
 * Plugin URI:  http://opentickets.com/product/opentickets-coupons/
 * Description: Adds multiple abilities to limit the usage of coupons by event. Additionally adds the ability to 'sell coupons', so that flex passes and season passes are possible.
 * Version:     2.0.5
 * Author:      Quadshot Software LLC
 * Author URI:  http://quadshot.com/
 * Text Domain: qsot-coupons
 * Domain Path: /langs/
 * Copyright:   Copyright (C) 2009-2014 Quadshot Software LLC
 * License:     OpenTickets Software License Agreement
 * License URI: http://opentickets.com/opentickets-enterprise-software-license-agreement/
 */

// checks all coupons plugin dependencies and sets up the plugin in general
class QSOT_coupons_launcher {
	// urls to relevant quadshot products
	protected static $urls = array(
		'me' => 'http://opentickets.com/product/opentickets-coupons/',
		'opentickets' => 'https://wordpress.org/plugins/opentickets-community-edition/',
	);
	// plugin basename
	protected static $me = '';
	// plugin version
	protected static $version = '2.0.5';
	protected static $version_key = 'qsot_coupons_version';
	// plugin paths
	protected static $plugin_url = '';
	protected static $plugin_dir = '';

	// dependency check vars. 
	// list of plugins currently in conflict with this one
	protected static $in_conflict = array();
	protected static $in_conflict_key = 'qsot_coupons_in_conflict';
	protected static $active = false;
	// min versions of required softwares
	protected static $min_wc_version = '2.3.8';
	protected static $min_otce_version = '2.0.0';

	// setup the plugin
	public static function pre_init() {
		// fill plugin vars
		self::$me = plugin_basename( __FILE__ );
		self::$plugin_url = plugin_dir_url( __FILE__ );
		self::$plugin_dir = plugin_dir_path( __FILE__ );

		// if we pass all the system requirements tests
		if ( self::_pass_system_requirements() ) {
			// add our class dirs to OTCE load
			add_filter( 'qsot-load-includes-dirs', array( __CLASS__, 'inform_core_about_us' ), 10 );
			// add template dirs to OTCE templater
			//add_filter( 'qsot-template-dirs', array( __CLASS__, 'add_template_directory' ), 10000, 4 );
			// do load finalization after all plugins have loaded
			add_action( 'plugins_loaded', array( __CLASS__, 'plugins_loaded' ) );

			if ( is_admin() )
				self::_check_version();

			// register our own activation function
			register_activation_hook( __FILE__, array( __CLASS__, 'activation' ) );
		}

		add_action( 'activated_plugin', array( __CLASS__, 'remove_in_conflict_cache' ), 0 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'remove_in_conflict_cache' ), 0 );

		add_action( 'plugins_loaded', array( __CLASS__, 'load_text_domain' ), 5 );
	}

	// load the text domain for this plugin, after all plugins are loaded, so that this plugin works with qTranslate
	public static function load_text_domain() {
		load_plugin_textdomain( 'qsot-coupons', false, dirname( self::$me ) . '/langs/' );
	}

	// public functions to fetch various informations about this plugin
	public static function me() { return self::$me; }
	public static function version() { return self::$version; }
	public static function plugin_url() { return self::$plugin_url; }
	public static function plugin_dir() { return self::$plugin_dir; }

	// check and possibly update the version number. this is mainly done for the benefit of other plugins, since this is not used anywhere in our code
	protected static function _check_version() {
		$version = get_option( self::$version_key, '0.0.0' );
		if ( $version != self::$version )
			update_option( self::$version_key, self::$version );
	}

	// perform various dep chacks
	protected static function _pass_system_requirements() {
		$pass = true;

		// require OTCE plugin
		if ( ! self::_is_opentickets_active() ) {
			add_action( 'admin_notices', array( __CLASS__, 'error_requires_opentickets' ) );
			// when opentickets community edition is activated, run our activation as well
			$file = substr( self::$me, 0, strpos( self::$me, 'qsot-coupons' ) ) . implode( DIRECTORY_SEPARATOR, array( 'opentickets-community-edition', 'launcher.php' ) );
			add_action( 'activate_' . $file, array( __CLASS__, 'activation' ), 1000001 );
			$pass = false;
		// require min OTCE version
		} else if ( ! self::_is_opentickets_min_version() ) {
			add_action( 'admin_notices', array( __CLASS__, 'error_opentickets_min_version' ) );
			$pass = false;
		}

		// require WC plugin
		if ( ! self::_is_woocommerce_active() ) {
			add_action( 'admin_notices', array( __CLASS__, 'error_requires_woocommerce' ) );
			// when woocommerce is activated, run our activation as well
			$file = substr( self::$me, 0, strpos( self::$me, 'qsot-coupons' ) ) . implode( DIRECTORY_SEPARATOR, array( 'woocommerce', 'woocommerce.php' ) );
			add_action( 'activate_' . $file, array( __CLASS__, 'activation' ), 1000001 );
			$pass = false;
		// require min WC version
		} else if ( ! self::_is_woocommerce_min_version() ) {
			add_action( 'admin_notices', array( __CLASS__, 'error_woocommerce_min_version' ) );
			$pass = false;
		}
		
		// check for known conflicting plugins
		if ( self::_has_conflicting_plugins() ) {
			add_action( 'admin_notices', array( __CLASS__, 'error_conflicting_plugins_active' ) );
			$pass = false;
		}

		return $pass;
	}

	// tell core OTCE where to find our classes for this plugin
	public static function inform_core_about_us( $plugin_directories ) {
		// load our classes first, as to override any core classes that need overriding
		array_unshift( $plugin_directories, self::$plugin_dir . 'coupons/' );
		return array_unique( $plugin_directories );
	}

	/* dont think this is used
	// tell core OTCE where to find the templates used by this plugin
	public static function add_template_directory( $list, $qsot_path='', $woo_path='', $type=false ) {
		// give a different location depending on the sub path to use
		if ( $qsot_path == 'templates/admin/' )
			array_unshift( $list, plugin_dir_path( __FILE__ ) . 'templates/admin/' );
		else if ( $type == 'woocommerce' )
			array_unshift( $list, plugin_dir_path( __FILE__ ) . 'templates/woocommerce/' );
		else
			array_unshift( $list, plugin_dir_path( __FILE__ ).'templates/' );
		return $list;
	}
	*/

	// print a generic message indicating that one of the dependencies does not meet a version requirement
	protected static function _error_dep_min_version( $dep, $min_version, $cur_version ) {
		?>
			<div class="error errors">
				<p class="error"><?php echo sprintf(
					__( '<u><strong>%s</strong></u><br/> The %s plugin <strong>requires</strong> that %s be at least at version %s. You currently have version %s. Please upgrade %s to finish activating all functionality of the %s plugin.', 'qsot-coupons' ),
					self::_me_link(),
					self::_me_link(),
					$dep,
					$min_version,
					$cur_version,
					$dep,
					self::_me_link()
				) ?>
				</p>
			</div>
		<?php
	}

	// generate the version requirement error for core OTCE
	public static function error_opentickets_min_version() {
		self::_error_dep_min_version( self::_me_link( self::$urls['opentickets'], 'OpenTickets Community Edition' ), self::$min_otce_version, get_option( 'opentickets_community_edition_version', '0.0.0' ) );
	}

	// generate the version requirement error for WooCommerce
	public static function error_woocommerce_min_version() {
		self::_error_dep_min_version( self::_me_link( null, 'WooCommerce' ), self::$min_wc_version, get_option( 'woocommerce_version', '0.0.0' ) );
	}

	// generic error stating that a required plugin is not present
	protected static function _requires_plugin( $req_plugin ) {
		?>
			<div class="error errors">
				<p class="error"><?php echo sprintf(
					__( '<u><strong>%s</strong></u><br/> The %s plugin <strong>requires</strong> that %s be activated in order to perform most vital functions; therefore, the plugin has not initialized any of its functionality. To enable the features of this plugin, simply install and activate %s.', 'qsot-coupons' ),
					self::_me_link(),
					self::_me_link(),
					$req_plugin,
					$req_plugin
				) ?>
				</p>
			</div>
		<?php
	}

	// generate the error saying that OTCE is required and not active
	public static function error_requires_opentickets() {
		self::_requires_plugin( self::_me_link( self::$urls['opentickets'], 'OpenTickets Community Edition' ) );
	}

	// generate the error saying that WooCommerce is required and not active
	public static function error_requires_woocommerce() {
		self::_requires_plugin( self::_me_link( null, 'WooCommerce' ) );
	}

	// generic error stating that certain active plugins are known to be a problem with this plugin
	public static function error_conflicting_plugins_active() {
		?>
			<div class="error errors">
				<p class="error"><?php echo sprintf(
					__( 'There are one or more plugins active that have know conflicts with %s. Please deactivate the following plugins, in order to ensure that the %s plugin functions properly: <strong><em>%s</em></strong>', 'qsot-coupons' ),
					self::_me_link(),
					self::_me_link(),
					implode( ', ', self::$in_conflict )
				) ?>
				</p>
			</div>
		<?php
	}

	// fill a static var with the list of all active plugins
	protected static function _find_active_plugins() {
		if ( false == self::$active ) {
			// aggregate a complete list of active plugins, including those that could be active on the network level
			$active = get_option( 'active_plugins', array() );
			$network = defined( 'MULTISITE' ) && MULTISITE ? get_site_option( 'active_sitewide_plugins' ) : array();
			$active = is_array( $active ) ? $active : array();
			$network = is_array( $network ) ? $network : array();
			self::$active = array_merge( array_keys( $network ), $active );
		}
	}

	// determine if a given plugin is active
	public static function _is_plugin_active( $plugin_dir, $plugin_file ) {
		self::_find_active_plugins();

		// check if the regular plugin is active. now DIRECTORY_SEPARATOR here, because wp translates it to '/' for the active plugin arrays
		$is_active = in_array( $plugin_dir . '/' . $plugin_file, self::$active );

		// if the regular plugin is not acitve, check for known direcotry formats for github zip downloads
		if ( ! $is_active ) {
			foreach ( self::$active as $active_plugin ) {
				if ( preg_match( '#^' . preg_quote( $plugin_dir, '#' ) . '-(master|[\d\.]+(-(alpha|beta|RC\d+)(-\d+)?)?)[\/\\\\]' . preg_quote( $plugin_file, '#' ). '$#', $active_plugin ) ) {
					$is_active = true;
					break;
				}
			}
		}

		return $is_active;
	}

	// check if OTCE is active
	public static function _is_opentickets_active() {
		return self::_is_plugin_active( 'opentickets-community-edition', 'launcher.php' );
	}

	// check if WooCommerce is active
	public static function _is_woocommerce_active() {
		return self::_is_plugin_active( 'woocommerce', 'woocommerce.php' );
	}

	// check the current OTCE version to see if it is at least the required version
	public static function _is_opentickets_min_version() {
		return version_compare( get_option( 'opentickets_community_edition_version', '0.0.0' ), self::$min_otce_version ) >= 0;
	}

	// check the current WooCommerce version to see if it is at least the required version
	public static function _is_woocommerce_min_version() {
		return version_compare( get_option( 'woocommerce_version', '0.0.0' ), self::$min_wc_version ) >= 0;
	}

	// check a list of known conflicting plugins. if any are active, then add them to a list that will be used later to pop an error
	public static function _has_conflicting_plugins() {

		// list of known conflicts
		$conflicts = array(
			//'#^qsot-ga-multi-price(-(master|[\d\.]+(-(alpha|beta|RC\d+)(-\d+)?)?))?[\/\\\\]qsot-ga-multi-price.php$#' => __( 'OpenTickets General Admission Multiprice', 'qsot-coupons' ),
		);

		// fetched the cached version of the list. this cache is blown out on plugin activation and deactivation. it is cached, because we do not want it running on every page load, since it can be heavy
		$cache = get_option( self::$in_conflict_key, '' );
		// if the cache is not empty, then use it intead of regenerating the whole lot
		if ( '' !== $cache ) {
			self::$in_conflict = $cache;
		// if the cache is empty, then generate a cache for later use
		} else {
			self::_find_active_plugins();
			// cycle through all plugins and do 
			foreach ( $conflicts as $file_regex => $name ) {
				// check the file_regex against all active plugins
				foreach ( self::$active as $active_plugin ) {
					// if a plugin matches, then mark this pluign as being in conflict
					if ( preg_match( $file_regex, $active_plugin ) ) {
						self::$in_conflict[ $file_regex ] = $name;
						break 1;
					}
				}
			}

			// update the cache
			update_option( self::$in_conflict_key, self::$in_conflict );
		}

		return !! count( self::$in_conflict );
	}

	// generate a labeled link to an asset, based on supplied params
	protected static function _me_link($link='', $label='', $format='') {
		// normalize the input
		$link = '' === $link ? $link : self::$urls['me'];
		$label = $label ? $label : __( 'OpenTickets - Coupons', 'qsot-coupons' );
		// determine the proper format
		$format = $format ? $format : ( null !== $link ? '<em><a href="%1$s" target="_blank">%2$s</a></em>' : '<em>%2$s</em>' );

		return sprintf( $format, esc_attr( $link ), $label );
	}

	// register this plugin with the keychain
	public static function plugins_loaded() {
		//do_action( 'qsot-register-addon', self::$me, array( 'code' => 'ZrfX7Xu#5D5eh=zY9tBtHbu^I8NO.@SXCxsm]dG|gDV<aRtt9]Oz*mKRxjt*wv2R', 'product' => 'QSOTBoxOffice' ) );
	}

	// upon activation, attempt to run any needed upates
	public static function activation() {
		// first make sure that any inconflict cache is removed
		self::remove_in_conflict_cache();

		// register the plugin with keychain
		self::plugins_loaded();
		//QSOT_addon_registry::instance()->force_check();

		// load any files that contain DB updates
		$path = QSOT_coupons_launcher::plugin_dir() . 'coupons/';
		require_once $path . 'core.class.php';
		//require_once $path . 'core/zoner.class.php';

		// let child files and other plugins know this specific activation is happening
		do_action('qsot-coupons-activation');
	}

	// blow out the inconflict cache
	public static function remove_in_conflict_cache() {
		delete_option( self::$in_conflict_key );
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) ) {
	QSOT_coupons_launcher::pre_init();
}
