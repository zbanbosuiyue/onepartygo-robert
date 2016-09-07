<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// the ajax handler for our display options extension
class qsot_display_options_ajax {
	// list of hooks to allow. [hook base name] => NOPRIV
	protected static $hooks = array(
		'search_posts' => 0,
		'load_posts' => 0,
	);

	// setup the ajax class
	public static function pre_init() {
		// setup all our ajax actions and hooks
		self::_setup_ajax_hooks();
	}

	// setup all the ajax hooks for the plugin
	protected static function _setup_ajax_hooks() {
		// for each hook, setup the respective ajax actions
		foreach ( self::$hooks as $base_name => $nopriv ) {
			// create the logged in user ajax action
			add_action( 'qsot-doaj_' . $base_name, array( __CLASS__, 'aj_' . $base_name ), 10 );

			// if the user does not have to be logged in for this, then create the non-logged in version
			if ( $nopriv )
				add_action( 'qsot-doaj-nopriv_' . $base_name, array( __CLASS__, 'aj_' . $base_name ), 10 );
		}

		add_action( 'wp_ajax_qsot-display-options', array( __CLASS__, 'handle_ajax' ), 10, 0 );
		add_action( 'wp_ajax_nopriv_qsot-display-options', array( __CLASS__, 'nopriv_handle_ajax' ), 10, 0 );
	}

	// handle all logged in ajax requests
	public static function handle_ajax( $nopriv=false ) {
		$resp = array(
			's' => false,
			'e' => array(),
		);

		// verify that this request is coming from our blog and not external sources
		check_ajax_referer( 'display-options-ajax', 'n' );

		// if the security token is not present, the fail automatically
		if ( ! isset( $_REQUEST['n'] ) ) {
			$resp['e'][] = __( 'Sorry your request could not be processed. n', 'qsot-display-options' );
		// if the supplied security token is not valid, then fail
		} else if ( ! wp_verify_nonce( $_REQUEST['n'], 'display-options-ajax' ) ) {
			$resp['e'][] = __( 'Sorry your request could not be processed. v', 'qsot-display-options' );
		// otherwise, pass and attempt to run the ajax
		} else {
			$prefix = $nopriv ? 'qsot-doaj-nopriv_' : 'qsot-doaj_';
			if ( isset( $_REQUEST['sa'] ) && ! empty( $_REQUEST['sa'] ) && has_filter( $prefix . $_REQUEST['sa'] ) ) {
				$resp = apply_filters( $prefix . $_REQUEST['sa'], $resp, !!$nopriv );
			} else {
				$resp['e'][] = __( 'Sorry your request could not be processed. f', 'qsot-display-options' );
			}
		}

		wp_send_json( $resp );
		exit;
	}

	// handle all logged out ajax requests
	public static function handle_ajax_nopriv() {
		self::handle_ajax( true );
	}

	// handle ajax requests to search events
	public static function aj_search_posts( $resp ) {
		// fetch the term from the request
		$term = (string)wc_clean( stripslashes( $_REQUEST['term'] ) );

		// fetch and normalize the post types
		$post_types = array_filter( array_map( 'trim', explode( ',', $_REQUEST['post_types'] ) ) );
		$post_types = empty( $post_types ) ? array( 'qsot-event' ) : $post_types;

		// determine if the post parent was passed to limit results
		$post_parent = isset( $_REQUEST['post_parent'] ) ? wp_parse_id_list( $_REQUEST['post_parent'] ) : null;

		// search the posts for the matching item
		$resp = self::_post_search( $resp, $term, $post_types, $post_parent, array( __CLASS__, 'format_event_title' ) );

		return $resp;
	}

	// load a list of posts in the 'selected posts' format, for our featured event shortcode form
	public static function aj_load_posts( $resp ) {
		// start gathering output
		ob_start();

		// setup our data for the output
		$field = $_REQUEST['name'];
		$ids = wp_parse_id_list( $_REQUEST['id'] );

		// render the output
		?>
			<?php foreach ( $ids as $id ): ?>
				<div class="item" role="item">
					<input type="hidden" name="<?php echo esc_attr( $field ) ?>[]" value="<?php echo absint( $id ) ?>" role="item-id" />
					<div role="item-thumb" class="item-thumb"><?php echo get_the_post_thumbnail( $id, 'thumbnail' ) ?></div>
					<div role="item-title" title="<?php echo esc_attr( get_the_title( $id ) ) ?>" class="item-title"><?php echo get_the_title( $id ) ?></div>
					<div role="remove-btn" class="remove-btn">X</div>
				</div>
			<?php endforeach; ?>
		<?php

		// fetch the output
		$out = ob_get_contents();
		ob_end_clean();

		// mark our response as success, and add the output to it
		$resp['s'] = true;
		$resp['r'] = $out;

		return $resp;
	}

	// return a value of true, no matter what
	public static function return_true() {
		return true;
	}

	// callback to format the title of events
	public static function format_event_title( $title, $match ) {
		return $title;
	}

	// get a raw list of matching posts
	protected static function _post_search( $resp, $term, $post_types, $post_parent=null, $post_title_cb=null, $post_id_cb=null ) {
		// normalize the post_types list
		$post_types = empty( $post_types ) ? array( 'qsot-event' ) : (array)$post_types;
		$has_event = in_array( 'qsot-event', $post_types );

		// if there is no term then return a generic set or results, ordered by date desc
		if ( empty( $term ) ) {
			$args = array(
				'post_type' => $post_types,
				'post_status' => 'publish',
				'posts_per_page' => 20,
				'post_parent__in' => $post_parent,
				's' => $term,
				'fields' => 'ids',
				'meta_key' => '_start',
				'meta_type' => 'DATETIME',
				'orderby' => 'meta_value',
				'order' => 'asc',
			);
			if ( $has_event ) {
				$args['meta_key'] = '_start';
				$args['meta_type'] = 'DATETIME';
				$args['orderby'] = 'meta_value';
			}
			$posts = get_posts( $args );
		} else {
			// basic search, usign title and post_content
			$args = array(
				'post_type' => $post_types,
				'post_status' => 'publish',
				'posts_per_page' => -1,
				'post_parent__in' => $post_parent,
				's' => $term,
				'fields' => 'ids'
			);

			// if a number is supplied as a search term, it could be an ID. try to look up posts based on that assumption too
			if ( is_numeric( $term ) ) {
				// look for direct matches of a post term
				$args2 = array(
					'post_type' => $post_types,
					'post_status' => 'publish',
					'posts_per_page' => -1,
					'post_parent__in' => $post_parent,
					'post__in' => array( 0, $term ),
					'fields' => 'ids'
				);

				// look for parent posts that match the supplied id
				$args3 = array(
					'post_type' => $post_types,
					'post_status' => 'publish',
					'posts_per_page' => -1,
					'post_parent__in' => $post_parent ? array_merge( (array) $post_parent, (array) $term ) : (array) $term,
					'fields' => 'ids'
				);

				// get a uniquified list of ids of matches
				$posts = array_unique( array_merge( get_posts( $args ), get_posts( $args2 ), get_posts( $args3 ) ) );
			// if the term is not numeric, then just perform the title/content search
			} else {
				$posts = array_unique( array_merge( get_posts( $args ) ) );
			}

			// sort the aggregated list by start date
			if ( $posts && $has_event ) {
				$posts = get_posts( array(
					'post_type' => $post_types,
					'post_status' => 'publish',
					'posts_per_page' => -1,
					'post__in' => $posts,
					'fields' => 'ids',
					'meta_key' => '_start',
					'meta_type' => 'DATETIME',
					'orderby' => 'meta_value',
					'order' => 'asc',
				) );
			}
		}

		$resp['raw'] = $posts;

		$found_matches = $parents = $pids = $titles = $post_types = $thumbs = $starts = array();

		// if there were results
		if ( $posts ) {
			// for each result, add a record to our final, formatted list of results, matching the id to the label
			foreach ( $posts as $post ) {
				$match = get_post( $post );
				$title = wp_kses_post( $match->post_title );
				// if there is a callback supplied to format the display value of the result item, then pass the title through that function
				if ( is_callable( $post_title_cb ) )
					$title = call_user_func( $post_title_cb, $title, $match );
				if ( is_callable( $post_id_cb ) )
					$post = call_user_func( $post_id_cb, $post, $match );

				// track the post parent of each item, used for sorting a little later
				$parents[] = $match->post_parent;

				// track the event start dates, used for sort later
				$starts[] = $start = QSOT_Utils::local_timestamp( get_post_meta( $post, '_start', true ) );

				$frmt = get_option( 'date_format', 'F, jS' ) . ' ' . get_option( 'time_format', 'g:ia' );
				// track the title for each item, used for combine and sort later
				$titles[] = sprintf( __( '%s (%s)', 'qsot-display-options' ), rawurldecode( $title ), date_i18n( $frmt, $start ) );

				// misc info
				$post_types[] = $match->post_type;
				$thumbs[] = get_the_post_thumbnail( $match->ID, 'thumbnail' );

				// track the post id for each item, used for sort and combine later
				$pids[] = $post;
			}

			// sort the results by post parent, then by start date, then title, then pid, so that parent events (post_parent=0) are first in the list
			array_multisort( $starts, SORT_ASC, SORT_NUMERIC, $parents, SORT_ASC, SORT_NUMERIC, $titles, SORT_ASC, SORT_STRING, $pids, SORT_ASC, SORT_NUMERIC, $post_types, SORT_ASC, SORT_STRING, $thumbs );

			// produce the final list. must be list of arrays, because js lib sorts by object key for some dumb reason
			foreach ( $pids as $index => $pid )
				$found_matches[] = array( 'id' => $pid, 't' => $titles[ $index ], 'u' => $thumbs[ $index ], 'y' => $post_types[ $index ] );
		}

		// add the results to our output
		$resp['r'] = apply_filters( 'qsot-search-found-posts', $found_matches, $post_types );
		$resp['s'] = true;

		return $resp;
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	qsot_display_options_ajax::pre_init();
