<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// the loader for all shortcodes for this extension
class QSOT_DO_shortcodes {
	// holder for otce plugin settings
	protected static $o = null;
	protected static $options = null;
	
	// settings order index for dynamic settings
	protected static $order_index = 201;

	// container for registered shortcodes
	protected static $shortcodes = array();

	// container for removed filters on ea content output, and count of how many levels deep it is
	protected static $removed_filters = array();
	protected static $level_removed = 0;

	// setup the class
	public static function pre_init() {
		// first thing, load all the options, and share them with all other parts of the plugin
		$settings_class_name = apply_filters( 'qsot-settings-class-name', '' );
		if ( ! class_exists( $settings_class_name ) )
			return false;
		self::$o = call_user_func_array( array( $settings_class_name, 'instance' ), array() );

		// load all the options, and share them with all other parts of the plugin
		$options_class_name = apply_filters( 'qsot-options-class-name', '' );
		if ( ! empty( $options_class_name ) ) {
			self::$options = call_user_func_array( array( $options_class_name, 'instance' ), array() );
			self::_setup_admin_options();
		}

		// allow shortcodes to register themselves
		add_action( 'qsot-register-shortcode', array( __CLASS__, 'register_shortcode' ), 100, 2 );

		// allow fetching of a list of shortcodes
		add_filter( 'qsot-get-shortcodes', array( __CLASS__, 'get_shortcodes' ), 100, 2 );

		// remove extraneous filters causing descriptions to be muddled
		add_action( 'qsot-before-upcoming-events', array( __CLASS__, 'remove_ea_interfaces' ) );
		add_action( 'qsot-before-featured-event', array( __CLASS__, 'remove_ea_interfaces' ) );
		add_action( 'qsot-after-featured-event', array( __CLASS__, 'restore_ea_interfaces' ) );
		add_action( 'qsot-after-upcoming-events', array( __CLASS__, 'restore_ea_interfaces' ) );

		// load all shortcodes
		do_action( 'qsot-load-includes', 'shortcodes', '#^.+\.shortcode\.php$#i');
	}

	// when drawing the output of some of our shortcodes, we need to make sure that we do not draw the 'UI' for the seat selection on event descriptions
	public static function remove_ea_interfaces() {
		// increment the level deep we are. we should only make changes to the filter storage when we are at level 1
		// this is needed in case of nested shortcodes that call this function
		self::$level_removed++;

		// if at level 1, then create a storage of the current filters, and remove all filters from the global list
		if ( 1 === self::$level_removed ) {
			// store all filters for the specific ones we need to
			foreach ( array( 'qsot-event-the-content' ) as $filter ) {
				if ( isset( $GLOBALS['wp_filter'][ $filter ] ) ) {
					self::$removed_filters[ $filter ] = $GLOBALS['wp_filter'][ $filter ];
					unset( $GLOBALS['wp_filter'][ $filter ] );
				}
			}
		}
	}

	// after we are done drawing our shortcodes, we need to restore any previous UI output for event descriptions
	public static function restore_ea_interfaces() {
		// decrement the level deep we are. we should only restore the filters when at level 0
		self::$level_removed--;

		// if at level 0, the restore the original GLOBAL filters we removed
		if ( 0 === self::$level_removed && self::$removed_filters ) {
			// restore all filters
			foreach ( self::$removed_filters as $filter => $settings ) {
				if ( ! isset( $GLOBALS['wp_filter'][ $filter ] ) )
					$GLOBALS['wp_filter'][ $filter ] = $settings;
			}
			// reset our restoration list
			self::$removed_filters = array();
		}
	}

	// wrapper to allow our shortcodes to register themselves with not only wp by also this extension
	public static function register_shortcode( $args='' ) {
		// normalize the args
		$args = wp_parse_args( $args, array(
			'code' => 'qsot_noop', // callback to generate the 'code' for this shortcode
			'render' => 'qsot_noop', // shortcode rendering callback
			'options' => 'qsot_noop', // callback called to register the options for the shortcode
			'form' => 'qsot_noop', // callback to call for displaying the options form that can be used to build the shortcode
		) );

		// if the options callback is a function that can be called, then add the options for the shortcode. called first, because it could change the 'code' of the shortcode
		if ( is_callable( $args['options'] ) )
			call_user_func_array( $args['options'], array( &self::$options, self::$order_index++ ) );

		$code = '';
		// if the 'code' callback is callable, then call it to find the code name
		if ( is_callable( $args['code'] ) )
			$code = call_user_func_array( $args['code'], array( &self::$options ) );

		// if the code is empty, then bail
		if ( '' === $code )
			return;

		// if the shortcode render function is a function that can be called, then add the shortcode
		if ( is_callable( $args['render'] ) ) {
			add_shortcode( $code, $args['render'] );
			self::$shortcodes[ $code ] = $args;
		}
	}

	// return a list of register shortcodes
	public static function get_shortcodes( $list, $tag='' ) {
		// if the tag was passed, then try to return the data about a shortcode registered for that tag
		if ( ! empty( $tag ) )
			return is_string( $tag ) && isset( self::$shortcodes[ $tag ] ) ? self::$shortcodes[ $tag ] : array();

		// otherwise return the whole list
		$list = is_array( $list ) ? $list : array();
		return array_merge( $list, self::$shortcodes );
	}

	// setup the options that are available to control our 'Display Options'
	protected static function _setup_admin_options() {
		// the 'Shortcodes' heading on the Frontend tab
		self::$options->add( array(
			'order' => 200,
			'type' => 'title',
			'title' => __( 'Shortcodes', 'qsot-display-options' ),
			'id' => 'heading-frontend-qsot-shortcodes-1',
			'page' => 'display-options',
		) );

		// end the 'Shortcodes' section on the page
		self::$options->add(array(
			'order' => 299,
			'type' => 'sectionend',
			'id' => 'heading-frontend-qsot-shortcodes-1',
			'page' => 'display-options',
		));
	}
}

abstract class QSOT_base_shortcode {
	protected $shortcode_default_code = '';
	protected $shortcode_option_name = '';
	protected $post = null;
	protected $query = null;

	// setup the actions and filters for the shortcode
	public function __construct() {
		do_action( 'qsot-register-shortcode', array(
			'code' => array( &$this, 'get_code' ),
			'render' => array( &$this, 'render_wrapper' ),
			'options' => array( &$this, 'options' ),
			'form' => array( &$this, 'form' ),
		) );
	}

	// draw the output of the shortcode. requires overload
	abstract public function render( $atts='', $inner_content='', $tag='' );

	// sets up the options for this shortcode. requires overloaded
	abstract public function options( &$options, $order_index );

	// settings form for generating the shortcode
	abstract public function form( $vars='' );

	// function that figures out the shortcode's code
	public function get_code( &$options ) {
		return $options->{ $this->shortcode_option_name };
	}

	// render the output of the shortcode
	public function render_wrapper( $atts='', $inner_content='', $tag='' ) {
		// start the buffer
		ob_start();

		// generate the output
		$this->render( $atts, $inner_content, $tag );

		// fetch the contents of the output
		$out = ob_get_contents();
		ob_end_clean();

		// return the contents for replacement in the string
		return $out;
	}

	// stash the current wp_query and post, which can be restored later
	protected function stash() {
		$this->query = is_object( $GLOBALS['wp_query'] ) ? clone $GLOBALS['wp_query'] : null;
		$this->post = is_object( $GLOBALS['post'] ) ? clone $GLOBALS['post'] : null;
	}

	// restore the previous query and post
	protected function unstash() {
		// restore the wp_query and post objects from before the stash
		if ( null !== $this->query )
			$GLOBALS['wp_query'] = $this->query;
		if ( null !== $this->post )
			$GLOBALS['post'] = $this->post;

		// re-initialize the current post
		setup_postdata( $GLOBALS['post'] );
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_DO_shortcodes::pre_init();
