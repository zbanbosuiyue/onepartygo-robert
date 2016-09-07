<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// the upcoming events widget
class QSOT_Upcoming_Events_Widget extends QSOT_DO_base_widget {
	// register this widget when appropriate
	public static function register() {
		register_widget( __CLASS__ );
	}

	// default settings
	protected $defaults = array(
		'title' => '',
		'limit' => 20,
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
		$this->short_name = 'upcoming-events';
		$this->long_name = __( 'Upcoming Events', 'qsot-display-options' );

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
			'limit' => $instance['limit'],
			'show_image' => $instance['show_image'],
			'date_format' => $instance['date_format'],
			'time_format' => $instance['time_format'],
			'meta' => $instance['meta'],
			'columns' => $instance['columns'],
		);

		// get the tagname for the appropriate shortcode
		$tag_name = apply_filters( 'qsot-settings', $this->short_name, 'qsot-do-upcoming-events-shortcode' );

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
				'show_range' => false,
				'show_parent' => false,
				'show_order' => false,
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
		QSOT_Upcoming_Events_Shortcode::instance()->form( $args );
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) ) {
	// if the widgets have already been registered, then do this one late
	if ( did_action( 'widgets_init' ) )
		QSOT_Upcoming_Events_Widget::register();
	// otherwise, set it to register itself when the other widgets do
	else
		add_action( 'widgets_init', array( 'QSOT_Upcoming_Events_Widget', 'register' ) );
}
