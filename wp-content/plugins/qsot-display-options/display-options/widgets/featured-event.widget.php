<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// the featured event widget
class QSOT_Featured_Event_Widget extends QSOT_DO_base_widget {
	// setup this widget and it's filters/actions
	public static function pre_init() {
		// if the widgets have already been registered, then do this one late
		if ( did_action( 'widgets_init' ) )
			self::register();
		// otherwise, set it to register itself when the other widgets do
		else
			add_action( 'widgets_init', array( __CLASS__, 'register' ) );
	}

	// register this widget when appropriate
	public static function register() {
		register_widget( __CLASS__ );
	}

	// default settings
	protected $defaults = array(
		'title' => '',
		'id' => '',
		'format' => 'list',
		'date_format' => '',
		'time_format' => '',
		'show_image' => 1,
		'meta' => 'date,price,availability',
		'columns' => 'date,title,price,availability',
	);

	// start and instance of this widget
	public function __construct( $id_base='', $name='', $widget_options=array(), $control_options=array() ) {
		// name the widget
		$this->short_name = 'featured-event';
		$this->long_name = __( 'Featured Event', 'qsot-display-options' );

		// update defaults with i18n
		$this->defaults['title'] = $this->long_name;
		$this->defaults['date_format'] = __( 'F jS, Y @ h:ia', 'qsot-display-options' );
		$this->defaults['time_format'] = __( 'h:ia', 'qsot-display-options' );

		parent::__construct( $id_base, $this->long_name, $widget_options, $control_options );
	}

	// render the widget itself
	public function render( $args, $instance ) {
		// construct a nice atts array
		$atts = array(
			'format' => $instance['format'],
			'id' => implode( ',', array_filter( wp_parse_id_list( $instance['id'] ) ) ),
			'show_image' => $instance['show_image'],
			'date_format' => $instance['date_format'],
			'time_format' => $instance['time_format'],
			'meta' => $instance['meta'],
			'columns' => $instance['columns'],
		);

		// get the tagname for the appropriate shortcode
		$tag_name = apply_filters( 'qsot-settings', $this->short_name, 'qsot-do-featured-event-shortcode' );

		// construct a shortcode tag based off the atts and tag_name
		$tag = $this->_construct_shortcode_tag( $tag_name, $atts );

		// if there is no tag, bail
		if ( '' === $tag )
			return;

		// render the widget, leveraging the shortcode
		echo do_shortcode( $tag );
	}

	// render the widget form
	public function render_form( $instance='' ) {
		// setup the form args shell
		$args = array(
			'args' => array(
				'show_title' => true,
			),
			'fields' => array(),
		);

		// construct the form args for each piece of required data
		foreach ( $this->defaults as $k => $_ ) {
			$args['fields'][ $k ] = array(
				'name' => $this->get_field_name( $k ),
				'id' => $this->get_field_id( $k ),
				'value' => $instance[ $k ],
			);
		}

		// render the settings form
		$uniq = uniqid( 'has-sortables-' );
		echo '<div id="' . $uniq . '">';
		QSOT_Featured_Event_Shortcode::instance()->form( $args );
		?></div>
			<script language="javascript">
				if ( ( typeof jQuery == 'function' || typeof jQuery == 'object' ) && null !== jQuery && ( typeof jQuery.fn.sortable == 'function' || typeof jQuery.fn.sortable == 'object' ) && null !== jQuery.fn.sortable )
					jQuery( '#<?php echo $uniq ?> .use-sortable' ).sortable();
			</script>
		<?php
	}

	// handle some special fields
	protected function _special_normalize( $instance ) {
		// normalize the id list
		$instance['id'] = implode( ',', array_filter( wp_parse_id_list( $instance['id'] ) ) );
		return $instance;
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_Featured_Event_Widget::pre_init();
