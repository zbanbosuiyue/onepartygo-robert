<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// the coupon plugin admin core. handles admin requests dealing with coupons
class qsot_coupons_admin {
	// setup the coupons admin class
	public static function pre_init() {
		if ( ! is_admin() ) return;

		// add our fields to the coupon edit/creation form in the admin, and add our save handler for those fields
		add_filter( 'woocommerce_coupon_data_tabs', array( __CLASS__, 'coupon_form_tabs' ), 10000, 1 );
		add_action( 'woocommerce_coupon_data_panels', array( __CLASS__, 'coupon_form_event_limit_panel' ), 10000 );
		add_action( 'woocommerce_coupon_options_save', array( __CLASS__, 'coupon_save' ), 10000, 1 );
		add_filter( 'qsot-sanitize-coupon-event-settings', array( __CLASS__, 'sane_coupon_event_settings' ), 1000, 2 );

		// register the assets we use in the admin, and enqueue them at the proper times
		add_action( 'admin_init', array( __CLASS__, 'register_assets' ), 1000 );
		add_filter( 'qsot-admin-load-assets-shop_coupon', array( __CLASS__, 'admin_coupon_assets' ), 10, 2 );
		add_filter( 'qsot-admin-load-assets-product', array( __CLASS__, 'admin_coupon_assets' ), 10, 2 );
		add_filter( 'qsot-admin-load-assets-shop_order', array( __CLASS__, 'admin_order_assets' ), 10, 2 );
		add_filter( 'qsot-coupons-tools-msgs', array( __CLASS__, 'admin_ui_msgs' ), 10, 2 );
		add_filter( 'qsot-order-coupons-ui-msgs', array( __CLASS__, 'admin_order_ui_msgs' ), 10, 2 );
		add_filter( 'qsot-order-coupons-ui-templates', array( __CLASS__, 'admin_order_ui_templates' ), 10, 2 );

		// add the add coupone button to the admin
		add_action( 'woocommerce_order_item_add_action_buttons', array( __CLASS__, 'add_coupon_button' ), 10, 1 );
	}

	// register the various assets we will use in the admin pages
	public static function register_assets() {
		$base = QSOT_coupons_launcher::plugin_url() . 'assets/';
		$version = QSOT_coupons_launcher::version();

		// admin js
		wp_register_script( 'qsot-coupons-tools', $base . 'js/utils/tools.js', array( 'qsot-tools', 'underscore', 'backbone' ), $version );
		wp_register_script( 'qsot-coupons-ui', $base . 'js/admin/ui.js', array( 'qsot-coupons-tools' ), $version );
		wp_register_script( 'qsot-order-coupons-ui', $base . 'js/admin/order-ui.js', array( 'qsot-coupons-tools' ), $version );

		// admin css
		wp_register_style( 'qsot-edit-coupon', $base . 'css/admin/edit-coupon.css', array(), $version );
	}

	// init the tools js settings
	protected static function _tools_js_settings( $exists, $post_id ) {
		wp_localize_script( 'qsot-coupons-tools', '_qsot_coupons_tools_settings', array(
			'nonce' => wp_create_nonce( 'coupon-ajax' ),
			'msgs' => apply_filters( 'qsot-coupons-tools-msgs', array(), $post_id ),
		) );
	}

	// load the assets that are needed on the edit coupon page
	public static function admin_coupon_assets( $exists, $post_id ) {
		wp_enqueue_style( 'qsot-edit-coupon' );
		wp_enqueue_script( 'qsot-coupons-ui' );

		// setup the settings sent to the coupon ui js
		wp_localize_script( 'qsot-coupons-ui', '_qsot_coupons_settings', array(
			'nonce' => wp_create_nonce( 'coupon-ajax' ),
			'msgs' => apply_filters( 'qsot-coupons-ui-msgs', array(), $post_id ),
		) );

		// init the tools settings
		self::_tools_js_settings( $exists, $post_id );
	}

	// load the assets used on the edit order pages
	public static function admin_order_assets( $exists, $post_id ) {
		wp_enqueue_script( 'qsot-order-coupons-ui' );

		// setup the settings sent to the coupon order ui js
		wp_localize_script( 'qsot-order-coupons-ui', '_qsot_order_coupons_settings', array(
			'nonce' => wp_create_nonce( 'coupon-ajax' ),
			'msgs' => apply_filters( 'qsot-order-coupons-ui-msgs', array(), $post_id ),
			'templates' => apply_filters( 'qsot-order-coupons-ui-templates', array(), $post_id ),
		) );

		// init the tools settings
		self::_tools_js_settings( $exists, $post_id );
	}

	// the messages that could be used in the coupon ui
	public static function admin_ui_msgs( $list, $post_id ) {
		// construct a list of new msgs to add to the list
		$new_msgs = array(
			'Are you sure you want to remove this limitation?' => __( 'Are you sure you want to remove this limitation?', 'qsot-coupons' ),
		);

		// return a merged list of msgs
		return array_merge( $list, $new_msgs );
	}

	// the messages that could be used in the order coupons ui
	public static function admin_order_ui_msgs( $list, $post_id ) {
		// construct a list of new msgs to add to the list
		$new_msgs = array(
		);

		// return a merged list of msgs
		return array_merge( $list, $new_msgs );
	}

	// the templates that will be used on the edit order pages, for the order coupons ui
	public static function admin_order_ui_templates( $list, $post_id ) {
		$list = (array)$list;

		// template for the used coupons portion of the page, if it does not already exist
		$list['used-coupons'] = '<div class="wc-used-coupons">'
				. '<ul class="wc_coupon_list">'
					. '<li><strong>' . __( 'Coupon(s) Used', 'qsot-coupons' ) . '</strong></li>'
				. '</ul>'
			. '</div>';

		return $list;
	}

	// add the tab for our coupon event settings
	public static function coupon_form_tabs( $list ) {
		// add the event limits tab
		$list['events-limits'] = array(
			'label' => __( 'Event Limits', 'qsot-coupons' ),
			'target' => 'event-limits-panel',
			'class' => '',
		);
		
		return $list;
	}

	// add the panel for the event limits
	public static function coupon_form_event_limit_panel() {
		global $post;
		?>
			<div id="event-limits-panel" class="panel woocommerce_options_panel">

				<div class="options_group">

					<?php
						// fetch the list of event ids already set
						$event_ids = array_filter( wp_parse_id_list( get_post_meta( $post->ID, 'event_ids', true ) ) );
						$json_ids = array();

						// aggregate a formatted list of the ids to their respective names
						foreach ( $event_ids as $event_id ) {
							$event = apply_filters( 'qsot-get-event', false, $event_id );
							if ( is_object( $event ) ) {
								$title = wp_kses_post( $event->post_title );
								$title = 0 == $event->parent ? sprintf( __( 'All %s events', 'qsot-coupon' ), '"' . $title . '"' ) : $title;
								$json_ids[ $event_id . '' ] = $title;
							}
						}
					?>

					<div class="form-field">
						<label for="event_ids"><?php _e( 'Only Specific Events', 'qsot-coupons' ); ?></label>
						<input type="hidden" class="qsot-event-search qsot-post-search" data-multiple="true" style="width: 50%;" id="event_ids" name="event_ids" data-placeholder="<?php _e( 'Search for an event&hellip;', 'qsot-coupons' ); ?>"
								data-action="qsot-coupons" data-sa="search_events" data-selected="<?php echo esc_attr( json_encode( $json_ids ) ) ?>" value="<?php echo implode( ',', array_keys( $json_ids ) ); ?>" />
						<img class="help_tip" data-tip="<?php _e( 'Events in this list, must have at least one ticket in the cart, in order to use this coupon. An empty list, means the coupon is valid for any event.', 'qsot-coupons' ); ?>"
								src="<?php echo WC()->plugin_url(); ?>/assets/images/help.png" height="16" width="16" />
						<div class="clear"></div>
					</div>

					<?php
						// load the date range inforamtion
						$start_date = get_post_meta( $post->ID, 'event_range_start', true );
						$end_date = get_post_meta( $post->ID, 'event_range_end', true );
					?>
					<div class="form-field">
						<label for="event_range_start"><?php _e( 'Events After', 'qsot-coupons' ) ?></label>
						<input type="text" class="use-datepicker-range from" data-frmt="<?php echo esc_attr( __( 'mm-dd-yy', 'qsot-coupons' ) ) ?>" value="<?php echo esc_attr( $start_date ) ?>"
							data-frmt-pattern="<?php echo esc_attr( _x( '(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])-[0-9]{4}', 'input-pattern', 'qsot-coupons' ) ) ?>"
							data-frmt-placeholder="<?php echo esc_attr( _x( 'MM-DD-YYYY', 'placeholder', 'qsot-coupons' ) ) ?>"
							pattern="[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])" data-placeholder="YYYY-MM-DD" id="event_range_start" name="event_range_start" linked="#event_range_end" />
						<img class="help_tip" data-tip="<?php _e( 'Events must occur after this date, for this coupon to be valid for them.', 'qsot-coupons' ); ?>"
								src="<?php echo WC()->plugin_url(); ?>/assets/images/help.png" height="16" width="16" />
						<div class="clear"></div>
					</div>
				
					<div class="form-field">
						<label for="event_range_end"><?php _e( 'Events Before', 'qsot-coupons' ) ?></label>
						<input type="text" class="use-datepicker-range to" data-frmt="<?php echo esc_attr( __( 'mm-dd-yy', 'qsot-coupons' ) ) ?>" value="<?php echo esc_attr( $end_date ) ?>"
							data-frmt-pattern="<?php echo esc_attr( _x( '(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])-[0-9]{4}', 'input-pattern', 'qsot-coupons' ) ) ?>"
							data-frmt-placeholder="<?php echo esc_attr( _x( 'MM-DD-YYYY', 'placeholder', 'qsot-coupons' ) ) ?>"
							pattern="[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])" data-placeholder="YYYY-MM-DD" id="event_range_end" name="event_range_end" linked="#event_range_start" />
						<img class="help_tip" data-tip="<?php _e( 'Events must occur after this date, for this coupon to be valid for them.', 'qsot-coupons' ); ?>"
								src="<?php echo WC()->plugin_url(); ?>/assets/images/help.png" height="16" width="16" />
					</div>
				
				</div>

				<div class="options_group">

					<div class="form-field">
						<label for="btn-add-limit"><?php _e( 'Limit Usage by Event', 'qsot-coupons' ) ?></label>
						<input type="button" value="<?php _e( 'Add Limitation', 'qsot-coupons' ) ?>" class="button add-limit" id="btn-add-limit" rel="add-limit" tar="#qsot-limitations" from="#qsot-limit-template" />

						<script type="text/html" id="qsot-limit-template">
							<div class="limitation">
								<input type="hidden" class="qsot-event-search qsot-post-search" style="width:50%;" name="event_limit[event_id][]" data-placeholder="<?php _e( 'Search for an event&hellip;', 'qsot-coupons' ); ?>"
										data-action="qsot-coupons" data-sa="search_events" data-selected="" value="" />
								<input type="number" step="1" min="0" name="event_limit[limit_amt][]" value="1" class="qty" title="<?php _e( 'Maximum number of tickets that can be sold for the selected event.', 'qsot-coupons' ) ?>" />
								<input type="number" step="1" min="0" name="event_limit[limit_amt][]" value="0" class="usage" disabled="disabled" title="<?php _e( 'Current number of tallied usages.', 'qsot-coupons' ) ?>" />
								<div class="remove" rel="remove"><?php _e( 'remove', 'qsot-coupon' ) ?></div>
								<div class="clear"></div>
							</div>
						</script>

						<div class="limit-header">
							<span class="col-head event"><?php _e( 'Event', 'qsot-coupon' ) ?></span>
							<span class="col-head qty"><?php _e( 'Max Qty.', 'qsot-coupon' ) ?></span>
							<span class="col-head usage"><?php _e( 'Usage', 'qsot-coupon' ) ?></span>
							<div class="clear"></div>
						</div>

						<div id="qsot-limitations">
							<?php
								// load the coupon's current event limitations
								$event_limits = get_post_meta( $post->ID, 'event_limits', true );
								$event_limits = '' === $event_limits ? array() : (array)$event_limits;

								// load the coupon's usages history
								$event_usages = get_post_meta( $post->ID, 'event_usages', true );
								// handle legacy meta
								if ( '' == $event_usages && ( $legacy = get_post_meta( $post->ID, 'usage_record', true ) ) ) {
									$event_usages = array_filter( (array)$legacy );
									update_post_meta( $post->ID, 'event_usages', $event_usages );
									delete_post_meta( $post->ID, 'usage_record' );
								}
								$event_usages = '' === $event_usages ? array() : (array)$event_usages;
							?>
							<?php foreach ( $event_limits as $event_id => $limit ): // for each one, create an editable list item ?>
								<?php $event = apply_filters( 'qsot-get-event', false, $event_id ); ?>
								<?php if ( is_object( $event ) ): ?>
									<?php
										$title = wp_kses_post( $event->post_title );
										$title = 0 == $event->parent ? sprintf( __( 'All %s events', 'qsot-coupon' ), '"' . $title . '"' ) : $title;
										$usage = isset( $event_usages[ $event_id . '' ] ) ? $event_usages[ $event_id . '' ] : 0;
									?>
									<div class="limitation">
										<input type="hidden" class="qsot-event-search qsot-post-search" style="width:50%;" name="event_limit[event_id][]" data-placeholder="<?php _e( 'Search for an event&hellip;', 'qsot-coupons' ); ?>"
												data-action="qsot-coupons" data-sa="search_events" data-selected="<?php echo esc_attr( $title ) ?>" value="<?php echo esc_attr( $event_id ) ?>"
										/><input type="number" step="1" min="0" name="event_limit[limit_amt][]" value="<?php echo esc_attr( $limit ) ?>" class="qty"
												title="<?php _e( 'Maximum number of tickets that can be sold for the selected event.', 'qsot-coupons' ) ?>"
										/><input type="number" step="1" min="0" name="event_limit[usages][]" value="<?php echo esc_attr( $usage ) ?>" class="usage" disabled="disabled"
												title="<?php _e( 'Current number of tallied usages.', 'qsot-coupons' ) ?>" />
										<div class="remove" rel="remove"><?php _e( 'remove', 'qsot-coupon' ) ?></div>
										<div class="clear"></div>
									</div>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					</div>

				</div>

				<?php do_action( 'qsot-coupon-options-event-limit' ); ?>

			</div>
		<?php
	}

	// when saving a coupon, we need to make sure to save our custom fields too
	public static function coupon_save( $coupon_id ) {
		// grab the post data
		$data = array(
			'event_ids' => isset( $_POST['event_ids'] ) ? $_POST['event_ids'] : array(),
			'event_limit' => isset( $_POST['event_limit'] ) ? $_POST['event_limit'] : array(),
			'event_range_start' => isset( $_POST['event_range_start'] ) ? $_POST['event_range_start'] : '',
			'event_range_end' => isset( $_POST['event_range_end'] ) ? $_POST['event_range_end'] : '',
		);

		// santize and organize the input data
		$data = apply_filters( 'qsot-sanitize-coupon-event-settings', $data, $coupon_id );

		// update our meta where appropriate
		if ( isset( $data['event_ids'] ) )
			update_post_meta( $coupon_id, 'event_ids', $data['event_ids'] );
		if ( isset( $data['event_limits'] ) ) // different key then above is intentional. read the sane_coupon_event_settings comments
			update_post_meta( $coupon_id, 'event_limits', $data['event_limits'] );
		if ( isset( $data['event_range_start'] ) )
			update_post_meta( $coupon_id, 'event_range_start', $data['event_range_start'] );
		if ( isset( $data['event_range_end'] ) )
			update_post_meta( $coupon_id, 'event_range_end', $data['event_range_end'] );
	}

	// organize and sanitize the event settings
	public static function sane_coupon_event_settings( $settings, $coupon_id ) {
		// sanitize the list of event ids
		if ( isset( $settings['event_ids'] ) )
			$settings['event_ids'] = implode( ',', array_filter( wp_parse_id_list( $settings['event_ids'] ) ) );
		
		// sanitize and organize the event_limits
		if ( isset( $settings['event_limit'] ) && ! empty( $settings['event_limit'] ) ) {
			// the raw setting is labled 'event_limit' (no 's')
			$raw_limit = $settings['event_limit'];
			unset( $settings['event_limit'] );

			// create the real setting, 'event_limits' (with an 's')
			$settings['event_limits'] = array();

			// organize the results
			if ( isset( $raw_limit['event_id'], $raw_limit['limit_amt'] ) && is_array( $raw_limit['event_id'] ) ) {
				foreach ( $raw_limit['event_id'] as $index => $event_id ) {
					// skip entries that have a blank event id
					if ( '' === $event_id ) continue;
					$settings['event_limits'][ $event_id . '' ] = isset( $raw_limit['limit_amt'][ $index ] ) ? $raw_limit['limit_amt'][ $index ] : 0;
				}
			}
		}

		// sanitize the event start range date
		if ( isset( $settings['event_range_start'] ) ) {
			$ts = strtotime( $settings['event_range_start'] );
			$settings['event_range_start'] = ( false !== $ts && $ts > 0 ) ? date( 'Y-m-d', $ts ) . ' 00:00:00' : '';
		}

		// sanitize the event end range date
		if ( isset( $settings['event_range_end'] ) ) {
			$ts = strtotime( $settings['event_range_end'] );
			$settings['event_range_end'] = ( false !== $ts && $ts > 0 ) ? date( 'Y-m-d', $ts ) . ' 23:59:59' : '';
		}

		return $settings;
	}

	// add the 'add coupon' button to the edit order page
	public static function add_coupon_button( $order ) {
		?>
			<?php if ( $order->is_editable() ): ?>
				<button type="button" class="button add-coupon" rel="add-coupon"><?php _e( 'Add Coupon', 'qsot-coupons' ); ?></button>
				<script type="text/html" id="qsot-add-coupon">
					<div class="wc-backbone-modal qsot-add-coupon-modal">
						<div class="wc-backbone-modal-content">
							<section class="wc-backbone-modal-main" role="main">
								<header class="wc-backbone-modal-header">
									<a class="modal-close modal-close-link" href="#"><span class="close-icon"><span class="screen-reader-text"><?php _e( 'Close', 'qsot-coupons' ) ?></span></span></a>
									<h1><?php _e( 'Add Coupon', 'qsot-coupons' ); ?></h1>
								</header>
								<article>
									<input type="hidden" class="qsot-coupon-search qsot-post-search" style="width:100%;" data-multiple="true" name="coupon_ids[]" data-placeholder="<?php _e( 'Search for a coupon&hellip;', 'qsot-coupons' ); ?>"
											data-action="qsot-coupons" data-sa="search_coupons" data-selected="{}" value="" />
								</article>
								<footer>
									<div class="inner">
										<button id="btn-add-selected-coupons" class="button button-primary button-large"><?php _e( 'Add', 'woocommerce' ); ?></button>
									</div>
								</footer>
							</section>
						</div>
					</div>
					<div class="wc-backbone-modal-backdrop modal-close">&nbsp;</div>
				</script>
			<?php endif; ?>
		<?php
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	qsot_coupons_admin::pre_init();
