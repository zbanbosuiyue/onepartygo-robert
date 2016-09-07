<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// class to handle the generation and output of a list of specific events, in shortcode form
class QSOT_Featured_Event_Shortcode extends QSOT_base_shortcode {
	protected static $protect = false;
	protected static $defaults = array();
	protected $shortcode_default_code = 'featured-event';
	protected $shortcode_option_name = 'qsot-do-featured-event-shortcode';

	// singleton
	protected static $instance = null;
	public static function instance( $force=false ) { return ( $force || null === self::$instance ) ? ( self::$instance = new QSOT_Featured_Event_Shortcode() ) : self::$instance; }

	// overtake the parent construct
	public function __construct() {
		self::$defaults = ! empty( self::$defaults ) ? self::$defaults : array(
			'title' => '',
			'format' => 'list',
			'id' => '',
			'show_desc' => 1,
			'order' => 'asc',
			'show_image' => 1,
			'date_format' => __( 'F jS, Y @ h:ia', 'qsot-display-options' ),
			'time_format' => __( 'h:ia', 'qsot-display-options' ),
			'meta' => 'date,price,availability',
		);
		parent::__construct();
	}

	// render the output of the shortcode
	public function render( $atts='', $inner_content='', $tag='' ) {
		// protect this shortcode from recursion
		if ( self::$protect )
			return;
		self::$protect = true;

		// normalize the args
		$atts = shortcode_atts( self::$defaults, $atts, $tag );

		// extra params
		$date_format = $atts['date_format'];
		$time_format = $atts['time_format'];
		$show_image = $atts['show_image'];
		$show_meta = array_filter( array_map( 'trim', explode( ',', strtolower( $atts['meta'] ) ) ) );
		$show_desc = $atts['show_desc'];
		$format = $atts['format'];

		// find the appropriate template to render the results
		$template = apply_filters( 'qsot-locate-template', '', array( 'shortcodes/featured-event.' . $atts['format'] . '.php' ), false, false );
		// try to fallback to a default template
		$template = empty( $template ) ? apply_filters( 'qsot-locate-template', '', array( 'shortcodes/featured-event.php' ), false, false ) : $template;
		// if missing, bail
		if ( empty( $template ) )
			return;

		// find the list of ids to display events for. if there are none, bail
		if ( ! ( $ids = wp_parse_id_list( $atts['id'] ) ) )
			return;

		// setup the args that will be used to pull a list of events to display
		$args = $this->_get_args( apply_filters( 'qsot-do-featured-event-args-before', array(
			'post_type' => 'qsot-event',
			'post_status' => array( 'publish' ),
			'post__in' => $ids,
			'orderby' => 'post__in',
			'order' => 'asc',
		), $atts ), $atts );

		// start a loop
		query_posts( $args );

		// if there are none to show, bail
		if ( ! have_posts() ) {
			wp_reset_query();
			self::$protect = false;
			return;
		}

		// fix the display of the event entries
		add_action( 'qsot-before-featured-event', array( &$this, 'add_modify_post_class' ), 1, 1 );
		add_action( 'qsot-after-featured-event', array( &$this, 'remove_modify_post_class' ), 1, 1 );

		// draw the results
		include $template;

		// reset the global query to avoid any problems later in the page load
		wp_reset_query();

		// remove the post class modifications
		remove_action( 'qsot-before-featured-event', array( &$this, 'add_modify_post_class' ), 1 );
		remove_action( 'qsot-after-featured-event', array( &$this, 'remove_modify_post_class' ), 1 );

		self::$protect = false;
	}

	// simple settings form for generating the shortcode
	public function form( $vars='' ) {
		// normalize the vars that were passed
		$vars = wp_parse_args( $vars, array( 'args' => array(), 'fields' => array() ) );
		$vars['args'] = wp_parse_args( $vars['args'], array( 'show_title' => false ) );
		foreach ( self::$defaults as $k => $v ) {
			$vars['fields'][ $k ] = wp_parse_args( isset( $vars['fields'][ $k ] ) ? $vars['fields'][ $k ] : array(), array(
				'name' => $k,
				'id' => $k,
				'value' => $v,
			) );
		}

		// construct a list of available formats for this shortcode
		$formats = apply_filters(
			'qsot-do-featured-event-formats',
			array(
				'list' => __( 'List Style', 'qsot-display-options' ),
			)
		);

		// draw the form
		?>
			<?php if ( $vars['args']['show_title'] ): ?>
				<div class="field">
					<label><?php _e( 'Title', 'qsot-display-options' ) ?></label>
					<input type="text" class="widefat field-val" rel="title" data-default="<?php echo esc_attr( self::$defaults['title'] ) ?>"
							value="<?php echo esc_attr( $vars['fields']['title']['value'] ) ?>"
							name="<?php echo $vars['fields']['title']['name'] ?>"
							id="<?php echo $vars['fields']['title']['id'] ?>" />
					<div class="helper"><?php _e( 'The title displayed at the top of the widget.', 'qsot-display-options' ) ?></div>
				</div>
			<?php endif; ?>

			<?php if ( count( $formats ) ): ?>
				<div class="field">
					<label><?php _e( 'Display Layout', 'qsot-display-options' ) ?></label>
					<select class="widefat field-val" rel="format" data-default="<?php echo esc_attr( self::$defaults['format'] ) ?>"
							name="<?php echo $vars['fields']['format']['name'] ?>"
							id="<?php echo $vars['fields']['format']['id'] ?>">
						<?php foreach ( $formats as $format => $label ): ?>
							<option value="<?php echo esc_attr( $format ) ?>" <?php selected( $format, $vars['fields']['format']['value'] ) ?>><?php echo force_balance_tags( $label ) ?></option>
						<?php endforeach; ?>
					</select>
					<div class="helper"><?php _e( 'The template to use for the layout of the events in the widget.', 'qsot-display-options' ) ?></div>
				</div>
			<?php endif; ?>

			<div class="field">
				<label><?php _e( 'Events to Show', 'qsot-display-options' ) ?></label>
				<input type="button" class="find-posts-btn button" role="find-posts-btn" scope=".field" list=".posts-list" selected-name="<?php echo $vars['fields']['id']['name'] ?>[]"
						value="<?php _e( 'Add Events', 'qsot-display-options' ) ?>" />
				<div id="<?php echo $vars['fields']['id']['id'] ?>" data-field="<?php echo $vars['fields']['id']['name'] ?>"
						class="field-val events-list posts-list use-sortable" data-default="<?php echo esc_attr( self::$defaults['id'] ) ?>"><?php
						$ids = array_filter( wp_parse_id_list( $vars['fields']['id']['value'] ) ); foreach ( $ids as $id ): ?>
					<div class="item" role="item">
						<input type="hidden" name="<?php echo $vars['fields']['id']['name'] ?>[]" value="<?php echo absint( $id ) ?>" role="item-id" />
						<div role="item-thumb" class="item-thumb"><?php echo get_the_post_thumbnail( $id, 'thumbnail' ) ?></div>
						<div role="item-title" title="<?php echo esc_attr( get_the_title( $id ) ) ?>" class="item-title"><?php echo get_the_title( $id ) ?></div>
						<div role="remove-btn" class="remove-btn">X</div>
					</div>
				<?php endforeach; ?></div>
			</div>

			<div class="field">
				<label><?php _e( 'Show Event Image', 'qsot-display-options' ) ?></label>
				<input type="hidden" value="0" name="<?php echo $vars['fields']['show_image']['name'] ?>" />
				<span class="cb-wrap">
					<input type="checkbox" class="widefat field-val" rel="show_image" value="1" data-default="<?php echo esc_attr( (int)self::$defaults['show_image'] ) ?>"
							<?php checked( 1, (int)$vars['fields']['show_image']['value'] ) ?>
							name="<?php echo $vars['fields']['show_image']['name'] ?>"
							id="<?php echo $vars['fields']['show_image']['id'] ?>"
					/> <span class="cb-text"><?php _e( 'Yes, show the featured image of the event.', 'qsot-display-options' ) ?></span>
				</span>
				<div class="helper"><?php _e( 'When checked, the featured image of the event (or parent event) will be displayed in the output.', 'qsot-display-options' ) ?></div>
			</div>

			<div class="field">
				<label><?php _e( 'Date Format', 'qsot-display-options' ) ?></label>
				<input type="text" class="widefat field-val" rel="date_format" data-default="<?php echo esc_attr( self::$defaults['date_format'] ) ?>"
						value="<?php echo esc_attr( $vars['fields']['date_format']['value'] ) ?>"
						name="<?php echo $vars['fields']['date_format']['name'] ?>"
						id="<?php echo $vars['fields']['date_format']['id'] ?>" />
				<div class="helper"><?php _e( 'The PHP date format to use when rendering the start (and maybe end) dates of the event.', 'qsot-display-options' ) ?></div>
			</div>

			<div class="field">
				<label><?php _e( 'Time Format', 'qsot-display-options' ) ?></label>
				<input type="text" class="widefat field-val" rel="time_format" data-default="<?php echo esc_attr( self::$defaults['time_format'] ) ?>"
						value="<?php echo esc_attr( $vars['fields']['time_format']['value'] ) ?>"
						name="<?php echo $vars['fields']['time_format']['name'] ?>"
						id="<?php echo $vars['fields']['time_format']['id'] ?>" />
				<div class="helper"><?php _e( 'The PHP time format to use when rendering the ending time, when events start and end on the same day.', 'qsot-display-options' ) ?></div>
			</div>

			<div class="field">
				<label><?php _e( 'Event Meta and Order', 'qsot-display-options' ) ?></label>
				<input type="text" class="widefat field-val" rel="meta" data-default="<?php echo esc_attr( self::$defaults['meta'] ) ?>"
						value="<?php echo esc_attr( $vars['fields']['meta']['value'] ) ?>"
						name="<?php echo $vars['fields']['meta']['name'] ?>"
						id="<?php echo $vars['fields']['meta']['id'] ?>" />
				<div class="helper"><?php _e( 'Comma separated list of event meta to display, and in what order, for "list" layout only. Values: ', 'qsot-display-options' ) ?><code>date, price, availability</code></div>
			</div>

			<?php do_action( 'qsot-do-featured-event-form', $vars ) ?>
		<?php
	}

	// add the post class modifications for the output of our post_classes
	public function add_modify_post_class( $format ) {
		add_filter( 'post_class', array( &$this, 'modify_post_class' ), PHP_INT_MAX, 1 );
	}

	// remove the post class modifications for the output of our post_classes
	public function remove_modify_post_class( $format ) {
		remove_filter( 'post_class', array( &$this, 'modify_post_class' ), PHP_INT_MAX );
	}

	// remove the most conflicting post classes for our event list displays
	public function modify_post_class( $classes ) {
		$out = array();

		// remove the most conflicting items from the post class list for our upcoming event displays
		foreach ( $classes as $class )
			if ( ! in_array( $class, array( 'entry', 'hentry' ) ) )
				$out[] = $class;

		return $out;
	}

	// determine the query_post args, based on the supplied attributes
	protected function _get_args( $args, $atts ) {
		// determine the sort order
		$order = trim( strtolower( $atts['order'] ) );
		if ( 'rand' == $order ) {
			$args['orderby'] = 'rand';
			$args['order'] = 'asc';
		} else {
			$args['order'] = in_array( $order, array( 'desc', 'asc' ) ) ? $order : 'asc';
		}

		return apply_filters( 'qsot-do-featured-event-args', $args, $atts );
	}

	// sets up the options for this shortcode
	public function options( &$options, $order_index ) {
		// setup the default values
		$options->def( $this->shortcode_option_name, $this->shortcode_default_code );

		// allows changing of the shortcode code, in case there is a clashing shortcode already, for the upcoming-events shortcode
		$options->add( array(
			'order' => $order_index,
			'id' => $this->shortcode_option_name,
			'type' => 'text',
			'title' => __( 'Featured Event Shortcode', 'qsot-display-options' ),
			'desc' => __( 'The shortcode "tag" that is used to display a (or a list of) featured event(s).', 'qsot-display-options' ),
			'desc_tip' => __( 'Changes the shortcode tag, in case you have a conflicting tag installed already.', 'qsot-display-options' ),
			'default' => $this->shortcode_default_code,
			'page' => 'display-options',
		) );

		// update the internal tag for the shortcode
		$this->shortcode_default_code = $options->{ $this->shortcode_option_name };
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	return new QSOT_Featured_Event_Shortcode();
