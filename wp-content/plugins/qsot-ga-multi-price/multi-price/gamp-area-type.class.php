<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// class to handle the GAMP event area type
class QSOT_GAMP_Area_Type extends QSOT_General_Admission_Area_Type {
	// container for the singleton instance
	protected static $instance = array();

	// get the singleton instance
	public static function instance() {
		// if the instance already exists, use it
		if ( isset( self::$instance ) && self::$instance instanceof QSOT_GAMP_Area_Type )
			return self::$instance;

		// otherwise, start a new instance
		return self::$instance = new QSOT_GAMP_Area_Type();
	}

	// constructor. handles instance setup, and multi instance prevention
	public function __construct() {
		// if there is already an instance of this object, then bail now
		if ( isset( self::$instance ) && self::$instance instanceof QSOT_GAMP_Area_Type )
			throw new Exception( sprintf( __( 'There can only be one instance of the %s object at a time.', 'opentickets-community-edition' ), __CLASS__ ), 12000 );

		// otherwise, set this as the known instance
		self::$instance = $this;

		// and call the intialization function
		$this->initialize();
	}

	// destructor. handles instance destruction
	public function __destruct() {
		$this->deinitialize();
	}


	// setup the object
	public function initialize( $ajax=true ) {
		// defaults from parent
		parent::initialize( false );

		// setup the object description
		$this->priority = 10;
		$this->find_priority = 10000;
		$this->slug = 'gamp';
		$this->name = __( 'General Admission Multi-Price', 'qsot-ga-multi-price' );

		// after all the plugins have loaded, register this type
		if ( ! did_action( 'plugins_loaded' ) )
			add_action( 'plugins_loaded', array( &$this, 'plugins_loaded' ), 10 );
		else {
			require_once QSOT_Multi_Price_Launcher::plugin_dir() . 'multi-price/price-struct.class.php';
			$this->plugins_loaded();
		}

		// actions to help sync the cart with the actions we take in the event ticket UI
		add_action( 'woocommerce_before_cart_item_quantity_zero', array( &$this, 'delete_ticket_from_cart' ), 10, 1 );
		add_action( 'woocommerce_cart_item_removed', array( &$this, 'delete_ticket_from_cart' ), 10, 1 );

		// augment the zoner search
		add_filter( 'qsot-zoner-query-find-query-parts', array( &$this, 'find_query_for_custom_fields' ), 10, 2 );
		add_filter( 'qsot-zoner-query-return-total-ticket-type-state', array( &$this, 'find_query_return_ticket_type_state' ), 10, 2 );

		if ( $ajax ) {
			// add the gaea ajax handlers
			$aj = QSOT_Ajax::instance();
			$aj->register( 'gamp-reserve', array( &$this, 'aj_reserve' ), array(), null, 'qsot-frontend-ajax' );
			$aj->register( 'gamp-remove', array( &$this, 'aj_remove' ), array(), null, 'qsot-frontend-ajax' );
			$aj->register( 'gamp-update', array( &$this, 'aj_update' ), array(), null, 'qsot-frontend-ajax' );

			// register our admin ajax functions
			$aj->register( 'gamp-add-tickets', array( &$this, 'admin_ajax_add_tickets' ), array( 'edit_shop_orders' ), null, 'qsot-admin-ajax' );
			$aj->register( 'gamp-update-ticket', array( &$this, 'admin_ajax_update_ticket' ), array( 'edit_shop_orders' ), null, 'qsot-admin-ajax' );
		}
	}

	// destroy the object
	public function deinitialize() {
		remove_action( 'switch_blog', array( &$this, 'setup_table_names' ), PHP_INT_MAX );
		remove_action( 'plugins_loaded', array( &$this, 'plugins_loaded' ), 10 );
		remove_filter( 'qsot-ticket-item-meta-keys', array( &$this, 'meta_keys_maintained' ), 10 );
		remove_filter( 'qsot-ticket-item-hidden-meta-keys', array( &$this, 'meta_keys_hidden' ), 10 );
	}

	// register this area type after all plugins have loaded
	public function plugins_loaded() {
		// register this as an event area type
		do_action_ref_array( 'qsot-register-event-area-type', array( &$this ) );

		// load the pricing structure handler
		$this->price_struct = QSOT_GAMP_Price_Struct::instance();
	}

	// register the assets we may need in either the admin or the frontend, for this area_type
	public function register_assets() {
		// reusable data
		$url = QSOT_Multi_Price_Launcher::plugin_url();
		$version = QSOT_Multi_Price_Launcher::version();

		// register styles and scripts
		wp_register_style( 'qsot-gamp-event-frontend', $url . 'assets/css/frontend/ui.css', array( 'qsot-gaea-event-frontend' ), $version );
		wp_register_script( 'qsot-gamp-event-frontend', $url . 'assets/js/frontend/ui.js', array( 'qsot-tools' ), $version );

		if ( is_admin() ) {
			// admin scripts
			wp_register_script( 'qsot-gamp-event-settings', $url . 'assets/js/event-settings.js', array( 'qsot-events-admin-edit-page', 'qsot-admin-tools' ), $version );
			wp_register_script( 'qsot-gamp-event-area-admin', $url . 'assets/js/event-area-ui.js', array( 'qsot-event-area-admin', 'jquery-ui-sortable', 'jquery-ui-droppable' ), $version );
			wp_register_script( 'qsot-gamp-ticket-selection', $url . 'assets/js/ticket-selection.js', array( 'qsot-admin-ticket-selection' ), $version );

			// admin styles
			wp_register_style( 'qsot-gamp-ticket-selection', $url . 'assets/css/ticket-selection.css', array(), $version );
			wp_register_style( 'qsot-gamp-event-area-admin', $url . 'assets/css/event-area-ui.css', array(), $version );
		}
	}

	// enqueue the appropriate assets for the frontend
	public function enqueue_assets( $event ) {
		// if we do not have the required info, then bail
		if ( ! is_object( $event ) || ! isset( $event->event_area ) || ! is_object( $event->event_area ) )
			return;

		// if this event is not using an event area of this type, then bail now
		if ( ! isset( $event->event_area->area_type ) || ! is_object( $event->event_area->area_type ) || $this->slug !== $event->event_area->area_type->get_slug() )
			return;

		// include the base styling
		wp_enqueue_style('qsot-gamp-event-frontend');

		// get the zoner for this area type
		$zoner = $this->get_zoner();

		// get the valid stati for that zoner
		$stati = $zoner->get_stati();

		// enqueue the frontend event ui scrit
		wp_enqueue_script( 'qsot-gamp-event-frontend' );

		// are we allowed to show the available quantity?
		$show_qty = 'yes' == apply_filters( 'qsot-get-option-value', 'yes', 'qsot-show-available-quantity' );

		// get the price struct used by this event
		$prices = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );
		$prices = isset( $prices['0'] ) ? $prices['0'] : array();

		// setup the settings we need for that script to run
		wp_localize_script( 'qsot-gamp-event-frontend', '_qsot_gamp_tickets', apply_filters( 'qsot-event-frontend-settings', array(
			'nonce' => wp_create_nonce( 'do-qsot-frontend-ajax' ),
			'edata' => $this->_get_frontend_event_data( $event ),
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'templates' => $this->get_templates( $event ),
			'messages' => array(
				'available' => array(
					'msg' => ( $show_qty )
							? __( 'There are currently <span class="available"></span> <span rel="tt"></span> available.', 'opentickets-community-edition' )
							: str_replace( '<span class="available"></span> ', '', __( 'There are currently <span class="available"></span> <span rel="tt"></span> available.', 'opentickets-community-edition' ) ),
					'type' => 'msg'
				),
				'more-available' => array(
					'msg' => ( $show_qty )
							? __( 'There are currently <span class="available"></span> more <span rel="tt"></span> available.', 'opentickets-community-edition' )
							: str_replace( '<span class="available"></span> ', '', __( 'There are currently <span class="available"></span> <span rel="tt"></span> available.', 'opentickets-community-edition' ) ),
					'type' => 'msg'
				),
				'not-available' => array( 'msg' => __( 'We\'re sorry. There are currently no tickets available.', 'opentickets-community-edition' ), 'type' => 'error' ),
				'sold-out' => array( 'msg' => __( 'We are sorry. This event is sold out!', 'opentickets-community-edition' ), 'type' => 'error' ),
				'one-moment' => array( 'msg' => __( '<h1>One Moment Please...</h1>', 'opentickets-community-edition' ), 'type' => 'msg' ),
			),
			'owns' => $zoner->find( array(
				'fields' => 'total-by-ticket-type',
				'state' => $stati['r'][0],
				'event_id' => $event->ID,
				'ticket_type_id' => $prices,
				'customer_id' => $zoner->current_user(),
			) ),
		), $event ) );
	}

	// enqueue the assets we need in the admin for this area type
	public function enqueue_admin_assets( $type=null, $exists=false, $post_id=0 ) {
		switch ( $type ) {
			case 'qsot-event-area':
				// get a list of sll ticket products
				$all = apply_filters( 'qsot-get-all-ticket-products', array() );

				$tickets = array();
				// convert that list into a name and id list
				while ( count( $all ) ) {
					$ticket = array_shift( $all );
					$tickets[] = array( 'id' => $ticket->id, 'name' => sprintf( '%s (%s)', $ticket->post->proper_name, $ticket->post->meta['price_html'] ) );
				}

				$ea = $area_type = false;
				// load the event area object
				if ( $exists && $post_id > 0 ) {
					$ea = apply_filters( 'qsot-get-event-area', false, $post_id );
					$area_type = is_object( $ea ) && ! is_wp_error( $ea ) ? $ea->area_type : false;
				}

				$structs = array();
				// get a list of the structs used by this event_area
				if ( is_object( $area_type ) && $area_type->get_slug() == $this->slug && $post_id > 0 )
					$structs = $this->price_struct->get_by_event_area_id( $post_id, array( 'price_list_format' => 'ids', 'price_sub_group' => 0 ) );

				// enqueue the extra event area admin script for the gamp area type
				wp_enqueue_script( 'qsot-gamp-event-area-admin' );
				wp_localize_script( 'qsot-gamp-event-area-admin', '_qsot_gamp_settings', apply_filters( 'qsot-gamp-event-area-ui-settings', array(
					'str' => array(
						'prices' => __( 'prices', 'qsot' ),
						'price' => __( 'price', 'qsot' ),
					),
					'templates' => $this->get_admin_templates( array(), 'event-area', array( 'exists' => $exists, 'post_id' => $post_id ) ),
					'tickets' => $tickets,
					'structs' => (object)$structs,
					'nonce' => wp_create_nonce( 'save-qsot-gamp-now' ),
				) ) );

				// enqueue the styles for it too
				wp_enqueue_style( 'qsot-gamp-event-area-admin' );
			break;

			case 'qsot-event':
				wp_enqueue_script( 'qsot-gamp-event-settings' );
			break;

			case 'shop_order':
				wp_enqueue_style( 'qsot-gamp-ticket-selection' );
				wp_enqueue_script( 'qsot-gamp-ticket-selection' );
			break;
		}
	}

	// get the frontend template to use in the event selection ui
	public function get_templates( $event ) {
		// make sure we have an event area
		$event->event_area = isset( $event->event_area ) && is_object( $event->event_area ) ? $event->event_area : apply_filters( 'qsot-event-area-for-event', false, $GLOBALS['post'] );

		// if there is no event area, then bail
		if ( ! isset( $event->event_area ) || ! is_object( $event->event_area ) )
			return apply_filters( 'qsot-event-frontend-templates', array(), $event );

		// get a list of all the templates we need for the gamp area type
		$needed_templates = apply_filters( 'qsot-gamp-frontend-templates', array(
			'ticket-selection',
			'owns-wrap',
			'owns',
			'msgs',
			'msg',
			'error',
			'one-single-title',
			'one-multi-title',
			'two-single-title',
			'two-multi-title',
			'ticket-type-display',
			'ticket-type-single',
			'ticket-type-multi-option',
			'ticket-type-multi-select',
		), $event, $this );

		// aggregate the data needed for the templates
		$args = array(
			'limit' => apply_filters( 'qsot-event-ticket-purchase-limit', 0, $event->ID ),
			'max' => 1000000,
			'cart_url' => '#',
		);

		$cart = WC()->cart;
		// if there is a cart, then try to update the cart url
		if ( is_object( $cart ) )
			$args['cart_url'] = $cart->get_cart_url();

		// figure out the true max, based on available info
		$zoner = $this->get_zoner();
		$stati = $zoner->get_stati();
		$taken = $zoner->find( array( 'fields' => 'total', 'event_id' => $event->ID, 'state' => array( $stati['r'][0], $stati['c'][0] ) ) );
		$capacity = $event->event_area->meta['_capacity'];
		$capacity = $capacity > 0 ? $capacity : PHP_INT_MAX;
		$args['max'] = $args['limit'] > 0 ? min( $args['limit'], min( $args['max'], $capacity - $taken ) ) : min( $args['max'], $capacity - $taken );

		// allow modification of the args
		$args = apply_filters( 'qsot-gamp-frontend-templates-data', $args, $event, $this );

		$templates = array();
		// load each template in the list
		foreach ( $needed_templates as $template )
			$templates[ $template ] = QSOT_Templates::maybe_include_template( 'event-area/gamp/' . $template . '.php', $args );

		return $templates;
	}

	// get the admin templates that are needed based on type and args
	public function get_admin_templates( $list, $type, $args='' ) {
		switch ( $type ) {
			case 'ticket-selection':
				$list['gamp'] = array();

				// create a list of the templates we need
				$needed_templates = array( 'info', 'actions-change', 'actions-add', 'inner-change', 'inner-add', 'inner-ticket-type-option', 'owned-item', 'owned-none' );

				// add the needed templates to the output list
				foreach ( $needed_templates as $template )
					$list['gamp'][ $template ] = QSOT_Templates::maybe_include_template( 'admin/ticket-selection/gamp/' . $template . '.php', $args );
			break;

			case 'event-area':
				// create a list of all the templates we need in the edit event area section of the admin
				$needed_templates = array( 'shell', 'ticket-li', 'struct-li' );

				// add the needed templates to the output list
				foreach ( $needed_templates as $template )
					$list[ 'gamp-' . $template ] = QSOT_Templates::maybe_include_template( 'admin/event-area/gamp/' . $template . '.php', $args );
			break;
		}

		return $list;
	}

	// construct the data array that holds all the info we send to the frontend UI for selecting tickets
	protected function _get_frontend_event_data( $event ) {
		// get the pricing struct for this event
		$struct = $this->price_struct->get_by_event_id( $event->ID, array( 'price_sub_group' => '0' ) );

		// get our zoner for this event
		$zoner = $this->get_zoner();
		$stati = $zoner->get_stati();

		// get the ticket price for this event area
		$prices = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );
		$prices = isset( $prices['0'] ) ? $prices['0'] : array();

		// determine the total number of sold or reserved seats, thus far
		$reserved_or_confirmed = $zoner->find( array( 'fields' => 'total', 'state' => array( $stati['r'][0], $stati['c'][0] ), 'event_id' => $event->ID, 'ticket_type' => $prices ) );

		// figure out how many that leaves for the picking
		$cap = isset( $event->event_area->meta, $event->event_area->meta['_capacity'] ) ? $event->event_area->meta['_capacity'] : 0;
		$left = $cap > 0 ? max( 0, $cap - $reserved_or_confirmed ) : 1000000;

		// start putting together the results
		$out = array(
			'id' => $event->ID,
			'name' => apply_filters( 'the_title', $event->post_title, $event->ID ),
			'ticket' => false,
			'link' => get_permalink( $event->ID ),
			'parent_link' => get_permalink( $event->post_parent ),
			'capacity' => $cap,
			'available' => $left,
			'struct' => $struct,
		);

		return apply_filters( 'qsot-frontend-event-data', $out, $event );
	}

	// determine if the supplied post could be of this area type. helps determine when data is legacy data that does not have the event type set
	public function post_is_this_type( $post ) {
		// if this is not an event area, then it cannot be
		if ( 'qsot-event-area' != $post->post_type )
			return false;

		$type = get_post_meta( $post->ID, '_qsot-event-area-type', true );
		// if the area_type is set, and it is not equal to this type, then bail. this short circuits the additional expensive check below
		if ( ! empty( $type ) && $type !== $this->slug )
			return false;

		$found = null;
		// if this event_area does not have any pricing structs, then bail
		$cache = wp_cache_get( 'post-' . $post->ID, 'gamp-check', false, $found );
		if ( ( null !== $found && ! $found) || ( null === $found && false === $cache ) ) {
			$cache = $this->price_struct->find( array( 'event_area_id' => $post->ID, 'fields' => 'ids' ) );
			$cache = is_array( $cache ) ? count( $cache ) : 0;
			wp_cache_set( 'post-' . $post->ID, $cache, 'gamp-check', 3600 );
		}
		if ( empty( $cache ) )
			return false;

		// otherwise, it is
		return true;
	}

	// modify the query parts of the zoner_query, if the fields return type is our new custom type
	public function find_query_for_custom_fields( $parts, $args ) {
		// only update the query if our return type is our custom one
		if ( isset( $args['fields'] ) && 'total-ticket-type-state' == $args['fields'] ) {
			$parts['fields'] = array( 'sum(ezo.quantity) quantity', 'ezo.state', 'ezo.ticket_type_id' );
			$parts['groupby'] = array( 'ezo.ticket_type_id', 'ezo.state' );
		}

		return $parts;
	}

	// create the actual return value for the zoner_query::find() if the return type is our custom one
	public function find_query_return_ticket_type_state( $results, $args ) {
		$indexed = array();
		// cycle through the results, and add them to our indexed result list
		foreach ( $results as $item ) {
			// if the indexed ticket type container does not exist, create it
			if ( ! isset( $indexed[ $item->ticket_type_id ] ) )
				$indexed[ $item->ticket_type_id ] = array();

			// if the state sub index container does not exist, create it
			if ( ! isset( $indexed[ $item->ticket_type_id ][ $item->state ] ) )
				$indexed[ $item->ticket_type_id ][ $item->state ] = 0;

			$indexed[ $item->ticket_type_id ][ $item->state ] += $item->quantity;
		}

		return $indexed;
	}

	// get the list of metaboxes relevant for this event type
	// postbox_classes_qsot-event-area_qsot-event-area-type
	public function get_meta_boxes() {
		// if we already generated this list, then use the cached version
		if ( is_array( $this->meta_boxes ) )
			return $this->meta_boxes;

		// create a container for the metabox list
		$meta_boxes = array();

		// create a list of all the metaboxes we should add for this area type
		foreach ( apply_filters( 'qsot-gamp-attributes-screens', array( 'qsot-event-area' ) ) as $screen ) {
			$meta_boxes[] = array(
				'qsot-gamp-attributes',
				__( 'GAMP - Attributes', 'qsot-ga-multi-price' ),
				array( &$this, 'mb_attributes' ),
				$screen,
				'normal',
				'high'
			);
		}

		return $this->meta_boxes = apply_filters( 'qsot-gamp-meta-boxes', $meta_boxes );
	}

	// draw the contents of the attributes metabox
	public function mb_attributes( $post ) {
		// list of settings to fetch
		$fields = array(
			'image' => '_thumbnail_id',
			'capacity' => '_capacity',
		);

		$options = array();
		// load the options from the list above
		foreach ( $fields as $key => $meta_key )
			$options[ $key ] = get_post_meta( $post->ID, $meta_key, true );
		?>
			<div class="qsot-mb edit-area">
				<input type="hidden" name="qsot-gamp-n" value="<?php echo wp_create_nonce( 'save-qsot-gamp-now' ); ?>" />

				<div class="field edit-field area-capacity-wrap" rel="field">
					<label for="gamp-capacity"><?php _e( 'Capacity', 'opentickets-community-edition' ) ?></label>
					<input autocomplete="off" type="number" min="0" step="1" class="widefat capacity" rel="capacity" name="gamp-capacity" id="gamp-capacity" value="<?php echo esc_attr( $options['capacity'] ) ?>" />
				</div>

				<div class="field edit-field area-pricing-struct-wrap" rel="field">
					<label for="gamp-price-structs"><?php _e( 'Setup Available Pricing', 'qsot-ga-multi-price' ) ?></label>
					<div role="price-struct-ui"></div>
					<div class="clear"></div>
				</div>

				<div class="field edit-field image-select-wrap" rel="field">
					<label for="gaea-img-id"><?php _e( 'Event Area Image', 'opentickets-community-edition' ) ?></label>

					<div>
						<div class="clear"></div>
						<button class="button use-popmedia" rel="change-img" scope="[rel='field']"><?php _e( 'Select Image', 'opentickets-community-edition' ) ?></button>
						<a href="#remove-img" rel="remove-img" class="remove-img-btn" scope="[rel='field']" preview="[rel='img-wrap']"><?php _e( 'remove', 'opentickets-community-edition' ) ?></a>
					</div>

					<div>
						<div class="image-preview" size="large" rel="img-wrap"><?php echo wp_get_attachment_image( $options['image'], 'large' ) ?></div>
						<input type="hidden" name="gamp-img-id" id="gamp-img-id" value="<?php echo esc_attr( $options['image'] ) ?>" rel="img-id" />
						<div class="clear"></div>
					</div>
				</div>
			</div>
		<?php
	}

	// handle the saving of event areas of this type
	// registered during area_type registration. then called in inc/event-area/post-type.class.php save_post()
	public function save_post( $post_id, $post, $updated ) {
		// check the nonce for our settings. if not there or invalid, then bail
		if ( ! isset( $_POST['qsot-gamp-n'] ) || ! wp_verify_nonce( $_POST['qsot-gamp-n'], 'save-qsot-gamp-now' ) )
			return;

		// save all the data for this type
		update_post_meta( $post_id, '_capacity', isset( $_POST['gamp-capacity'] ) ? $_POST['gamp-capacity'] : '' );
		update_post_meta( $post_id, '_thumbnail_id', isset( $_POST['gamp-img-id'] ) ? $_POST['gamp-img-id'] : '' );

		// update the pricing structures too
		$this->_update_pricing_structs( $post_id );
	}

	// update the pricing structures upon save of the event area
	public function _update_pricing_structs( $event_area_id ) {
		// get a list of the current pricing structures for this event area
		$current = $this->price_struct->get_by_event_area_id( $event_area_id );

		$found = array();
		// create a name lookup for the structs
		$name_lookup = array();
		foreach ( $current as $struct )
			$name_lookup[ $struct->name ] = $struct->id; // can have overlaps. so what

		$lists = array( 'update' => array(), 'insert' => array() );
		// cycle through the submitted pricing structure updates, and break them into 3 groups: updates, inserts, and deletes
		if ( isset( $_POST['gamp-struct-settings'] ) ) {
			foreach ( $_POST['gamp-struct-settings'] as $raw_struct ) {
				// get the current struct we are checking
				$new_struct = @json_decode( stripslashes( $raw_struct ) );

				// update the price list to include all the needed data
				foreach ( $new_struct->prices as $ind => $product_id )
					$new_struct->prices[ $ind ] = (object)array( 'product_id' => $product_id, 'display_order' => $ind + 1, 'sub_group' => 0 );

				// figure out if this struct is an existing one
				$exists = isset( $current[ $new_struct->id ] ) ? $current[ $new_struct->id ] : false;
				$exists = false === $exists && isset( $name_lookup[ $new_struct->name ] ) ? $current[ $name_lookup[ $new_struct->name ] ] : $exists;

				// if there is no existing struct, then this is a new one
				if ( false === $exists ) {
					$lists['insert'][ $new_struct->id ] = $new_struct;
				// otherwise, this is an update to an existing one
				} else {
					$found[] = $exists->id;
					$lists['update'][ $exists->id ] = array( 'new' => $new_struct, 'old' => $exists );
				}
			}
		}

		// first, remove any structs that used to exist that do not exist any more
		$delete = array_diff( array_keys( $current ), $found );
		if ( is_array( $delete ) && count( $delete ) )
			$this->price_struct->delete_structs( $delete );

		// next handle any updates to exsiting entries
		if ( count( $lists['update'] ) )
			$this->price_struct->update_structs( $lists['update'] );

		// last, create any new structs
		if ( count( $lists['insert'] ) )
			$new_map = $this->price_struct->insert_structs( $lists['insert'], $event_area_id );
	}

	// render the frontend ui
	public function render_ui( $event, $event_area ) {
		// get the zoner for this event_area
		$zoner = $event_area->area_type->get_zoner();

		// get the zoner stati
		$stati = $zoner->get_stati();

		// figure out how many tickets we have reserved for this event currently
		$reserved = $zoner->find( array( 'fields' => 'total-by-ticket-type', 'event_id' => $event->ID, 'customer_id' => $zoner->current_user(), 'order_id' => 0, 'state' => $stati['r'][0] ) );
		$total = array_sum( array_values( $reserved ) );

		// default template
		$template_file = 'post-content/event-area-closed.php';

		// if the event can have ticket sold, or if it is sold out but this user has active reservations, then show the event ticket selection UI
		if ( apply_filters( 'qsot-can-sell-tickets-to-event', false, $event->ID ) || $cnt > 0 )
			$template_file = 'post-content/gamp-event-area.php';

		$out = '';
		// if we have the event area, then go ahead and render the appropriate interface
		if ( is_object( $event_area ) ) {
			$event_area->prices = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );
			$event_area->prices = isset( $event_area->prices['0'] ) ? $event_area->prices['0'] : array();
			$template = apply_filters( 'qsot-locate-template', '', array( $template_file, 'post-content/gamp-event-area.php' ), false, false );
			ob_start();
			if ( ! empty( $template ) )
				QSOT_Templates::include_template( $template, apply_filters( 'qsot-draw-gamp-event-area-args', array(
					'event' => $event,
					'reserved' => $reserved,
					'total_reserved' => $total,
					'area' => $event_area,
				), $event, $event_area ), true, false );
			$out = ob_get_contents();
			ob_end_clean();
		}

		// allow modification if needed
		return apply_filters( 'qsot-no-js-gamp-seat-selection-form', $out, $event_area, $event, 0, $reserved );
	}

	// get the event area display name, based on the event area and its meta
	public function get_event_area_display_name( $event_area ) {
		// get the capacity of the event_area
		$capacity = (int) get_post_meta( $event_area->ID, '_capacity', true );

		// get the number of pricing structures for this event_area
		$count = $this->price_struct->find( array( 'event_area_id' => $event_area->ID, 'fields' => 'ids', 'price_sub_group' => 0 ) );
		$count = is_array( $count ) ? count( $count ) : 0;

		// construct the final name for the event area to be displayed
		return sprintf(
			'%s [x%s] (%s)',
			apply_filters( 'the_title', $event_area->post_title, $event_area->ID ),
			$capacity,
			sprintf( _n( '%d pricing structure', '%d pricing structures', $count, 'qsot-ga-multi-price' ), $count )
		);
	}

	// determine the ticket_type for the supplied data for this area_type
	public function get_ticket_type( $data='' ) {
		// normalize the supplied data
		$data = wp_parse_args( $data, array(
			'event' => false,
			'fields' => 'objects',
		) );

		// if there is no event in the supplied, data, then bail
		if ( false == $data['event'] )
			return new WP_Error( 'invalid_event', __( 'Could not find that event.', 'qsot-ga-multi-price' ) );

		// if the event supplied is an id, try to load the area
		if ( $data['event'] && is_numeric( $data['event'] ) )
			$data['event'] = get_post( $data['event'] );

		// if there is still no event object, then bail
		if ( ! is_object( $data['event'] ) || ! isset( $data['event']->ID ) )
			return new WP_Error( 'invalid_event', __( 'Could not find that event.', 'qsot-ga-multi-price' ) );

		// find the pricing struct for this event
		$struct = $this->price_struct->get_by_event_id( $data['event']->ID, array( 'price_sub_group' => '0' ) );

		// find all the prices for this pricing struct. if only ids are requested, reduce the result to a list of ids
		$prices = array( '0' => array() );
		if ( isset( $struct->prices ) ) {
			foreach ( $struct->prices as $price ) {
				// if only ids are needed, set that now and skip other logic
				if ( 'ids' == $data['fields'] ) {
					$prices['0'][] = $price->product_id;
					continue;
				}

				// otherwise add the product object if it exists
				$product = wc_get_product( $price->product_id );
				if ( is_object( $product ) && ! is_wp_error( $product ) ) {
					foreach ( $price as $k => $v )
						$product->{$k} = $v;
					$prices['0'][] = $product;
				}
			}
		}

		return $prices;
	}

	// add the 'data' to each response passed to this function
	protected function _add_data( $resp, $event, $event_area=null, $ticket_types=null ) {
		$resp['data'] = array( 'owns' => 0, 'available' => 0, 'available_more' => 0 );
		// get the needex objects for data construction
		$zoner = $this->get_zoner();
		$event_area = is_object( $event_area ) ? $event_area : ( isset( $event->event_area ) && is_object( $event->event_area ) ? $event->event_area : apply_filters( 'qsot-event-area-for-event', false, $event ) );
		if ( ! is_array( $ticket_types ) ) {
			$ticket_types = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );
			$ticket_types = isset( $ticket_types['0'] ) ? $ticket_types['0'] : array();
		}

		// if any of the data is missing or errors, then bail
		if ( ! is_object( $zoner ) || is_wp_error( $zoner ) )
			return $resp;
		if ( ! is_object( $event_area ) || is_wp_error( $event_area ) )
			return $resp;
		if ( ! is_array( $ticket_types ) || empty( $ticket_types ) )
			return $resp;

		// normalize the ticket types to an array of product_ids
		$raw_ticket_types = $ticket_types;
		$ticket_types = array();
		foreach ( $raw_ticket_types as $ind => $ticket_type ) {
			if ( is_numeric( $ticket_type ) )
				$ticket_types[] = $ticket_type;
			else if ( is_object( $ticket_type ) && isset( $ticket_type->product_id ) )
				$ticket_types[] = $ticket_type->product_id;
			else if ( is_object( $ticket_type ) && isset( $ticket_type->id ) )
				$ticket_types[] = $ticket_type->id;
		}

		// if there are no valid ticket types, then bail
		if ( empty( $ticket_types ) )
			return $resp;

		$stati = $zoner->get_stati();
		// add the extra data used to update the ui
		$resp['data'] = array(
			'owns' => $zoner->find( array( 'fields' => 'total-by-ticket-type', 'event_id' => $event->ID, 'customer_id' => $zoner->current_user(), 'order_id' => 0, 'ticket_type_id' => $ticket_types, 'state' => $stati['r'][0] ) ),
			'available' => 0,
			'available_more' => 0,
		);

		// only show the remaining availability if we are allowed by settings
		if ( 'yes' == apply_filters( 'qsot-get-option-value', 'yes', 'qsot-show-available-quantity' ) ) {
			// determine how many tickets have been sold or reserved for this event so far
			$reserved_or_confirmed = $zoner->find( array( 'fields' => 'total', 'event_id' => $event->ID ) );

			// calculate how many are left
			$capacity = isset( $event_area->meta, $event_area->meta['_capacity'] ) ? $event_area->meta['_capacity'] : 0;
			$left = max( 0, $capacity - $reserved_or_confirmed );

			// update the response
			$resp['data']['available'] = $resp['data']['available_more'] = $capacity > 0 ? $left : 1000000;
		}

		return $resp;
	}

	// handle the reserve ticket ajax requests
	public function aj_reserve( $resp, $event ) {
		// determine the quantity that is being requested
		$qty = intval( $_POST['quantity'] );

		// if the quantity is not a positive number, then bail
		if ( $qty <= 0 ) {
			$resp['e'][] = __( 'The quantity must be greater than zero.', 'opentickets-community-edition' );
			return $this->_add_data( $resp, $event );
		}

		// get the event_area based on the event
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
		if ( ! is_object( $event_area ) ) {
			$resp['e'][] = __( 'Could not find that event.', 'opentickets-community-edition' );
			return $this->_add_data( $resp, $event );
		}

		// determine the ticket type to use for the request
		$valid_types = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );
		$valid_types = isset( $valid_types['0'] ) ? $valid_types['0'] : array();
		$ticket_type = isset( $_POST['ticket-type'] ) ? intval( $_POST['ticket-type'] ) : -1;
		if ( ! in_array( $ticket_type, $valid_types ) ) {
			$resp['e'][] = __( 'You must select a valid ticket price to reserve.', 'qsot-ga-multi-price' );
			return $this->_add_data( $resp, $event );
		}

		// get the zoner that will handle this request
		$zoner = $this->get_zoner();

		// process the reservation request
		$res = $zoner->reserve( false, array(
			'event_id'=> $event->ID,
			'ticket_type_id' => $ticket_type,
			'customer_id' => $zoner->current_user(),
			'quantity' => $qty,
			'order_id' => 0,
		) );

		// if the result was successful
		if ( ! is_wp_error( $res ) && is_scalar( $res ) && $res > 0 ) {
			// force the cart to send the cookie, because sometimes it doesnt. stupid bug
			WC()->cart->maybe_set_cart_cookies();

			// construct an affirmative response, with the remainder data if applicable
			$resp['s'] = true;
			$resp['m'] = array( __( 'Updated your reservations successfully.', 'opentickets-community-edition' ) );
			$resp['n'] = wp_create_nonce( 'do-qsot-frontend-ajax' );
		// if the request failed for a known reason, then add that reason to the response
		} else if ( is_wp_error( $res ) ) {
			$resp['e'] = array_merge( $resp['e'], $res->get_error_message() );
		// otherwise it failed for an unknown reason. add an error to the response
		} else {
			$resp['e'][] = __( 'Could not update your reservations.', 'opentickets-community-edition' );
		}

		return $this->_add_data( $resp, $event, $event_area, $ticket_type );
	}

	// handle the remove reservation ajax requests
	public function aj_remove( $resp, $event ) {
		// get the event_area based on the event
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
		if ( ! is_object( $event_area ) ) {
			$resp['e'][] = __( 'Could not find that event.', 'opentickets-community-edition' );
			return $this->_add_data( $resp, $event );
		}

		// determine the ticket type to use for the request
		$valid_types = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );
		$valid_types = isset( $valid_types['0'] ) ? $valid_types['0'] : array();
		$ticket_type = isset( $_POST['ticket-type'] ) ? intval( $_POST['ticket-type'] ) : -1;
		if ( ! in_array( $ticket_type, $valid_types ) ) {
			$resp['e'][] = __( 'You do not have any tickets of that type reserved.', 'qsot-ga-multi-price' );
			return $this->_add_data( $resp, $event );
		}

		// get the zoner that will handle this request
		$zoner = $this->get_zoner();
		$stati = $zoner->get_stati();

		// process the reservation request
		$res = $zoner->remove( false, array(
			'event_id'=> $event->ID,
			'ticket_type_id' => $ticket_type,
			'customer_id' => $zoner->current_user(),
			'order_id' => 0,
			'state' => $stati['r'][0],
		) );

		// if the result was successful
		if ( $res && ! is_wp_error( $res ) ) {
			// force the cart to send the cookie, because sometimes it doesnt. stupid bug
			WC()->cart->maybe_set_cart_cookies();

			// construct an affirmative response, with the remainder data if applicable
			$resp['s'] = true;
			$resp['m'] = array( __( 'Updated your reservations successfully.', 'opentickets-community-edition' ) );
		// if the request failed for a known reason, then add that reason to the response
		} else if ( is_wp_error( $res ) ) {
			$resp['e'] = array_merge( $resp['e'], $res->get_error_message() );
		// otherwise it failed for an unknown reason. add an error to the response
		} else {
			$resp['e'][] = __( 'Could not update your reservations.', 'opentickets-community-edition' );
		}

		return $this->_add_data( $resp, $event, $event_area, $ticket_type );
	}

	// handle the update reservation ajax requests
	public function aj_update( $resp, $event ) {
		$resp['m'] = isset( $resp['m'] ) && is_array( $resp['m'] ) ? $resp['m'] : array();
		$resp['e'] = isset( $resp['e'] ) && is_array( $resp['e'] ) ? $resp['e'] : array();
		// determine the quantity that is being requested
		$qtys = $_POST['quantity'];

		// if the quantity is not a positive number, then bail
		if ( array_sum( $qtys ) <= 0 ) {
			$resp['e'][] = __( 'The quantity must be greater than zero.', 'opentickets-community-edition' );
			return $this->_add_data( $resp, $event );
		}

		// get the event_area based on the event
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
		if ( ! is_object( $event_area ) ) {
			$resp['e'][] = __( 'Could not find that event.', 'opentickets-community-edition' );
			return $this->_add_data( $resp, $event );
		}

		// determine the ticket type to use for the request
		$valid_types = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );
		$valid_types = isset( $valid_types['0'] ) ? $valid_types['0'] : array();
		$raw_ticket_type = isset( $_POST['ticket-type'] ) ? $_POST['ticket-type'] : array();
		if ( ! is_array( $raw_ticket_type ) || empty( $raw_ticket_type ) || ! ( $ticket_types = array_intersect( $raw_ticket_type, $valid_types ) ) ) {
			$resp['e'][] = __( 'You must select a valid ticket price to reserve.', 'qsot-ga-multi-price' );
			return $this->_add_data( $resp, $event );
		}

		// get the zoner that will handle this request
		$zoner = $this->get_zoner();

		// get the list of zoner stati
		$stati = $zoner->get_stati();

		$at_least_one_success = false;
		// process the reservation request, for each ticket type we were sent
		foreach ( $ticket_types as $ind => $ticket_type ) {
			// figure out the quantity for this ticket type
			$qty = isset( $qtys[ $ind ] ) ? $qtys[ $ind ] : 0;

			// if the quantity is not a positive number, then skip this item
			if ( $qty <= 0 ) {
				$resp['e'][] = __( 'The quantity must be greater than zero.', 'opentickets-community-edition' );
				continue;
			}

			// run the request for this ticket type
			$res = $zoner->update( false, array(
				'event_id'=> $event->ID,
				'ticket_type_id' => $ticket_type,
				'customer_id' => $zoner->current_user(),
				'order_id' => 0,
				'status' => $stati['r'][0],
			), array( 'quantity' => $qty ) );

			// if the result was successful
			if ( $res && ! is_wp_error( $res ) ) {
				// construct an affirmative response, with the remainder data if applicable
				$at_least_one_success = true;
				$resp['m'][] = __( 'Updated your reservations successfully.', 'opentickets-community-edition' );
			// if the request failed for a known reason, then add that reason to the response
			} else if ( is_wp_error( $res ) ) {
				$resp['e'] = array_merge( $resp['e'], $res->get_error_messages() );
			// otherwise it failed for an unknown reason. add an error to the response
			} else {
				$resp['e'][] = __( 'Could not update your reservations.', 'opentickets-community-edition' );
			}
		}

		// normalize the message and error lists
		$resp['e'] = array_unique( $resp['e'] );
		$resp['m'] = array_unique( $resp['m'] );

		// if there was at least on success, then set the response status
		if ( $at_least_one_success ) {
			$resp['s'] = true;

			// force the cart to send the cookie, because sometimes it doesnt. stupid bug
			WC()->cart->maybe_set_cart_cookies();
		}

		return $this->_add_data( $resp, $event, $event_area, $ticket_type );
	}

	// add the gamp event data to the admin ajax load event response
	public function admin_ajax_load_event( $data, $event, $event_area, $order ) {
		// add the html versions of the start and end date
		$frmt = __( 'D, F jS, Y h:ia', 'opentickets-community-edition' );
		$data['_html_date'] = sprintf( '<span class="from">%s</span> - <span class="to">%s</span>', date_i18n( $frmt, strtotime( $event->meta->start ) ), date_i18n( $frmt, strtotime( $event->meta->end ) ) );

		// add the capacity
		$data['_capacity'] = intval( isset( $event_area->meta['_capacity'] ) ? $event_area->meta['_capacity'] : 0 );

		// get the available amount of tickets left on the event
		$zoner = $this->get_zoner();
		$data['_available'] = $zoner->get_availability( $event, $event_area );

		// add the raw event data, in case we want it, and the edit event link
		$event->event_area = $event_area;
		$data['_raw'] = $event;
		$data['_link'] = sprintf( '<a href="%s" target="_blank">%s</a>', get_edit_post_link( $event->ID ), $data['name'] );

		// load all the image sizes for the featured image of the event area
		$data['_imgs'] = array();
		// if the event area has a featured image, load that image's details for use in the ui
		if ( isset( $event_area->meta['_thumbnail_id'] ) ) {
			// get the image data, and store it in the result, so the ui can do with it what it wants
			$img_info = get_post_meta( $event_area->meta['_thumbnail_id'], '_wp_attachment_metadata', true );
			$data['_image_info_raw'] = $img_info;

			// then for each image size, aggregate some information for displaying the image, which is used to create the image tags
			if ( isset( $img_info['file'] ) && is_array( $img_info ) && isset( $img_info['sizes'] ) && is_array( $img_info['sizes'] ) ) {
				$u = wp_upload_dir();
				$base_file = $img_info['file'];
				$file_path = trailingslashit( trailingslashit( $u['baseurl'] ) . str_replace( basename( $base_file ), '', $base_file ) );
				// for each image size, add a record with the image path and size details
				foreach ( $img_info['sizes'] as $k => $info ) {
					$data['_imgs'][$k] = array(
						'url' => $file_path . $info['file'],
						'width' => $info['width'],
						'height' => $info['height'],
					);
				}
				// also add an entry for the fullsize version
				$data['_imgs']['full'] = array(
					'url' => trailingslashit( $u['baseurl'] ) . $base_file,
					'width' => $img_info['width'],
					'height' => $img_info['height'],
				);
			}
		}

		// figure out the appropriate customer id
		$customer_id = 'order:' . $order->id;
		if ( isset( $_POST['customer_user'] ) && ! empty( $_POST['customer_user'] ) )
			$customer_id = $_POST['customer_user'];
		elseif ( ( $order_customer_id = get_post_meta( $order->id, '_customer_user', true ) ) )
			$customer_id = $order_customer_id;

		$zoner = $this->get_zoner();
		$stati = $zoner->get_stati();
		// default number of tickets owned by this order and the default ticket data
		$data['_owns'] = $zoner->find( array(
			'event_id' => $event->ID,
			'order_id' => $order->id,
			'state' => array( $stati['r'][0], $stati['c'][0] ),
			'customer_id' => $customer_id,
		) );
		$data['_struct'] = $this->price_struct->get_by_event_id( $event->ID, array( 'price_sub_group' => 0 ) );

		return apply_filters( 'qsot-gamp-admin-ajax-load-event', $data, $event, $event_area );
	}

	// handle the admin ajax request to add a ticket to an order
	public function admin_ajax_add_tickets( $resp, $event ) {
		// if the event does not exist, then bail
		if ( ! is_object( $event ) ) {
			$resp['e'][] = __( 'Could not find the new event.', 'opentickets-community-edition' );
			return $resp;
		}
		
		// attempt to load the event_area for that event, and if not loaded, then bail
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
		if ( ! is_object( $event_area ) || ! isset( $event_area->area_type ) || ! is_object( $event_area->area_type ) || ! ( $zoner = $event_area->area_type->get_zoner() ) ) {
			$resp['e'][] = __( 'Could not find the new event\'s event area.', 'opentickets-community-edition' );
			return $resp;
		}
		$stati = $zoner->get_stati();

		// load the order and if it does not exist, bail
		$order = wc_get_order( isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : false );
		if ( ! is_object( $order ) || is_wp_error( $order ) ) {
			$resp['e'][] = __( 'Could not find that order item on the order.', 'opentickets-community-edition' );
			return $resp;
		}

		// if the quantity is not valid, then bail
		if ( ( $quantity = isset( $_POST['qty'] ) ? (int) $_POST['qty'] : 0 ) <= 0 ) {
			$resp['e'][] = __( 'The quantity must be greater than zero.', 'opentickets-community-edition' );
			return $resp;
		}

		// determine the ticket type to use for the request
		$valid_types = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );
		$valid_types = isset( $valid_types['0'] ) ? $valid_types['0'] : array();
		$ticket_type_id = isset( $_POST['ttid'] ) ? intval( $_POST['ttid'] ) : -1;
		if ( ! in_array( $ticket_type_id, $valid_types ) ) {
			$resp['e'][] = __( 'You must select a valid ticket price to reserve.', 'qsot-ga-multi-price' );
			return $this->_add_data( $resp, $event );
		}
		// verify that the supplied ticket type is a valid product
		$product = wc_get_product( $ticket_type_id );
		if ( ! is_object( $product ) ) {
			$resp['e'][] = __( 'Could not add those tickets, because the ticket product was invalid.', 'opentickets-community-edition' );
			return $resp;
		} else if ( is_wp_error( $product ) ) {
			$resp['e'] = $product->get_error_messages();
			return $resp;
		}

		// figure out the appropriate customer id
		$customer_id = 'order:' . $order->id;
		if ( isset( $_POST['customer_user'] ) && ! empty( $_POST['customer_user'] ) )
			$customer_id = $_POST['customer_user'];
		elseif ( ( $order_customer_id = get_post_meta( $order->id, '_customer_user', true ) ) )
			$customer_id = $order_customer_id;

		// actually add the ticket
		$res = $zoner->reserve( false, array(
			'event_id' => $event->ID,
			'order_id' => $order->id,
			'quantity' => $quantity,
			'customer_id' => $customer_id,
			'ticket_type_id' => $ticket_type_id,
		) );

		// if the response was successful, then...
		if ( ! is_wp_error( $res ) && is_scalar( $res ) && $res > 0 ) {
			// update the response status
			$resp['s'] = true;

			// add the item to the order
			$item_id = $this->_add_or_update_order_item( $order, $product, $res, array( 'event_id' => $event->ID ) );

			// update the reservation entry with the order_item_id
			$new_state = $stati['c'][0];
			$zoner->update( false, array(
				'event_id' => $event->ID,
				'order_id' => $order->id,
				'quantity' => $res,
				'customer_id' => $customer_id,
				'ticket_type_id' => $ticket_type_id,
				'state' => $stati['r'][0],
			), array( 'order_item_id' => $item_id, 'state' => $new_state ) );

			// notifiy externals of the change
			$event->event_area = $event_area;
			do_action( 'qsot-order-admin-gamp-added-tickets', $order, $event, $quantity, $customer_id, $item_id );
		}

		// add the data for the event area to the response too, so we can update the area data on the display
		$resp['data'] = array(
			'id' => $event->ID,
			'name' => apply_filters( 'the_title', $event->post_title, $event->ID ),
			'area_type' => $event_area->area_type->get_slug(),
		);
		$resp['data'] = $event_area->area_type->admin_ajax_load_event( $resp['data'], $event, $event_area, $order );

		return $resp;
	}

	// add a new item or update an existing item for this reservation request
	protected function _add_or_update_order_item( $order, $product, $qty, $args ) {
		$found = 0;
		// cycle through the order items and find the first matching order item for this event and product combo
		foreach ( $order->get_items( 'line_item' ) as $oiid => $item ) {
			// if there is no product_id on this item, skip it
			if ( ! isset( $item['product_id'] ) || $item['product_id'] != $product->id )
				continue;

			$matched = true;
			// figure out if all the args match
			foreach ( $args as $k => $v ) {
				if ( ! isset( $item[ $k ] ) || $item[ $k ] != $v ) {
					$matched = false;
					break;
				}
			}

			// if all the fields match, then use this order item
			if ( $matched ) {
				$found = $oiid;
				break;
			}
		}

		$item_id = 0;
		// if the product-event combo was found in an existing order item, then simply update the quantity of that order item
		if ( $found > 0 ) {
			$order->update_product( $found, $product, array( 'qty' => $qty ) );
			$item_id = $found;
		// otherwise add a new order item for this seleciton
		} else {
			$item_id = $order->add_product( $product, $qty );
			foreach ( $args as $k => $v )
				wc_add_order_item_meta( $item_id, '_' . $k, $v );
		}

		return $item_id;
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_GAMP_Area_Type::instance();
