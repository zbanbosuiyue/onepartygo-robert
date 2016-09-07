<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

if ( ! class_exists( 'QSOT_Ticket_Sales_by_Event_Report' ) ):

// new, faster, more efficient ticket sales by event report. creates sub-reports, broken down by child event (based on parent event selection), then payment type, to show how many ticket sales have happened per event
class QSOT_Ticket_Sales_by_Event_Report extends QSOT_Admin_Report {
	protected $order = 100;
	protected $limit = 200;
	protected $offset = 0;
	protected $parent_event_id = 0;
	protected $date_from = '';
	protected $date_to = '';
	protected $events = array();
	protected $event_ids = array();
	protected $ticket_types = array();

	protected $event_area = null;
	protected $zoner = null;
	protected $event = null;
	protected $event_id = 0;
	protected $event_name = '';
	protected $event_ind = 0;
	protected $state_map = array();
	protected $totals = array();

	// initialization specific to this report
	public function init() {
		// setup the report namings
		$this->group_name = $this->name = __( 'Ticket Sales by Event', 'qsot-reporting' );
		$this->group_slug = $this->slug = 'by-event';

		// add the ajax handle for this report
		$aj = QSOT_Ajax::instance();
		$aj->register( $this->slug, array( &$this, 'handle_ajax' ), 'edit_posts', 10, 'qsot-admin-report-ajax' );
	}

	// individual reports should define their own set of columns to display in html
	public function html_report_columns() {
		return apply_filters( 'qsot-' . $this->slug . '-report-html-columns', array(
			'method' => array( // payment method
				'title' => __( 'Payment Method', 'qsot-reporting' ),
				'tooltip' => __( 'Method of payment for these tickets', 'qsot-reporting' )
			),
			'subtotal' => array( // dollars before discounts
				'title' => __( 'Subtotal', 'qsot-reporting' ),
				'tooltip' => __( 'Total money to collect, prior to discounts', 'qsot-reporting' )
			),
			'units' => array( // tickets sold
				'title' => __( '#', 'qsot-reporting' ),
				'tooltip' => __( 'Total number of tickets sold', 'qsot-reporting' )
			),
			'percent' => array( // percentage of the capacity sold
				'title' => __( '%', 'qsot-reporting' ),
				'tooltip' => __( 'Percentage of the total available tickets that were sold', 'qsot-reporting' )
			),
			'discount' => array( // dollars discounted
				'title' => __( 'Comp', 'qsot-reporting' ),
				'tooltip' => __( 'Total money that was discounted from the price of tickets', 'qsot-reporting' )
			),
			'discount_units' => array( // number of discounted tickets
				'title' => __( 'Comp #', 'qsot-reporting' ),
				'tooltip' => __( 'Total number of tickets that received a discount', 'qsot-reporting' )
			),
			'discount_percent' => array( // percent of discounted tickets
				'title' => __( 'Comp %', 'qsot-reporting' ),
				'tooltip' => __( 'Percentage of sold tickets that received a discount', 'qsot-reporting' )
			),
			'total' => array( // income
				'title' => __( 'Total', 'qsot-reporting' ),
				'tooltip' => __( 'Total money collected, after discounts', 'qsot-reporting' )
			),
		) );
	}

	// get a list of the default total for the summary portion of this report
	public function default_totals() {
		return apply_filters( 'qsot-' . $this->slug . '-report-summary-default-totals', array(
			'method' => '-',
			'subtotal' => 0,
			'units' => 0,
			'percent' => 0,
			'discount' => 0,
			'discount_units' => 0,
			'discount_percent' => 0,
			'total' => 0,
		) );
	}

	// individual reports should define their own set of columns to add to the csv
	public function csv_report_columns() {
		return apply_filters( 'qsot-' . $this->slug . '-report-csv-columns', array(
			'order_item_id' => __( 'ID', 'qsot-reporting' ),
			'first_name' => __( 'First', 'qsot-reporting' ),
			'last_name' => __( 'Last', 'qsot-reporting' ),
			'method' => __( 'Payment Method', 'qsot-reporting' ),
			'order_id' => __( 'Order #', 'qsot-reporting' ),
			'subtotal' => __( 'Item Subtotal', 'qsot-reporting' ),
			'taxes' => __( 'Taxes', 'qsot-reporting' ),
			'discount' => __( 'Discount', 'qsot-reporting' ),
			'total' => __( 'Total', 'qsot-reporting' ),
			'event_name' => __( 'Event', 'qsot-reporting' ),
			'zone' => __( 'Seat/Zone', 'qsot-reporting' ),
			'ticket_type' => __( 'Ticket Type', 'qsot-reporting' ),
			'quantity' => __( 'Quantity', 'qsot-reporting' ),
		) );
	}

	// determine if the current page request has the required request fields needed to run a full report
	public function has_required_request_fields() {
		// if this is the printer friendly version, that requires different fields
		if ( $this->is_printer_friendly() ) {
			if ( ! isset( $_REQUEST['csv-source'] ) )
				return;

			return true;
		}

		// if the parent event is not present, bail
		if ( ! isset( $_REQUEST['parent_event_id'] ) )
			return;

		// if the date range is not present, then bail
		if ( ! isset( $_REQUEST['date_from'], $_REQUEST['date_to'] ) )
			return;

		return apply_filters( 'qsot-' . $this->slug . '-report-has-required-request-fields', true );
	}

	// before running any part of the report, we need to figure out what events to run the report for
	protected function _before_starting() {
		$this->offset = 0;
		$this->events = array();
		$this->event = null;
		$this->date_from = date( 'Y-m-d 00:00:00', strtotime( $_REQUEST['date_from'] ) );
		$this->date_to = date( 'Y-m-d 23:59:59', strtotime( $_REQUEST['date_to'] ) );

		// if this is not the printer friendly version, then load some extra stuff
		if ( ! $this->is_printer_friendly() ) {
			$this->parent_event_id = max( 0, intval( $_REQUEST['parent_event_id'] ) );
			// get a list of all the events that are children of the parent, within the given timframe
			$this->events = get_posts( array(
				'post_type' => 'qsot-event',
				'post_status' => 'any',
				'posts_per_page' => -1,
				'post_parent' => $this->parent_event_id,
				'meta_key' => '_start',
				'meta_type' => 'DATETIME',
				'orderby' => 'meta_value',
				'order' => 'asc',
				'meta_query' => array(
					array( 'key' => '_start', 'value' => array( $this->date_from, $this->date_to ), 'compare' => 'BETWEEN', 'type' => 'DATETIME' ),
				),
			) );

			// create a list of just the event_ids
			foreach ( $this->events as $event )
				$this->event_ids[] = $event->ID;
		}

		// add messages for deprecated filters
		add_action( 'qsot-load-seating-report-assets', array( __CLASS__, 'deprectated_filter' ), -1 );
		add_action( 'qsot-seating-report-get-ticket-data', array( __CLASS__, 'deprectated_filter' ), -1 );
		add_action( 'qsotc-seating-report-compile-rows-occupied', array( __CLASS__, 'deprectated_filter' ), -1 );
		add_action( 'qsot-seating-report-compile-rows-lines', array( __CLASS__, 'deprectated_filter' ), -1 );
		add_action( 'qsotc-seating-report-compile-rows-available', array( __CLASS__, 'deprectated_filter' ), -1 );
		add_action( 'qsot-seating-report-compile-rows-available', array( __CLASS__, 'deprectated_filter' ), -1 );
		add_action( 'qsot-seating-report-fields', array( __CLASS__, 'deprectated_filter' ), -1 );
		add_action( 'qsotc-seating-report-csv-row', array( __CLASS__, 'deprectated_filter' ), -1 );
	}

	protected function _starting() {
		// reset all internal trackers
		$this->totals = array();
		$this->offset = 0;
		$this->event_id = $this->event->ID;
		$ts = strtotime( get_post_meta( $this->event_id, '_start', true ) );
		$this->event_name = sprintf( '%s on %s @ %s', apply_filters( 'the_title', $this->event->post_title ), date( __( 'm/d/Y', 'opentickets-community-edition' ), $ts ), date( __( 'g:ia', 'opentickets-community-edition' ), $ts ) );

		// load the event_area for this event
		$this->event_area = apply_filters( 'qsot-event-area-for-event', false, $this->event_id );
		$this->zoner = is_object( $this->event_area ) && ! is_wp_error( $this->event_area ) && is_object( $this->event_area->area_type ) ? $this->event_area->area_type->get_zoner() : false;
		$states = is_object( $this->zoner ) && ! is_wp_error( $this->zoner ) ? $this->zoner->get_stati() : array();

		$this->state_map = array();
		// create the state map based on the valid states
		foreach ( $states as $abbr => $state )
			$this->state_map[ $state[0] ] = $state[3];
	}

	// grab the next event to use for the next sub-report
	protected function _next_report() {
		$this->event = isset( $this->events[ $this->event_ind ] ) ? $this->events[ $this->event_ind ] : null;
		$this->event_ind++;
	}

	// run the report. this report is really several smaller reports. as such, we need to run it multiple times internally. this function overrides the core class functionality to accomplish that task
	protected function _results() {
		// if the needed request fields are not present, then bail now
		if ( ! $this->has_required_request_fields() )
			return;

		// if the report is not supposed to run yet, then bail
		if ( ! $this->_verify_run_report() )
			return;

		// setup the data we need for all sub-reports
		$this->_before_starting();

		$displayed_pf_version = false;
		// if this is a printer frinedly version, try to load the data from the supplied csv_filename
		if ( $this->is_printer_friendly() && isset( $_REQUEST['csv-source'] ) )
			$displayed_pf_version = $this->_from_csv_source();

		// if the printer friendly version was not displayed, then load and draw the from form version
		if ( ! $displayed_pf_version )
			$this->_from_form_parameters();
	}

	// load the results and display the specific table, based on the supplied csv source
	protected function _from_csv_source() {
		// find the path to the csv file
		$csv_path = $this->_csv_path();

		// find the filename of the csv file to use
		$basename = basename( $_REQUEST['csv-source'] );

		// if the csv file does not exist, or we cannot open it, then bail
		if ( ! @file_exists( $csv_path['path'] . $basename ) || ! ( $file = @fopen( $csv_path['path'] . $basename, 'r' ) ) )
			return false;
		fgetcsv( $file );
		$headers = array_keys( $this->csv_report_columns() );

		$row_cnt = 0;
		$oiids = array();
		// while there are rows to read....
		while ( $row = fgetcsv( $file ) ) {
			$row = array_combine( $headers, $row );
			$oiids[ $row['order_item_id'] ] = $row['order_id'];
			$row_cnt++;

			// if we have reached the row processing limit, then process these rows now, and reset our buffers
			if ( $row_cnt >= $this->limit ) {
				// load all the order item meta
				$oi_data = $this->_order_item_meta_from_oiid_list( $oiids );

				// if we have not yet assigned the current event for this report, attempt to do so now
				$first = current( $oi_data );
				if ( ! isset( $this->event ) && is_object( $first ) && isset( $first->_event_id ) ) {
					$this->event_id = $first->_event_id;
					$this->event = get_post( $first->_event_id );
				}

				// process the rows we found
				$this->_handle_row_group( $oi_data, false );
				$row_cnt = 0;
				$oiids = array();
			}
		}
		if ( $row_cnt > 0 ) {
			// load all the order item meta
			$oi_data = $this->_order_item_meta_from_oiid_list( $oiids );

			// if we have not yet assigned the current event for this report, attempt to do so now
			$first = current( $oi_data );
			if ( ! isset( $this->event ) && is_object( $first ) && isset( $first->_event_id ) ) {
				$this->event_id = $first->_event_id;
				$this->event = get_post( $first->_event_id );
			}

			// process the rows we found
			$this->_handle_row_group( $oi_data, false );
		}
		fclose( $file );

		// if no data was loaded/sorted, then fail
		if ( empty( $this->totals ) )
			return false;

		$title = isset( $_REQUEST['report_title'] ) ? $_REQUEST['report_title'] : __( 'Report', 'qsot-reporting' );
		// draw the reports
		$this->totals = $this->_percentages( $this->totals );
		$this->_draw_report( $this->totals, null, $title );

		return true;
	}

	// process the report generation, based on the submitted form request
	protected function _from_form_parameters() {
		// grab the next sub-report's event
		$this->_next_report();

		// while there are events to run the report for...
		while ( null !== $this->event ) {
			// start the csv output file. if that fails, there is no point in continuing
			if ( ! ( $csv_file = $this->_open_csv_file( $this->event->ID, 'event-' ) ) )
				return $this->_error( new WP_Error( 'no_csv_file', __( 'Could not open the CSV file path. Aborting report generation.', 'opentickets-community-edition' ) ) );
			elseif ( is_wp_error( $csv_file ) )
				return $this->_error( $csv_file );

			// tell the report is about to start running
			$this->_starting();

			$all_html_rows = 0;
			// run the report, while there are still rows to process
			while ( $group = $this->more_rows() )
				$all_html_rows += $this->_handle_row_group( $group, $csv_file );

			// draw the html version header
			$this->_html_report_header( '', $csv_file );

			// before we close the footer, allow reportss to add some logic
			$this->_before_html_footer( $all_html_rows );

			// draw the html version footer
			$this->_html_report_footer( $csv_file );

			// close the csv file
			$this->_close_csv_file( $csv_file );

			// grab the next sub-report's event
			$this->_next_report();
		}
	}

	// handle the subgroup of rows, while running the report. return the number of rows we generated
	protected function _handle_row_group( $group, $csv_file ) {
		// gather all the information that is used to create both csv and html versions of the report, for the found rows
		$data = $this->aggregate_row_data( $group );

		// add this group of results to the csv report
		if ( $csv_file )
			$this->_csv_render_rows( $data, $csv_file );

		// aggregate the totals rows, based on the existing data for this report and these new rows
		$this->_aggregate_totals( $data );

		// clean up the memory
		$this->_clean_memory();

		return 0;
	}

	// draw a descreet report
	protected function _draw_report( $results, $csv_file, $heading='' ) {
		// draw the html version header
		$this->_html_report_header( $heading, $csv_file );

		// before we close the footer, allow reportss to add some logic
		$this->_before_html_footer( $results );

		// draw the html version footer
		$this->_html_report_footer( $csv_file );
	}

	// accept a sub-group of rows, and add their totals to the running totals, for the summary report that is displayed in the html
	protected function _aggregate_totals( $group ) {
		// cycle through the group and aggregate the data, indexing it by the payment type
		foreach ( $group as $row ) {
			// if the payment type does not have any totals yet, then make an entry for it
			if ( ! isset( $this->totals[ $row['method'] ] ) ) {
				$this->totals[ $row['method'] ] = $this->default_totals();
				$this->totals[ $row['method'] ]['method'] = $row['method_title'];
			}

			// tally up the running totals
			$this->totals[ $row['method'] ]['subtotal'] += $row['subtotal'];
			$this->totals[ $row['method'] ]['units'] += $row['quantity'];
			$this->totals[ $row['method'] ]['discount'] += $row['discount'];
			$this->totals[ $row['method'] ]['discount_units'] += ( $row['discount'] > 0 ? $row['quantity'] : 0 );
			$this->totals[ $row['method'] ]['total'] += $row['total'];
		}
	}

	// allow reports to add stuff to the bottom of the table if needed
	protected function _before_html_footer( $all_html_rows ) {
		// tally the percentages
		$capacity = get_post_meta( $this->event_id, '_capacity', true );
		$totals = $this->totals;
		foreach ( $totals as $ind => $row ) {
			$totals[ $ind ]['capacity'] = $capacity;
			$totals[ $ind ]['percent'] = 100 * ( $totals[ $ind ]['units'] / $capacity );
			$totals[ $ind ]['discount_percent'] = 100 * ( $totals[ $ind ]['discount_units'] / $capacity );
		}

		// format the columns in each row
		foreach ( $totals as $ind => $row ) {
			$totals[ $ind ]['subtotal'] = wc_price( $row['subtotal'] );
			$totals[ $ind ]['discount'] = wc_price( $row['discount'] );
			$totals[ $ind ]['total'] = wc_price( $row['total'] );
			$totals[ $ind ]['percent'] = sprintf( '%01' . wc_get_price_decimal_separator() . '2f', $totals[ $ind ]['percent'] ) . '%';
			$totals[ $ind ]['discount_percent'] = sprintf( '%01' . wc_get_price_decimal_separator() . '2f', $totals[ $ind ]['discount_percent'] ) . '%';
		}

		// final filter before output of totals
		$totals = apply_filters( 'qsot-' . $this->slug . '-report-summary-report-totals', $totals, $this->event );
		$all_html_rows = count( $totals );

		// draw the summary report html
		if ( ! empty( $all_html_rows ) ) {
			$this->_html_report_rows( $totals );
		// otherwise, if no html rows were printed, then print a row indicating that
		} else {
			$columns = count( $this->html_report_columns() );
			echo '<tr><td colspan="' . $columns . '">' . __( 'There are no tickts sold for this event yet.', 'opentickets-community-edition' ) . '</td></tr>';
		}
	}

	// override the html report header to include the title of the subreport
	protected function _html_report_header( $heading='', $csv_file=array() ) {
		// if this is the printer friendly version, display the report title
		if ( $this->is_printer_friendly() ) {
			?><h2><?php echo sprintf( __( 'By Event: %s', 'opentickets-community-edition' ), apply_filters( 'the_title', $this->event->post_title, $this->event->ID ) ) ?></h2><?php
		}

		?><div class="sub-report"><h3><?php echo $heading ? $heading : apply_filters( 'the_title', $this->event->post_title, $this->event->ID ) ?></h3><?php

		// draw the csv link
		$this->_csv_link( $csv_file );

		parent::_html_report_header( ! empty( $this->totals ) );
	}

	// draw the report result footer, in html form
	protected function _html_report_footer( $csv_file=array() ) {
		// get the list of report columns
		$columns = $this->html_report_columns();

		// construct the footer of the resulting table
		?>
					</tbody>
					<tfoot>
						<tr>
							<?php
								foreach ( $columns as $column => $column_settings ) {
									// skip the text fields
									if ( 'method' == $column ) {
										echo '<th>&nbsp;</th>';
										continue;
									// total the aggregate number of all the numberic columns
									} else if ( in_array( $column, array( 'subtotal', 'units', 'percent', 'discount', 'discount_units', 'discount_percent', 'total' ) ) ) {
										$total = 0;
										foreach ( $this->totals as $row )
											$total += $row[ $column ];

										$column = esc_attr( $column );
										// if the field is a percent, then draw a percent output
										if ( in_array( $column, array( 'percent', 'discount_percent' ) ) ) {
											echo sprintf( '<th class="%s">%s</th>', $column, $this->format_number( $total ) . '%' );
										// or if the field is a dollar amount, then draw a currency output
										} else if ( in_array( $column, array( 'subtotal', 'discount', 'total' ) ) ) {
											echo sprintf( '<th class="%s">%s</th>', $column, wc_price( $total ) );
										// otherwise, just assume it is an integer output
										} else {
											echo sprintf( '<th class="%s">%s</th>', $column, $this->format_number( $total, 0 ) );
										}
									// otherwise, allow modification from outside
									} else {
										echo '<th class="' . $column . '">' . force_balance_tags( apply_filters( 'qsot-' . $this->slug . '-report-footer-column', $column, $column, $this->totals ) ) . '</th>';
									}
								}
							?>
						</tr>
					</tfoot>
				</table>
				<?php
					// draw the csv link
					$this->_csv_link( $csv_file );
				?>
			</div>
		<?php
	}

	// send warnings about deprecated filters
	public function deprectated_filter( $val ) {
		$replacement = null;
		// determine if there is a one to one replacement
		switch ( current_filter() ) {
			case 'qsotc-seating-report-csv-row': $replacement = 'filter:' . 'qsot-' . $this->slug . '-report-csv-row'; break;
			case 'qsot-seating-report-fields': $replacement = 'filter:' . 'qsot-' . $this->slug . '-report-csv-columns OR qsot-' . $this->slug . '-report-html-columns'; break;
			case 'qsot-seating-report-compile-rows-lines':
			case 'qsotc-seating-report-compile-rows-occupied': $replacement = 'filter:' . 'qsot-' . $this->slug . '-report-data-row'; break;
			case 'qsot-seating-report-compile-rows-available':
			case 'qsotc-seating-report-compile-rows-available': $replacement = 'filter:' . 'qsot-' . $this->slug . '-report-before-html-footer'; break;
		}

		// pop the error
		_deprecated_function( 'filter:' . current_filter(), '1.13.0', null );

		// pass through
		return $val;
	}

	// handle the ajax requests for this report
	protected function _process_ajax() {
		// if the parent_id changed, then just pop a new form
		if ( isset( $_REQUEST['reload-form'] ) || ! isset( $_REQUEST['parent_event_id'], $_REQUEST['last_parent_id'] ) || empty( $_REQUEST['parent_event_id'] ) || $_REQUEST['parent_event_id'] != $_REQUEST['last_parent_id'] ) {
			$this->_form();
			exit;
		}

		// otherwise, pop the results table
		$this->_results();
		exit;
	}

	// augment the printerfriendly url
	public function printer_friendly_url( $csv_file, $report ) {
		// get the base printer friendly url from the parent class
		$url = QSOT_Admin_Report::printer_friendly_url( $csv_file, $report );

		// add our special params
		$url = add_query_arg( array(
			'sa' => $this->slug,
			'csv-source' => basename( $csv_file['path'] ),
			'report_title' => is_numeric( $csv_file['id'] ) ? get_the_title( $csv_file['id'] ) : __( 'Summary Report', 'qsot-reporting' ),
			'date_from' => $_REQUEST['date_from'],
			'date_to' => $_REQUEST['date_to'],
		), $url );

		return $url;
	}

	// control the form for this report
	public function form() {
		// determine whether we need the second part of the form or not
		$extended_form = $this->_verify_run_report();

		// check if the parent event_id was was submitted, becuase it is requried to get a list of child events
		$parent_ids = isset( $_REQUEST['parent_event_id'] ) ? array_filter( wp_parse_id_list( $_REQUEST['parent_event_id'] ) ) : array();
		// misleading name. hopefully select2 init bug will get fixed so we can make this a multi select later

		// get the list of parent posts
		$selected = $parent_ids ? get_posts( array(
			'post_type' => 'qsot-event',
			'post_status' => 'any',
			'post_per_page' => -1,
			'fields' => 'ids',
			'post__in' => $parent_ids,
		) ) : array();

		// aggregate a list of selected elements
		$selected_parent = array();
		$min = $max = null;
		if ( ! empty( $selected ) ) foreach ( $selected as $id ) {
			// add the parent event to the list of selected parent events, so that we can properly select the already selected items in the parent event list when ajax refreshes the form
			$title = get_the_title( $id );
			$selected_parent = array( 'id' => $id, 'text' => wp_get_post_parent_id( $id ) ? $title : sprintf( __( 'All "%s" events', 'qsot-reports' ), $title ) );
			list( $start, $end ) = apply_filters( 'qsot-event-date-range', array( date( 'Y-m-d' ), date( 'Y-m-d' ) ), array( 'event_id' => $id ) );

			// determine the min start date and max end date of all the selected events
			$start = strtotime( $start );
			$end = strtotime( $end );
			$min = null !== $min ? min( $min, $start ) : ( $start ? $start : null );
			$max = null !== $max ? max( $max, $end ) : ( $end ? $end : null );
		}

		// configure the extra ajax search options
		$extra_options = array(
			'only_parents' => true,
			'orderby' => 'start',
			'order' => 'asc',
		);

		?>
			<div class="main-form">
				<span title="<?php echo esc_attr( __( 'Select all event series to generate the ticket sales report for.', 'qsot-reporting' ) ) ?>">
					<label><?php _e( 'Parent Event', 'qsot-reporting' ) ?></label>
					<input type="hidden" class="use-select2" style="width:100%; max-width:450px; display:inline-block !important;" name="parent_event_id" id="parent_event_id" data-minchar="0"
							<?php if ( ! empty( $selected_parent ) ): ?>data-init-value="<?php echo esc_attr( @json_encode( $selected_parent ) ) ?>" <?php endif; ?>
							data-init-placeholder="<?php echo esc_attr( __( 'Select an Event', 'opentickets-community-edition' ) ) ?>" data-sa="find-event" data-action="qsot-admin-report-ajax"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'do-qsot-admin-report-ajax' ) ) ?>" data-extra="<?php echo esc_attr( @json_encode( $extra_options ) ) ?>" />
				</span>

				<input type="<?php echo $extended_form ? 'button' : 'submit' ?>" class="button pop-loading-bar refresh-form" data-target="#report-form" data-scope="form"
						value="<?php echo esc_attr( __( 'Use this Event', 'opentickets-community-edition' ) ) ?>" />
			</div>

			<?php if ( ! empty( $extended_form ) ): ?>
				<?php
					$start = $min > 90000 ? date( 'c', $min ) : date( 'c' );
					$end = $max > 90000 ? date( 'c', $max ) : date( 'c', strtotime( '+1 day' ) );
				?>
				<div class="extended-form">
					<input type="hidden" name="last_parent_id" value="<?php echo esc_attr( implode( ',', $parent_ids ) ) ?>" rel="last-parent" />
					<label><?php _e( 'Date Range', 'qsot-reporting' ) ?></label>

					<input type="text" id="date_from" name="date_from" class="use-i18n-datepicker" role="from" scope=".extended-form"
							data-init-date="<?php echo esc_attr( $start ) ?>"
							title="<?php _e( 'From Date', 'qsot-reporting' ) ?>" data-display-format="<?php echo esc_attr( __( 'mm-dd-yy', 'qsot-reporting' ) ) ?>" data-mode="icon:dashicons-calendar-alt" />
					<?php _e( ' to ', 'qsot-reporting' ) ?>
					<input type="text" id="date_to" name="date_to" class="use-i18n-datepicker" role="to" scope=".extended-form"
							data-init-date="<?php echo esc_attr( $end ) ?>"
							title="<?php _e( 'To Date', 'qsot-reporting' ) ?>" data-display-format="<?php echo esc_attr( __( 'mm-dd-yy', 'qsot-reporting' ) ) ?>" data-mode="icon:dashicons-calendar-alt" />
					<div class="helper"><?php _e( 'A date range within the span of the series. Used to determine which individual events will have their reports generated.', 'qsot-reporting' ) ?></div>

					<input type="submit" class="button-primary" value="<?php echo esc_attr( __( 'Show Report', 'opentickets-community-edition' ) ) ?>" />
				</div>
			<?php endif; ?>
		<?php
	}

	// augment the run report verification check, so that it requires the event_id and that the last_parent_id equals the submitted parent_event_id
	protected function _verify_run_report( $only_orig=false ) {
		// first check if it passes standard validation
		if ( ! parent::_verify_run_report() )
			return false;

		// if we passed the above and are being asked for only the original results, then succeed now
		if ( $only_orig || $this->is_printer_friendly() )
			return true;

		// if there is no parent event id specified, then fail
		if ( ! isset( $_REQUEST['parent_event_id'] ) )
			return false;

		return true;
	}

	// the report should define a function to get a partial list of rows to process for this report. for instance, we don't want to have one group of 1,000,000 rows, run all at once, because
	// the memory implications on that are huge. instead we would need to run it in discreet groups of 1,000 or 10,000 rows at a time, depending on the processing involved
	public function more_rows() {
		global $wpdb;

		// valid states
		$in = "'" . implode( "','", array_filter( array_map( 'trim', array_keys( $this->state_map ) ) ) ) . "'";

		// grab the next group of matches
		$matches = $wpdb->get_results( $wpdb->prepare(
			'select order_item_id, order_id from ' . $wpdb->qsot_event_zone_to_order . ' where event_id = %d and state in (' . $in . ') order by since limit %d offset %d',
			$this->event_id,
			$this->limit,
			$this->offset
		) );

		// increment the offset for the next loop
		$this->offset += $this->limit;

		$oiids = array();
		// get an indexed list of order_item_id => order_id
		foreach ( $matches as $match )
			$oiids[ (string)intval( $match->order_item_id ) ] = $match->order_id;

		// if there are no order items, then bail
		if ( empty( $oiids ) )
			return array();

		return $this->_order_item_meta_from_oiid_list( $oiids );
	}

	// the report should define a function to process a group of results, which it contructed in the more_rows() method
	public function aggregate_row_data( array $group ) {
		$order_ids = $order_item_ids = array();
		// create a list of order_ids, based on the rows in this group
		foreach ( $group as $row )
			$order_ids[] = $row->order_id;

		// normalize the lists
		$order_ids = array_filter( array_map( 'absint', $order_ids ) );

		// get all the order meta, for all orders, and then index it by order_id
		$order_meta = $this->_get_order_meta( $order_ids );

		// get all the products for the unique ticket types present in the row list
		$ticket_types = $this->_get_ticket_types( $group );

		$final = array();
		// finally, put it all together
		foreach ( $group as $row ) {
			// if we can load the zone info for this row, then do so
			if ( isset( $row->_zone_id ) && is_object( $this->zoner ) && is_callable( array( $this->zoner, 'get_zone_info' ) ) )
				$zone = $this->zoner->get_zone_info( $row->_zone_id );
			else
				$zone = (object)array( 'id' => -1, 'name' => '-' );

			$total = $this->_item_meta( $row, '_line_total', 0 );
			$subtotal = $this->_item_meta( $row, '_line_subtotal', 0 );
			// construct the finalized report row for this group
			$final[] = apply_filters( 'qsot-' . $this->slug . '-report-data-row', array(
				'order_item_id' => $row->order_item_id,
				'first_name' => $this->_order_meta( $order_meta, '_billing_first_name', $row ),
				'last_name' => $this->_order_meta( $order_meta, '_billing_last_name', $row ),
				'method' => $total > 0 ? $this->_order_meta( $order_meta, '_payment_method', $row ) : 'free',
				'method_title' => $total > 0 ? $this->_order_meta( $order_meta, '_payment_method_title', $row ) : __( 'Free', 'qsot-reporting' ),
				'order_id' => $row->order_id,
				'subtotal' => $subtotal,
				'taxes' => $this->format_number( $this->_item_meta( $row, '_line_tax', 0 ) ),
				'discount' => $subtotal - $total,
				'total' => $total,
				'event_name' => $this->event_name,
				'zone' => isset( $zone->name ) && ! empty( $zone->name ) ? $zone->name : $zone->abbr,
				'ticket_type' => isset( $this->ticket_types[ $row->_product_id ] ) ? $this->ticket_types[ $row->_product_id ]->_cached_title : __( '(unknown)', 'qsot-reporting' ),
				'quantity' => $row->_qty,
				'_raw' => $row,
			), $row, $this->event, isset( $order_meta[ $row->order_id ] ) ? $order_meta[ $row->order_id ] : array() );
		}

		return $final;
	}

	// get a very specific piece of order item meta, based on the complete item record and the name of the field to obtain
	protected function _item_meta( $item, $field, $default='' ) {
		return isset( $item->{ $field } ) ? $item->{ $field } : $default;
	}

	// calculate the percentages based on the event and the line of totals
	protected function _percentages( $results ) {
		// find the capacity to use for the percentage calcs
		if ( is_object( $this->event ) ) {
			$capacity = get_post_meta( $this->event->ID, '_capacity', true );
		} else {
			$capacity = 0;
			foreach ( $this->events as $event )
				$capacity += get_post_meta( $event->ID, '_capacity', true );
		}

		// tally the percentages
		foreach ( $results as $ind => $row ) {
			$results[ $ind ]['capacity'] = $capacity;
			$results[ $ind ]['percent'] = 100 * ( $results[ $ind ]['units'] / $capacity );
			$results[ $ind ]['discount_percent'] = 100 * ( $results[ $ind ]['discount_units'] / $capacity );
		}

		return $results;
	}

	// get a list of unique ticket types based on the list of rows
	protected function _get_ticket_types( $group ) {
		// cycle through the group of rows and load any products that have not yet been loaded
		foreach ( $group as $row ) {
			if ( isset( $row->_product_id ) && ! isset( $this->ticket_types[ $row->_product_id ] ) ) {
				$product = wc_get_product( $row->_product_id );
				if ( is_object( $product ) && ! is_wp_error( $product ) ) {
					$product->_cached_title = $product->get_title();
					$this->ticket_types[ $row->_product_id ] = $product;
				}
			}
		}
	}
}

endif;

return new QSOT_Ticket_Sales_by_Event_Report();
