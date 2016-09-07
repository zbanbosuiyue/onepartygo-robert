<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// class to handle the import export functionality of the seating plugin
// exports and imports entire seating charts in JSON format
class QSOT_Seating_ImportExport {
	protected static $export_file = null;
	protected static $strlen_func = '';
	protected static $uploads = array();
	protected static $output = array();
	protected static $input = array();
	protected static $has_zip = false;

	// setup the actions and filters that are used and handled by this class
	public static function pre_init() {
		self::$strlen_func = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
		self::$has_zip = class_exists( 'ZipArchive' );

		// add an export action the seating charts, on the seating chart list page in the admin
		add_action( 'post_row_actions', array( __CLASS__, 'add_row_actions' ), 10, 2 );

		// add a menu item to the seating chart menu, for the import screen
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 10 );
		add_action( 'admin_init', array( __CLASS__, 'register_admin_assets' ), 10 );

		// intercept admin based export requests
		add_action( 'wp_loaded', array( __CLASS__, 'handle_export_requests' ), 1 );

		// action used to export seating charts
		add_action( 'qsot-seating-export-charts', array( __CLASS__, 'export_seating_charts' ), 10, 3 );

		// action used to handle importing of seating charts
		add_action( 'qsot-seating-import-charts', array( __CLASS__, 'import_seating_charts' ), 10, 3 );
	}

	// register the assets that will be used by the import export functionality
	public static function register_admin_assets() {
		// resuable path and version
		$uri = QSOT_seating_launcher::plugin_url();
		$version = QSOT_seating_launcher::version();

		// register the assets
		wp_register_style( 'qsot-seating-import', $uri . 'assets/css/admin/import.css', array(), $version );
		wp_register_script( 'qsot-seating-import', $uri . 'assets/js/admin/import.js', array( 'qsot-tools' ), $version );
	}

	// wrapper function to parse a json export of seating charts into actual seating chart posts on this install
	public static function import_seating_charts( $file_path ) {
		// if the path to the uploaded zip file is not readable, then bail
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) )
			return false;

		// open it up ias a ziparchive, so that we can extract the descreet files as needed
		$zip = new ZipArchive();
		$result = $zip->open( $file_path, ZipArchive::CHECKCONS );

		// if there was a problem openning the zip, then bail with an error
		if ( true !== $result ) {
			add_action( 'admin_notices', array( __CLASS__, 'error_opening_uploaded_zip' ) );
			return false;
		}

		$filenames = array();
		// aggregate a list of files that we think are exports
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( preg_match( '#^.*\.json$#si', $name ) )
				$filenames[] = $name;
		}

		// if there were not files in the zip that we recognize as an export file, then bail
		if ( empty( $filenames ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'error_file_contains_no_exports' ) );
			return false;
		}

		// foreach filename in the list, extract the file contents and parse it into actual seating charts
		foreach ( $filenames as $filename ) {
			// get the contents of the file
			$data = self::_file_contents_from_zip( $zip, $filename );

			// if the data is empty, skip this file
			if ( empty( $data ) )
				continue;

			// otherwise, expand it into an array
			$data = @json_decode( $data, true );

			// if the file was not a json file of a seating chart, despite what we thought, then skip it
			if ( ! is_array( $data ) || ! isset( $data['chart'], $data['tickets'], $data['pricing'], $data['zones'] ) )
				continue;

			// store the data in a container that is accessbile inside this class and release the original memory allocation
			self::$input = $data;
			$data = null;
			//die(var_dump(self::$input));

			// first, lookup or import all of the required ticket products, and store their ids for later use
			self::_reconcil_tickets();

			// next, import the chart itself
			$chart_id = self::_import_post( self::$input['chart'] );
			
			// if the chart was not imported, just move on
			if ( empty( $chart_id ) )
				continue;
			self::$input['chart']['post']['ID'] = $chart_id;

			// insert all the zones
			self::_import_zones();

			// import pricing structs
			self::_import_pricing();
			//die(var_dump(self::$input));
		}
	}

	// lookup or import all tickets from the import data
	protected static function _reconcil_tickets() {
		// if there are no tickets to import, then bail
		if ( ! isset( self::$input['tickets'] ) || empty( self::$input['tickets'] ) )
			return;

		// foreach ticket in the import data
		foreach ( self::$input['tickets'] as $ticket_name => $ticket_data ) {
			// figure out a price to match on
			$price = isset( $ticket_data['meta'], $ticket_data['meta']['_price'] ) ? current( $ticket_data['meta']['_price'] ) : '0';

			// get just the ticket_post from the ticket_data
			$ticket_post = $ticket_data['post'];

			// try to lookup an existing, matching product that is a ticket, with the same price as this product
			$qargs = array(
				'post_type' => $ticket_post['post_type'],
				'post_status' => $ticket_post['post_status'],
				'name' => $ticket_post['post_name'],
				'meta_query' => array(
					array(
						'key' => '_ticket',
						'value' => 'yes',
					),
					array(
						'key' => '_price',
						'value' => $price,
						'type' => 'DECIMAL',
					),
				),
				'orderby' => 'id',
				'order' => 'asc',
				'posts_per_page' => 1,
			);
			$found = get_posts( $qargs );

			// if there was a found match, then use that instead of reimporting it as a new product
			if ( is_array( $found ) && 1 == count( $found ) ) {
				$found = current( $found );
				self::$input['tickets'][ $ticket_name ]['post']['ID'] = $found->ID;
				continue;
			}

			// otherwise, import the post
			$id = self::_import_post( $ticket_data );

			// if the post was successfully imported, then store the id for later use
			if ( $id > 0 )
				self::$input['tickets'][ $ticket_name ]['post']['ID'] = $id;
		}
	}

	// import a post, based on a format used in the export functionality
	protected static function _import_post( $raw_post ) {
		// create the post
		$post_id = wp_insert_post( $raw_post['post'] );

		// if there was no post_id, then the insert failed, so fail up
		if ( ! $post_id || is_wp_error( $post_id ) )
			return false;

		// next, import all the known meta
		foreach ( $raw_post['meta'] as $key => $values ) {
			// special stuff for thumbnails, because we have to import an image
			if ( '_thumbnail_id' == $key ) {
				foreach ( $values as $value ) {
					$value = self::_maybe_import_image( $value, $post_id );
					add_post_meta( $post_id, $key, $value );
				}
			// otherwise, just import the data
			} else {
				foreach ( $values as $value ) {
					$value = maybe_unserialize( $value );
					add_post_meta( $post_id, $key, $value );
				}
			}
		}

		return $post_id;
	}

	// maybe import an image, if we have not done so already
	protected static function _maybe_import_image( $image_name, $post_id ) {
		// if the image is not in our image list for the import, then bail
		if ( ! isset( self::$input['images'][ $image_name ] ) )
			return 0;

		// if the image has already been imported during this imprt run, then just reuse the same image
		if ( isset( self::$input['images'][ $image_name ]['post']['ID'] ) )
			return self::$input['images'][ $image_name ]['post']['ID'];

		$filename = '';
		// try to write the file
		if ( isset( self::$input['images'][ $image_name ]['file_name'], self::$input['images'][ $image_name ]['file_dump'] ) ) {
			// file the file to disk and retrieve the new file_path
			$filename = self::_new_filename( self::$input['images'][ $image_name ]['file_name'] );
			file_put_contents( $filename, @base64_decode( self::$input['images'][ $image_name ]['file_dump'] ) );
		}
		// if there is no file, then just bail with 0
		if ( empty( $filename ) )
			return 0;

		// create the post
		$attachment_id = wp_insert_attachment( self::$input['images'][ $image_name ]['post'], $filename, $post_id );

		// if there was no post_id, then the insert failed, so fail up
		if ( ! $attachment_id || is_wp_error( $attachment_id ) )
			return false;

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $filename ) );

		// next, import all the known meta
		foreach ( self::$input['images'][ $image_name ]['meta'] as $key => $values ) {
			foreach ( $values as $value ) {
				$value = maybe_unserialize( $value );
				add_post_meta( $post_id, $key, $value );
			}
		}

		// save the id so we know it is useful later
		self::$input['images'][ $image_name ]['post']['ID'] = $attachment_id;

		return $attachment_id;
	}

	// create a new filename, depending on the current contents of the target dir
	protected static function _new_filename( $old_filename ) {
		static $u = false;
		// cache the wp_uploads path, because it is heavy to regen
		if ( false === $u )
			$u = wp_upload_dir();

		$path = trailingslashit( $u['path'] );
		$filename = sanitize_file_name( $old_filename );
		// if the filealready exists, rename the target file. only do this once, and live with the consequences if there is still a collision
		if ( file_exists( $path . $filename ) ) {
			$parts = explode( '.', $filename );
			$ext = count( $parts ) > 1 ? array_pop( $parts ) : '';
			$filename = uniqid( implode( '.', $parts ) ) . ( ! empty( $ext ) ? '.' . $ext : '' );
		}

		return $path . $filename;
	}

	// import all the zones that are in the input data
	protected static function _import_zones() {
		global $wpdb;

		// load the event_area, area_type, and zoner for the new area
		$chart = apply_filters( 'qsot-get-event-area', false, self::$input['chart']['post']['ID'] );
		$area_type = is_object( $chart ) && isset( $chart->area_type ) && is_object( $chart->area_type ) ? $chart->area_type : false;
		$zoner = is_object( $area_type ) ? $area_type->get_zoner() : false;


		// if there is no event_area, area_type, or zoner, then bail
		if ( ! is_object( $area_type ) || ! is_object( $zoner ) )
			return;

		// if there are no zones, then just bail
		if ( ! isset( self::$input['zones'] ) || empty( self::$input['zones'] ) )
			return;

		// break the zones down into two groups, based on zone type
		$type1 = $type2 = array();
		foreach ( self::$input['zones'] as $zone_abbr => $zone ) {
			if ( 2 == $zone['zone_type'] ) {
				$type2[ $zone_abbr ] = (object) $zone;
			} else {
				$type1[ $zone_abbr ] = (object) $zone;
			}
		}

		// import each type of zone, recording the results for later use
		$res_type1 = $zoner->update_zones( self::$input['chart']['post']['ID'], $type1, QSOT_Seating_Area_Type::ZONES );
		$res_type2 = $zoner->update_zones( self::$input['chart']['post']['ID'], $type2, QSOT_Seating_Area_Type::ZOOM_ZONES );

		// update all the zone_ids, and import any images appropriately (while updating the appropriate meta)
		foreach ( $res_type1 + $res_type2 as $zone_abbr => $zone_id ) {
			self::$input['zones'][ $zone_abbr ]['post']['ID'] = self::$input['zones'][ $zone_abbr ]['zone_id'] = $zone_id;

			// if there was an image, the upload it appropriately, and assign the meta appropriately
			if ( isset( self::$input['zones'][ $zone_abbr ]['meta']['image-id'], self::$input['images'][ self::$input['zones'][ $zone_abbr ]['meta']['image-id'] ] ) ) {
				$image_id = self::_maybe_import_image( self::$input['zones'][ $zone_abbr ]['meta']['image-id'], self::$input['chart']['post']['ID'] );
				// if the image was successfully imported, then update the zone meta accordingly
				if ( $image_id > 0 ) {
					self::$input['zones'][ $zone_abbr ]['meta']['image-id'] = $image_id;

					// get the image url
					@list( $url ) = @wp_get_attachment_image_src( $image_id, 'full' );

					// @NOTE: should have a function for this... but for now this is fine
					$wpdb->update(
						$wpdb->qsot_seating_zonemeta,
						array( 'meta_value' => $image_id ),
						array( 'qsot_seating_zones_id' => $zone_id, 'meta_key' => 'image-id' )
					);
					$wpdb->insert(
						$wpdb->qsot_seating_zonemeta,
						array( 'meta_key' => 'src', 'meta_value' => $url, 'qsot_seating_zones_id' => $zone_id )
					);
				}
			}
		}
	}

	// import the pricing structures from the import data
	protected static function _import_pricing() {
		// if there is no pricing, then bail
		if ( ! isset( self::$input['pricing'] ) || empty( self::$input['pricing'] ) )
			return;

		// load the event_area and area_type for the new area
		$chart = apply_filters( 'qsot-get-event-area', false, self::$input['chart']['post']['ID'] );
		$area_type = is_object( $chart ) && isset( $chart->area_type ) && is_object( $chart->area_type ) ? $chart->area_type : false;

		// if there is no event_area or area_type, then bail
		if ( ! is_object( $area_type ) )
			return;

		$id = -1;
		$ps_list = array();
		// cycle through the pricing options in the import, and create a new list that has all the needed ids
		foreach ( self::$input['pricing'] as $ps_name => $grouping ) {
			// create a list for the subgroups of this pricing struct
			$list = array( 'name' => $ps_name, 'prices' => array() );

			// cycle through the import subgroups, and add them to the list
			foreach ( $grouping as $zone_abbr => $ticket_types ) {
				// determine the appropriate id to use as the zone_id
				$zone_id = '___default' == $zone_abbr ? '0' : ( isset( self::$input['zones'][ $zone_abbr ] ) ? self::$input['zones'][ $zone_abbr ]['zone_id'] : false );

				// if there was no id, bail
				if ( false === $zone_id )
					continue;

				$prices = array();
				// figure out the prices ids
				foreach ( $ticket_types as $type_name ) {
					// if that price was not imported, then skip this one
					if ( ! isset( self::$input['tickets'][ $type_name ], self::$input['tickets'][ $type_name ]['post'], self::$input['tickets'][ $type_name ]['post']['ID'] ) )
						continue;

					// add the id to the price list
					$prices[] = self::$input['tickets'][ $type_name ]['post']['ID'];
				}

				$list['prices'][ $zone_id ] = $prices;
			}

			$ps_list[ $id-- ] = $list;
		}

		// actually import the pricing structs now
		$area_type->price_struct->save_pricing( $ps_list, self::$input['chart']['post']['ID'] );
		//do_action( 'qsot-update-seating-pricing', $ps_list, self::$input['chart']['post']['ID'] );
	}

	// get the contents of a specific file within a zip archive
	protected static function _file_contents_from_zip( $zip, $filename ) {
		// get the file resource, if available
		if ( ! ( $file = $zip->getStream( $filename ) ) || ! is_resource( $file ) )
			return false;

		$output = '';
		// read the file into a string
		while ( ! feof( $file ) )
			$output .= fread( $file, 10240 );

		// close the file
		fclose( $file );

		// return the file contents
		return $output;
	}

	// add the new menu items to the seating charts menu
	public static function admin_menu() {
		// add an import menu item
		$hook = add_submenu_page(
			'edit.php?post_type=qsot-event-area',
			__( 'Import Seating Charts', 'qsot-seating' ),
			__( 'Import', 'qsot-seating' ),
			'edit_posts',
			'qsot-seating-import',
			array( __CLASS__, 'ap_import_screen' )
		);

		add_action( 'load-' . $hook, array( __CLASS__, 'ap_head_import_screen' ) );
		add_action( 'admin_print_styles-' . $hook, array( __CLASS__, 'ap_assets_import_screen' ) );
	}

	// enqueue the needed assets for the import admin screen
	public static function ap_assets_import_screen() {
		wp_enqueue_media();
		wp_enqueue_style( 'qsot-seating-import' );
		wp_enqueue_script( 'qsot-seating-import' );
	}

	// when the import page is loading, we may need to process an import now
	public static function ap_head_import_screen() {
		// add imported message
		if ( isset( $_GET['imported'] ) )
			add_action( 'admin_notices', array( __CLASS__, 'message_import_successful' ) );

		// check if all the required information is present, and if the form validation checks out
		if ( ! isset( $_POST['import_file_id'], $_POST['importn'] ) )
			return;
		if ( ! wp_verify_nonce( $_POST['importn'], 'upload-import-file' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'error_generic_problem' ) );
			return;
		}

		// make sure that the supplied attachment id is valid, and that we can actually read the file it points to
		$attachment = get_post( $_POST['import_file_id'] );
		if ( ! is_object( $attachment ) || 'attachment' != $attachment->post_type ) {
			add_action( 'admin_notices', array( __CLASS__, 'error_must_be_a_zip' ) );
			return;
		}

		// figure out the file path name of the selected file
		$u = wp_upload_dir();
		$relative_file_path = get_post_meta( $attachment->ID, '_wp_attached_file', true );
		if ( empty( $relative_file_path ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'error_must_be_a_zip' ) );
			return;
		}
		$filename = trailingslashit( $u['basedir'] ) . $relative_file_path ;

		// if the file is not readable, error out
		if ( ! file_exists( $filename ) || ! is_readable( $filename ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'error_must_be_a_zip' ) );
			return;
		}

		do_action( 'qsot-seating-import-charts', $filename );

		wp_safe_redirect( add_query_arg( array( 'imported' => 1 ), remove_query_arg( array( 'updated', 'imported' ) ) ) );
		exit;
	}

	// display the actual import seating chart page
	public static function ap_import_screen() {
		?>
			<div class="wrap">
				<h2><?php _e( 'Import Seating Charts', 'qsot-seating' ) ?></h2>

				<div class="inner">
					<form action="<?php echo remove_query_arg( array( 'imported', 'updated' ) ) ?>" method="post" id="import-form" class="qsot-seating-form">
						<div class="fields">

							<div class="field">
								<label><?php _e( 'File to Import', 'qsot-seating' ); ?></label>
								<input type="hidden" name="import_file_id" class="import-file-id" value="" />
								<input type="button" class="button upload-btn" role="upload-export" value="<?php echo esc_attr( __( 'Select File', 'qsot-seating' ) ) ?>"
										data-preview=".filename-preview" data-scope=".field" data-id=".import-file-id" />
								<span class="filename-preview"></span>
								<div class="helper"><?php _e( 'Select an exported version of some Seating Charts to import. Must be a zip file generated by an Export.', 'qsot-seating' ) ?></div>
							</div>

							<div class="actions">
								<input type="hidden" name="importn" value="<?php echo esc_attr( wp_create_nonce( 'upload-import-file' ) ) ?>" />
								<input type="submit" class="button-primary" value="<?php echo esc_attr( __( 'Import Charts', 'qsot-seating' ) ) ?>" />
							</div>

					</form>
				</div>
			</div>
		<?php
	}

	// wrapper function to compile all the seating chart data into a single file, and export it into a JSON output file
	public static function export_seating_charts( $seating_chart_ids, $filename ) {
		self::$uploads = wp_upload_dir();
		// normalize the seating chart id list to an array
		$seating_chart_ids = array_filter( (array) $seating_chart_ids );

		// if there are no ids supplied, then bail
		if ( empty( $seating_chart_ids ) )
			return;

		// open the local export file
		if ( ! ( $local_file_path = self::_open_export_file( $filename ) ) )
			return;

		$cnt = 0;
		// cycle through the ids, and process each chart one at a time, to reduce memory cost
		foreach ( $seating_chart_ids as $id ) {
			// first, load the seating chart
			$chart = apply_filters( 'qsot-get-event-area', false, $id );
			$area_type = is_object( $chart ) && isset( $chart->area_type ) && is_object( $chart->area_type ) ? $chart->area_type : false;
			$zoner = is_object( $area_type ) ? $area_type->get_zoner() : false;

			// if the area_type or zoner were not loaded, or if the area type is not seating, then bail
			if ( ! is_object( $area_type ) || ! is_object( $zoner ) || 'seating' !== $area_type->get_slug() )
				continue;

			// load the pricing info and the zone info
			$chart->pricing = $area_type->price_struct->find( array( 'event_area_id' => $chart->ID ) );
			$chart->zones = $zoner->get_zones( array( 'event_area_id' => $chart->ID ) );
			
			self::$output = array();
			// use the chart object to create a non-id-based expression of the data

			$tt_ids = array();
			// get a list of product ids used as tickets for this event
			foreach ( $chart->pricing as $ps_id => $ps )
				foreach ( $ps->prices as $group => $list )
					foreach ( $list as $data )
						if ( isset( $data->product_id ) )
							$tt_ids[] = $data->product_id;
			$tt_ids = array_unique( $tt_ids );

			// add the ticket types to the export
			$ticket_lookup = self::_export_tickets( $tt_ids );

			// raw post data
			self::$output['chart'] = self::_get_post_data( $chart );

			self::$output['zones'] = array();
			$zone_lookup = array();
			// first, add the zones, so that we can make a lookup of the zone_id to zone_abbr
			foreach ( $chart->zones as $zone_id => $zone ) {
				// make sure we can identify the zone during import
				$zone->abbr = empty( $zone->abbr ) ? 'zone-' . $zone->id : $zone->abbr;

				// make zone information that is not id based
				$zone_data = array(
					'name' => $zone->name,
					'abbr' => $zone->abbr,
					'zone_type' => $zone->zone_type,
					'capacity' => $zone->capacity,
					'meta' => self::_filter_zone_meta( $zone->meta, $zone ),
				);
				self::$output['zones'][ $zone->abbr ] = $zone_data;

				// add the zone to the lookup
				$zone_lookup[ $zone_id ] = $zone->abbr;
			}

			self::$output['pricing'] = array();
			// add the pricing to the export
			foreach ( $chart->pricing as $ps_id => $ps ) {
				$name = $ps->name;
				$by_zone = array();

				// cycle through the by-zone pricing, and convert the structure into something we can import, independent of ids
				foreach ( $ps->prices as $zone_id => $prices ) {
					// skip zones we cannot restore during import, because of the lack of unique zone_abbr
					if ( 0 != $zone_id && ! isset( $zone_lookup[ $zone_id ] ) )
						continue;

					$list = array();
					// create a list of prices to use
					foreach ( $prices as $price )
						if ( isset( $ticket_lookup[ $price->product_id ] ) )
							$list[] = $ticket_lookup[ $price->product_id ];

					// if we had a list of prices, then create an entry in the export data describing these prices
					if ( ! empty( $list ) )
						$by_zone[ 0 == $zone_id ? '___default' : $zone_lookup[ $zone_id ] ] = $list;
				}

				self::$output['pricing'][ $name ] = $by_zone;
			}

			// write the export object to a file in the export zip
			self::_write_export_file( $chart->ID . '-' . $chart->post_name . '.json', @json_encode( self::$output ) );

			// clean up all the caches, in yet another attempt to keep memory usage low
			if ( isset( $GLOBAL['wp_object_cache'], $GLOBAL['wp_object_cache']->cache ) )
				$GLOBAL['wp_object_cache']->cache = array();
			if ( isset( $GLOBAL['wpdb'] ) )
				$GLOBAL['wpdb']->flush();
		}

		// finish up the output of the file, and close it
		self::_close_export_file();

		// force the download of this local file
		self::_force_download( $local_file_path, $filename );

		// and remove the local file
		self::_remove_export_file( $local_file_path );

		// end transmission
		exit();
	}

	// add the data about the ticket products to our exported object, and track a lookup of old id to product name
	protected static function _export_tickets( $ids ) {
		// lookup table
		$ticket_id_to_name_lookup = array();

		self::$output['tickets'] = array();
		// list of tickets used by this chart, non-id-based
		foreach ( $ids as $tt_id ) {
			// load the product
			$ticket_type = get_product( $tt_id );

			// create an entry into a lookup table, that will be used later to associate prices to ticket products, instead of the id
			$ticket_id_to_name_lookup[ $tt_id ] = $ticket_type->post->post_name;
			self::$output['tickets'][ $ticket_type->post->post_name ] = self::_get_post_data( $ticket_type->post );
		}

		return $ticket_id_to_name_lookup;
	}

	// extract post data from a post object into an exportable format
	protected static function _get_post_data( $post ) {
		return array(
			'post' => array(
				'post_title' => $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_status' => $post->post_status,
				'post_name' => $post->post_name,
				'post_type' => $post->post_type,
				'post_mime_type' => $post->post_mime_type,
			),
			'meta' => self::_filter_meta( get_post_meta( $post->ID ), $post ),
		);
	}

	// filter out undesireable meta key-value pairs, and convert certain ones into lookups, like thumbnails
	protected static function _filter_meta( $meta, $post ) {
		// list of final output meta
		$output = array();

		// cycle through the meta
		foreach ( $meta as $key => $values ) {
			// skip wp special keys
			// yes image data, because the import process will recreate it
			if ( in_array( $key, array( '_edit_lock', '_edit_last', '_wp_attachment_metadata', '_wp_attached_file' ) ) )
				continue;

			// if this is the thumbnail_id, we need to add the thumbnail to our output object, and convert the id to a lookup
			if ( '_thumbnail_id' == $key ) {
				$new_values = array();
				foreach ( $values as $value ) {
					$image_name = self::_get_image( $value );
					if ( ! empty( $image_name ) )
						$new_values[] = $image_name;
				}

				// if we had images that we are exporting, then add them to the meta
				if ( ! empty( $new_values ) )
					$output[ $key ] = $new_values;

				continue;
			}

			$output[ $key ] = $values;
		}

		return apply_filters( 'qsot-seating-export-post-meta', $output, $meta, $post );
	}

	// not unlike above, we need to filter some of the zone specific meta to be lookups
	protected static function _filter_zone_meta( $meta, $zone ) {
		// list of final output meta
		$output = array();

		// cycle through the meta
		foreach ( $meta as $key => $value ) {
			// skip wp special keys
			if ( in_array( $key, array( 'src' ) ) )
				continue;

			// if this is the thumbnail_id, we need to add the thumbnail to our output object, and convert the id to a lookup
			if ( 'image-id' == $key ) {
				$new_value = array();
				$image_name = self::_get_image( $value );
				if ( ! empty( $image_name ) )
					$new_value = $image_name;

				// if we had images that we are exporting, then add them to the meta
				if ( ! empty( $new_value ) )
					$output[ $key ] = $new_value;

				continue;
			}

			$output[ $key ] = $value;
		}

		return apply_filters( 'qsot-seating-export-zone-meta', $output, $meta, $zone );
	}

	// get an image, based on id, and add it to out output data
	protected static function _get_image( $id ) {
		// load the image
		$image = get_post( $id );

		// if there was no image, then bail
		if ( ! is_object( $image ) )
			return false;

		// convert it to an exportable object
		$data = self::_get_post_data( $image );

		// get a dump of the raw image itself, and store it in the output data
		$img_data = wp_get_attachment_metadata( $id );
		$filename = trailingslashit( self::$uploads['basedir'] ) . $img_data['file'];
		$data['file_name'] = basename( $filename );
		$data['file_dump'] = base64_encode( file_get_contents( $filename ) );

		// store the image in the overall output data for this chart
		if ( ! isset( self::$output['images'] ) )
			self::$output['images'] = array();
		self::$output['images'][ $image->post_name ] = $data;

		return $image->post_name;
	}

	// for the download of a given local file, as a given remote file name
	protected static function _force_download( $local, $remote ) {
		// if the local file does not exist, or is not readable, then bail
		if ( ! file_exists( $local ) || ! is_readable( $local ) )
			return;

		// setup the headers for the force download
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Transfer-Encoding: Binary' );
		header( 'Content-disposition: attachment; filename="' . $remote . '"' );

		// actually read the file to the browser
		readfile( $local );
	}

	// remove the local file we created for the export
	protected static function _remove_export_file( $file_path ) {
		// only remove the file if it exists and is writable
		if ( ! file_exists( $file_path ) || ! is_writable( $file_path ) )
			return false;

		// remove it
		unlink( $file_path );
		return true;
	}

	// open a local file that will be used to store the results of the export. we are doing this as a wrapper to the main loop of the export function, so that we can reduce the needed memory footprint.
	// instead of tracking the whole output of the export in a string, and then writing it to a file later... we are writing it as we go
	protected static function _open_export_file( $filename ) {
		// make an obfuscated file name
		$u = wp_upload_dir();
		$obfuscated = sha1( AUTH_SALT . $filename . rand( 0, PHP_INT_MAX ) ) . '.zip';

		// compile the local file path
		$file_path = trailingslashit( $u['path'] ) . $obfuscated;

		// if the file is not writable, then bail
		if ( ! is_writable( dirname( $file_path ) ) )
			return false;

		// open the zip archive
		self::$export_file = new ZipArchive();
		$result = self::$export_file->open( $file_path, ZipArchive::CREATE );

		// if the archive was not opened, then bail
		if ( true !== $result ) {
			self::$export_file = null;
			return false;
		}

		return $file_path;
	}

	// write some data to our export file. again, we are doing this like this, so that we reduce our memory footprint
	protected static function _write_export_file( $filename, $data ) {
		// sanitize the filename
		$filename = sanitize_file_name( $filename );

		// if we actaully have a ziparchive and a non-empty filename, then add a file to the archive
		if ( self::$export_file instanceof ZipArchive && ! empty( $filename ) )
			self::$export_file->addFromString( $filename, $data );
	}

	// close the export file.
	protected static function _close_export_file() {
		if ( self::$export_file instanceof ZipArchive )
			self::$export_file->close();
	}

	// actually handle the admin based export requests
	public static function handle_export_requests() {
		// if we dont have ZipArchive, then bail, and pop an admin error
		if ( ! self::$has_zip ) {
			add_action( 'admin_notices', array( __CLASS__, 'error_requires_ziparchive' ) );
			return;
		}

		// if we are not in the admin, then bail
		if ( ! is_admin() )
			return;

		// only do this if the appropriate data is present in the request
		if ( ! isset( $_REQUEST['qsot-seating-export'], $_REQUEST['post'] ) )
			return;

		// normalize the id list to ints, so that the json matches for our nonce compare
		$ids = array_map( 'absint', $_REQUEST['post'] );

		// if there are no ids, then bail
		if ( empty( $ids ) )
			return;
			
		// verify our nonce to avoid shenannigans
		if ( ! wp_verify_nonce( $_REQUEST['qsot-seating-export'], 'qsot-seating-export-' . @json_encode( $ids ) ) )
			return;

		$exportable = array();
		// comprise a list of the posts that were requested that the current user has access to export
		foreach ( $_REQUEST['post'] as $id )
			if ( current_user_can( 'edit_post', $id ) )
				$exportable[] = $id;

		// if there are no seating charts on the list that are exportable by the current user, then bail
		if ( empty( $exportable ) )
			return;

		// otherwise, export them
		do_action( 'qsot-seating-export-charts', $exportable, count( $exportable ) . '-seating-charts-' . date( 'Y-m-d' ) . '.zip' );
		exit;
	}

	// add the export row action to each seating chart in the list
	public static function add_row_actions( $actions, $post ) {
		// only do this for seating charts
		if ( 'qsot-event-area' != $post->post_type )
			return $actions;

		// if the current user has access to edit the chart, then they should be able to export it
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			$added = false;
			$list = $actions;
			$actions = array();
			$export_list = array_map( 'absint', array( $post->ID ) );
			$nonce = wp_create_nonce( 'qsot-seating-export-' . @json_encode( $export_list ) );

			// create a link or non-link, depending on the status of the ziparchive php extension
			$export_link = self::$has_zip
				?  sprintf(
						'<a href="%s" title="%s">%s</a>',
						add_query_arg( array( 'qsot-seating-export' => $nonce, 'post' => $export_list ) ),
						__( 'Export this Seating Chart', 'qsot-seating' ),
						__( 'Export', 'qsot-seating' )
					)
				: sprintf(
						'<span title="%s">%s</a>',
						__( 'You must have the ZipArchive PHP extension, in order to user this feature.', 'qsot-seating' ),
						__( 'Export', 'qsot-seating' )
					);

			// attempt to add the export option right after the 'edit' option. if that fails, just add it to the end of the list
			foreach ( $list as $action => $link ) {
				$actions[ $action ] = $link;
				if ( 'edit' == $action ) {
					$actions['export'] = $export_link;
					$added = true;
				}
			}

			// if the link was not already added, just add it to the end of the list
			if ( ! $added )
				$actions['export'] = $export_link;
		}

		return $actions;
	}

	// import successful message
	public static function message_import_successful() {
		// if the needed url param is not present, then bail
		if ( ! isset( $_GET['imported'] ) || 1 != $_GET['imported'] )
			return;

		// print out the message
		?>
			<div class="updated">
				<p><?php _e( 'Your seating chart has been successfully imported.', 'qsot-seating' ) ?></p>
			</div>
		<?php
	}

	// show an admin error that indicates that a missing PHP extension is required in order to complete the task
	public static function error_requires_ziparchive() {
		?>
			<div class="error">
				<p><?php sprintf(
					__( 'You tried to use the import or export functionality of the OpenTickets Seating Extension. These features require the %sZipArchive PHP Extension%s, which surprisingly you do not seem to have. In order to use these features, you must first install that extension at the server level.', 'qsot-seating' ),
					'<a title="PHP.net page about ZipArchive" href="http://php.net/manual/en/class.ziparchive.php">',
					'</a>'
				) ?></p>
			</div>
		<?php
	}

	// display an error saying that we could not open the selected zip file
	public static function error_opening_uploaded_zip() {
		?>
			<div class="error">
				<p><?php _e( 'There was a problem opening the zip file you selected. Try re-exporting it from it\' source, and then reuploading it here.', 'qsot-seating' ) ?></p>
			</div>
		<?php
	}

	// display error showing that none of the files in the zip were actually export files
	public static function error_file_contains_no_exports() {
		?>
			<div class="error">
				<p><?php _e( 'None of the files inside the zip archive you selected, were export files of a seating chart.', 'qsot-seating' ) ?></p>
			</div>
		<?php
	}

	// display an error indicating that the selected file is not an attachment or a zip file
	public static function error_must_be_a_zip() {
		?>
			<div class="error">
				<p><?php _e( 'You must select a ZIP file of an export or some Seating Charts.', 'qsot-seating' ) ?></p>
			</div>
		<?php
	}

	// display a generic error, which could cover lots of problems
	public static function error_generic_problem() {
		?>
			<div class="error">
				<p><?php _e( 'A problem occurred trying to process your request. Please try again.', 'qsot-seating' ) ?></p>
			</div>
		<?php
	}
}

if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_Seating_ImportExport::pre_init();
