<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// loader for all the widgets for this extension
class QSOT_DO_widgets {
	// holder for otce plugin settings
	protected static $o = null;
	protected static $options = null;
	
	// settings order index for dynamic settings
	protected static $order_index = 201;

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

		// widget related assets
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'load_admin_assets' ), 10 );

		// load all widgets
		do_action( 'qsot-load-includes', 'widgets', '#^.+\.widget\.php$#i');

		// ui settings
		add_filter( 'qsot-display-options-ui-msgs', array( __CLASS__, 'ui_messages' ), 10, 2 );
		add_filter( 'qsot-display-options-ui-templates', array( __CLASS__, 'ui_templates' ), 10, 2 );
		add_action( 'admin_footer-widgets.php', array( __CLASS__, 'modal_templates' ) );
	}

	// register the assets that could be used in the admin
	public static function register_admin_assets() {
		// reused vars
		$url = QSOT_display_options_launcher::plugin_url();
		$version = QSOT_display_options_launcher::version();

	}

	// load the appropriate admin assets, depending on the current page
	public static function load_admin_assets( $hook ) {
		// if on the widgets page in the admin
		if ( 'widgets.php' == $hook ) {
			wp_enqueue_style( 'qsot-do-admin-widgets' );
			wp_enqueue_script( 'qsot-do-find-posts-box' );
			wp_localize_script( 'qsot-do-utils', '_qsot_do_settings', array(
				'nonce' => wp_create_nonce( 'display-options-ajax' ),
				'msgs' => apply_filters( 'qsot-display-options-ui-msgs', array(), 0 ),
				'templ' => apply_filters( 'qsot-display-options-ui-templates', array(), 0 ),
			) );
		}
	}

	// load the modal templates used on the widgets screen
	public static function modal_templates() {
		do_action( 'qsot-display-options-fpb-modal-templates' );
	}

	// list of translatable messages and their translations for the js interface
	public static function ui_messages( $list, $post_id ) {
		// new list of messages
		$new_list = array();

		return array_merge( $list, $new_list );
	}

	// list of templates used by the js interface
	public static function ui_templates( $list, $post_id ) {
		// new list of templates
		$new_list = array(
			'list-item' => '<div class="item" role="item">'
					. '<input type="hidden" name="" value="" role="item-id" />'
					. '<div role="item-thumb" class="item-thumb"></div>'
					. '<div role="item-title" class="item-title"></div>'
					. '<div role="remove-btn" class="remove-btn">X</div>'
				. '</div>',

			'result-item' => '<div class="item" role="item">'
					. '<div role="item-type" class="item-post-type"></div>'
					. '<div role="item-thumb" class="item-thumb"></div>'
					. '<div role="item-title" class="item-title"></div>'
					. '<div class="clear"></div>'
				. '</div>',
		);

		return array_merge( $list, $new_list );
	}

	// setup the options that are available to control our 'Display Options'
	protected static function _setup_admin_options() {
	}
}

abstract class QSOT_DO_base_widget extends WP_Widget {
	protected $short_name = '';
	protected $long_name = '';
	protected $defaults = array( 'title' => '' );
	protected $exclude = array( 'title' );

	// draw the output of the widget. requires overload
	abstract public function render( $args, $instance );

	// draw the form for the widget settings
	abstract public function render_form( $instance='' );

	// function that normalizes the widget args and passes them to the actual function that renders the widget settings form
	public function form( $instance='' ) {
		// normalize the widget instance params
		$instance = $this->_normalize_instance( $instance );

		// wrap the form in a styling div
		echo '<div class="widget-settings">';

		// call the render function
		$this->render_form( $instance );

		// end wrapper
		echo '</div>';
	}

	// function that wraps the output of the widget in the widget shell, if there is any output
	public function widget( $args='', $instance='' ) {
		// normalize the args and instance vars
		$args = wp_parse_args( $args, array(
			'before_widget' => '<div class="widget %s">',
			'before_title' => '<h3 class="widgettitle">',
			'after_title' => '</h3>',
			'after_widget' => '</div>',
		) );
		$instance = $this->_normalize_instance( $instance );

		// start a buffer to capture the output of the widget
		ob_start();

		// fetch the output of the widget
		$this->render( $args, $instance );

		// get the output of the widget
		$output = trim( ob_get_contents() );
		ob_end_clean();

		// if the widget generated output, then actually print that output, wrapped in our widget structure
		if ( '' !== $output ) {
			// open the widget shell
			echo $args['before_widget'];

			// if there is a title, then render it, wrapped in the title shell
			if ( isset( $instance['title'] ) && '' !== trim( $instance['title'] ) )
				echo $args['before_title'], force_balance_tags( $instance['title'] ), $args['after_title'];

			// wrap our widget output in a different diff, because it makes it easier to style
			echo '<div class="widget-inside">', $output, '</div>';

			// close the widget shell
			echo $args['after_widget'];
		}
	}

	// generic update function, called when saving the widget
	public function update( $new_instance='', $old_instance='' ) {
		return $this->_normalize_instance( wp_parse_args( $new_instance, $old_instance ) );
	}

	// use a tag_name and list of atts to construct a shortcode that can be used to render some output
	protected function _construct_shortcode_tag( $tag_name, $atts='' ) {
		// normalize the args
		$atts = wp_parse_args( $atts, array() );
		$tag_name = trim( $tag_name );

		// if the tag_name is empty, bail
		if ( '' === $tag_name )
			return '';

		// organize the atts into a manageable list that can be concatonated to construct the shortcode
		$atts_pairs = array();
		foreach ( $atts as $k => $v ) {
			if ( is_scalar( $v ) )
				$atts_pairs[] = $k . '="' . esc_attr( $v ) . '"';
			else if ( is_array( $v ) )
				$atts_pairs[] = $k . '="' . esc_attr( implode( ',', $v ) ) . '"';
		}

		// finalize the tag
		return '[' . $tag_name . ' ' . implode( ' ', $atts_pairs ) . ']';
	}

	// generic special rules function
	protected function _special_normalize( $instance ) { return $instance; }

	// normalize the parameters for the given instance array
	protected function _normalize_instance( $instance ) {
		// overlay the instance on top of the widget defaults
		$instance = wp_parse_args( $instance, $this->defaults );

		// other widgets can have some special rules, without having to redfine the entire set
		$instance = $this->_special_normalize( $instance );

		// strip tags from all parameters
		$instance = $this->_strip_tags( $instance );

		return $instance;
	}

	// remove all tags from all params, except those in the exclusion list
	protected function _strip_tags( $instance, $exclude='' ) {
		// normalize the exclude list
		$exclude = is_scalar( $exclude ) ? array_filter( array_map( 'trim', explode( ',', $exclude ) ) ) : $exclude;
		$exclude = array_merge( $this->exclude, (array)$exclude );
		$exclude = array_combine( $exclude, array_fill( 0, count( $exclude ), 1 ) );

		return $this->_strip_tags_r( $instance, $exclude );
	}

	// recursively strip tags from all values, except those who's keys are in the exclude list
	protected function _strip_tags_r( $instance, $exclude, $base_key='' ) {
		// cycle through the key value pairs
		foreach ( $instance as $k => &$v ) {
			// if this key is not marked to be skipped, and if the wildcard at this key level is not marked to be skipped, then clean the key
			if ( ! isset( $exclude[ $base_key . $k ], $exclude[ $base_key . '*' ] ) ) {
				// if this value is an array or object, then recurse
				if ( ! is_scalar( $v ) ) {
					$v = $this->_strip_tags_r( $v, $exclude, $k . '.' );
				// otherwise, strip tags from this scalar value
				} else {
					$v = strip_tags( $v );
				}
			}
		}

		return $instance;
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_DO_widgets::pre_init();
