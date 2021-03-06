<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

if ( ! class_exists( 'QSOT_Ticket_Sales_by_Date_Report' ) ):

// new, faster, more efficient ticket sales by date report. creates sub-reports, broken down by child event (based on parent event selection), then payment type, to show how many ticket sales have happened per event. also summary
class QSOT_Ticket_Sales_by_Date_Report extends QSOT_Admin_Report {
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

	protected $_csv_files = array();
	protected $sorted_results = array();
	protected $summary_totals = array();

	// initialization specific to this report
	public function init() {
		// setup the report namings
		$this->group_name = $this->name = __( 'Ticket Sales by Date', 'qsot-reporting' );
		$this->group_slug = $this->slug = 'by-date';

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

		// if the date range is not present, then bail
		if ( ! isset( $_REQUEST['date_from'], $_REQUEST['date_to'] ) )
			return;

		return apply_filters( 'qsot-' . $this->slug . '-report-has-required-request-fields', true );
	}

	// before running any part of the report, we need to figure out what events to run the report for
	protected function _before_starting() {
		$this->events = array();
		$this->offset = 0;
		$this->parent_event_id = isset( $_REQUEST['parent_event_id'] ) ? max( 0, intval( $_REQUEST['parent_event_id'] ) ) : 0;
		$this->date_from = date( 'Y-m-d 00:00:00', strtotime( $_REQUEST['date_from'] ) );
		$this->date_to = date( 'Y-m-d 23:59:59', strtotime( $_REQUEST['date_to'] ) );

		$this->_csv_files = array( 'summary' => $this->_open_csv_file( 'summary', 'event-' ) );
		$this->state_map = array();
		// create the map of states to propernames
		foreach ( QSOT_Post_Type_Event_Area::instance()->get_event_area_types() as $area_type ) {
			$zoner = is_object( $area_type ) && ! is_wp_error( $area_type ) ? $area_type->get_zoner() : false;
			$states = is_object( $zoner ) && ! is_wp_error( $zoner ) ? $zoner->get_stati() : array();
			foreach ( $states as $state )
				$this->state_map[ $state[0] ] = $state[3];
		}

		// if this is the printer friendly version, display the report title
		if ( $this->is_printer_friendly() ) {
			?><h2><?php echo sprintf( __( 'By Date Report: %s to %s', 'qsot-reporting' ), $this->date_from, $this->date_to ) ?></h2><?php
		}
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

		// close the csv file
		foreach ( $this->_csv_files as $csv_file )
			$this->_close_csv_file( $csv_file );
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
				$this->_handle_row_group( $this->_order_item_meta_from_oiid_list( $oiids ), false );
				$row_cnt = 0;
				$oiids = array();
			}
		}
		if ( $row_cnt > 0 )
			$this->_handle_row_group( $this->_order_item_meta_from_oiid_list( $oiids ), false );
		fclose( $file );

		// if the summary is present but not any subreports, then add the summary to the subreport list
		if ( empty( $this->sorted_results ) && ! empty( $this->summary_totals ) )
			$this->sorted_results['summary'] = $this->summary_totals;

		// if no data was loaded/sorted, then fail
		if ( empty( $this->sorted_results ) )
			return false;

		$title = isset( $_REQUEST['report_title'] ) ? $_REQUEST['report_title'] : __( 'Report', 'qsot-reporting' );
		// draw the reports
		foreach ( $this->sorted_results as $results ) {
			$this->totals = $this->_percentages( $results );
			$this->_draw_report( $results, null, $title );
		}

		return true;
	}

	// load the results and display the tables, based on the supplied form parameters
	protected function _from_form_parameters() {
		// start the aggregated list of results 
		while ( $group = $this->more_rows() )
			$this->_handle_row_group( $group );

		// draw the summary report
		$this->totals = $this->_percentages( $this->summary_totals );
		$this->_draw_report( $this->summary_totals, $this->_csv_files['summary'], __( 'Summary Report', 'qsot-reporting' ) );

		// draw the individual reports
		foreach ( $this->sorted_results as $event_id => $results ) {
			$this->event = $this->events[ $event_id ];
			$this->totals = $this->_percentages( $results );
			$this->_draw_report( $results, $this->_csv_files[ $event_id ] );
		}
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

	// handle the subgroup of rows, while running the report. return the number of rows we generated
	protected function _handle_row_group( $group, $create_csvs=true ) {
		// gather all the information that is used to create both csv and html versions of the report, for the found rows
		$data = $this->aggregate_row_data( $group );

		// add this group of results to the csv report
		if ( $create_csvs ) foreach ( $data as $event_id => $group ) {
			$this->_csv_render_rows( $group, $this->_csv_files[ $event_id ] );
			$this->_csv_render_rows( $group, $this->_csv_files['summary'] );
		}

		// aggregate the totals rows, based on the existing data for this report and these new rows
		$this->_aggregate_totals( $data );

		// clean up the memory
		$this->_clean_memory();

		return 0;
	}

	// accept a sub-group of rows, and add their totals to the running totals, for the summary report that is displayed in the html
	protected function _aggregate_totals( $group ) {
		// cycle through the indexed-by-event_id list of results
		foreach ( $group as $event_id => $event_group ) {
			// if there is not a sorted results row yet, make one
			if ( ! isset( $this->sorted_results[ $event_id ] ) )
				$this->sorted_results[ $event_id ] = array();

			// cycle through the group and aggregate the data, indexing it by the payment type
			foreach ( $event_group as $row ) {
				// if the payment type does not have any totals yet, then make an entry for it
				if ( ! isset( $this->sorted_results[ $event_id ][ $row['method'] ] ) ) {
					$this->sorted_results[ $event_id ][ $row['method'] ] = $this->default_totals();
					$this->sorted_results[ $event_id ][ $row['method'] ]['method'] = $row['method_title'];
				}

				// this individual set of results
				$this->sorted_results[ $event_id ][ $row['method'] ]['subtotal'] += $row['subtotal'];
				$this->sorted_results[ $event_id ][ $row['method'] ]['units'] += $row['quantity'];
				$this->sorted_results[ $event_id ][ $row['method'] ]['discount'] += $row['discount'];
				$this->sorted_results[ $event_id ][ $row['method'] ]['discount_units'] += ( $row['discount'] > 0 ? $row['quantity'] : 0 );
				$this->sorted_results[ $event_id ][ $row['method'] ]['total'] += $row['total'];

				// if the payment type does not have any totals yet, then make an entry for it
				if ( ! isset( $this->summary_totals[ $row['method'] ] ) ) {
					$this->summary_totals[ $row['method'] ] = $this->default_totals();
					$this->summary_totals[ $row['method'] ]['method'] = $row['method_title'];
				}

				// tally up the running totals
				$this->summary_totals[ $row['method'] ]['subtotal'] += $row['subtotal'];
				$this->summary_totals[ $row['method'] ]['units'] += $row['quantity'];
				$this->summary_totals[ $row['method'] ]['discount'] += $row['discount'];
				$this->summary_totals[ $row['method'] ]['discount_units'] += ( $row['discount'] > 0 ? $row['quantity'] : 0 );
				$this->summary_totals[ $row['method'] ]['total'] += $row['total'];
			}
		}
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

	// allow reports to add stuff to the bottom of the table if needed
	protected function _before_html_footer( $results ) {
		$results = $this->_percentages( $results );
		// format the columns in each row
		foreach ( $results as $ind => $row ) {
			$results[ $ind ]['subtotal'] = wc_price( $row['subtotal'] );
			$results[ $ind ]['discount'] = wc_price( $row['discount'] );
			$results[ $ind ]['total'] = wc_price( $row['total'] );
			$results[ $ind ]['percent'] = sprintf( '%01' . wc_get_price_decimal_separator() . '2f', $results[ $ind ]['percent'] ) . '%';
			$results[ $ind ]['discount_percent'] = sprintf( '%01' . wc_get_price_decimal_separator() . '2f', $results[ $ind ]['discount_percent'] ) . '%';
		}

		// final filter before output of totals
		$results = apply_filters( 'qsot-' . $this->slug . '-report-summary-report-totals', $results, $this->event );
		$all_html_rows = count( $results );

		// draw the summary report html
		if ( ! empty( $all_html_rows ) ) {
			$this->_html_report_rows( $results );
		// otherwise, if no html rows were printed, then print a row indicating that
		} else {
			$columns = count( $this->html_report_columns() );
			echo '<tr><td colspan="' . $columns . '">' . __( 'There are no tickts sold for this event yet.', 'opentickets-community-edition' ) . '</td></tr>';
		}
	}

	// override the html report header to include the title of the subreport
	protected function _html_report_header( $heading='', $csv_file=array() ) {
		?><div class="sub-report"><h3><?php echo $heading ? $heading : apply_filters( 'the_title', $this->event->post_title, $this->event->ID ) ?></h3><?php

		// draw the csv link
		$this->_csv_link( $csv_file );

		parent::_html_report_header();
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
		if ( isset( $_REQUEST['reload-form'] ) ) {
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
		}

		// find the start an end date, and make sure they are assigned properly
		$start = isset( $_REQUEST['date_from'] ) ? strtotime( $_REQUEST['date_from'] ) : strtotime( '-30 days' );
		$end = isset( $_REQUEST['date_to'] ) ? strtotime( $_REQUEST['date_to'] ) : strtotime( 'today 23:59:59' );

		// configure the extra ajax search options
		$extra_options = array(
			'only_parents' => true,
			'include_all' => true,
			'orderby' => 'start',
			'order' => 'asc',
		);

		?>
			<div class="main-form">
				<span title="<?php echo esc_attr( __( 'Choose the date range to run the report for.', 'qsot-reporting' ) ) ?>">
					<?php
						$start = date( 'c', $start );
						$end = date( 'c', $end );
					?>
					<label><?php _e( 'Date Range', 'qsot-reporting' ) ?></label>
					<input type="text" id="date_from" name="date_from" class="use-i18n-datepicker" role="from" scope=".main-form"
							data-init-date="<?php echo esc_attr( $start ) ?>"
							title="<?php _e( 'From Date', 'qsot-reporting' ) ?>" data-display-format="<?php echo esc_attr( __( 'mm-dd-yy', 'qsot-reporting' ) ) ?>" data-mode="icon:dashicons-calendar-alt" />
					<?php _e( ' to ', 'qsot-reporting' ) ?>
					<input type="text" id="date_to" name="date_to" class="use-i18n-datepicker" role="to" scope=".main-form"
							data-init-date="<?php echo esc_attr( $end ) ?>"
							title="<?php _e( 'To Date', 'qsot-reporting' ) ?>" data-display-format="<?php echo esc_attr( __( 'mm-dd-yy', 'qsot-reporting' ) ) ?>" data-mode="icon:dashicons-calendar-alt" />
					<div class="helper"><?php _e( 'A date range within the span of the series. Used to determine which individual events will have their reports generated.', 'qsot-reporting' ) ?></div>
				</span>

				<span title="<?php echo esc_attr( __( 'Select all event series to generate the ticket sales report for.', 'qsot-reporting' ) ) ?>">
					<label><?php _e( 'Parent Event', 'qsot-reporting' ) ?></label>
					<input type="hidden" class="use-select2" style="width:100%; max-width:450px; display:inline-block !important;" name="parent_event_id" id="parent_event_id" data-minchar="0"
							<?php if ( ! empty( $selected_parent ) ): ?>data-init-value="<?php echo esc_attr( @json_encode( $selected_parent ) ) ?>" <?php endif; ?>
							data-init-placeholder="<?php echo esc_attr( __( 'Select an Event', 'opentickets-community-edition' ) ) ?>" data-sa="find-event" data-action="qsot-admin-report-ajax"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'do-qsot-admin-report-ajax' ) ) ?>" data-extra="<?php echo esc_attr( @json_encode( $extra_options ) ) ?>" />
				</span>

				<input type="submit" class="button-primary" value="<?php echo esc_attr( __( 'Show Report', 'opentickets-community-edition' ) ) ?>" />
			</div>
		<?php
	}

	// the report should define a function to get a partial list of rows to process for this report. for instance, we don't want to have one group of 1,000,000 rows, run all at once, because
	// the memory implications on that are huge. instead we would need to run it in discreet groups of 1,000 or 10,000 rows at a time, depending on the processing involved
	public function more_rows() {
		global $wpdb;

		$child_query = '';
		// if the parent event was provided, add a filter for the child events, to the main query
		if ( $this->parent_event_id > 0 ) {
			// get a list of all child events
			$child_ids = get_posts( array(
				'post_type' => 'qsot-event',
				'post_parent' => $this->parent_event_id,
				'post_status' => array( 'publish', 'private', 'hidden' ),
				'fields' => 'ids',
				'posts_per_page' => -1,
			) );

			// if we did not find any child events, even though a parent was specified, then bail
			if ( empty( $child_ids ) )
				return array();

			// construct the child portion of the query
			$child_query = ' and event_id in(' . implode( ',', $child_ids ) . ') ';
		}

		// valid states
		$in = "'" . implode( "','", array_filter( array_map( 'trim', array_keys( $this->state_map ) ) ) ) . "'";

		// grab the next group of matches
		$matches = $wpdb->get_results( $wpdb->prepare(
			'select order_item_id, order_id from ' . $wpdb->qsot_event_zone_to_order . ' where since between %s and %s and state in (' . $in . ')' . $child_query . ' order by since limit %d offset %d',
			$this->date_from,
			$this->date_to,
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
		$this->_get_ticket_types( $group );

		$final = array();
		// finally, put it all together
		foreach ( $group as $row ) {
			// if the order item does not have an event_id, then skip it
			if ( ! isset( $row->_event_id ) )
				continue;

			// if the event is not yet loaded, then load it now
			if ( ! isset( $this->events[ $row->_event_id ] ) ) {
				// attempt to open the csv file for this event
				$this->_csv_files[ $row->_event_id ] = $this->_open_csv_file( $row->_event_id, 'event-' );
				if ( is_wp_error( $this->_csv_files[ $row->_event_id ] ) ) {
					unset( $this->_csv_files[ $row->_event_id ] );
					continue;
				}

				// load all the needed / used event data
				$this->events[ $row->_event_id ] = get_post( $row->_event_id );
				$this->events[ $row->_event_id ]->event_name = apply_filters( 'the_title', $this->events[ $row->_event_id ]->post_title, $this->events[ $row->_event_id ]->ID );
				$this->events[ $row->_event_id ]->event_area = apply_filters( 'qsot-event-area-for-event', false, $row->_event_id );
				$this->events[ $row->_event_id ]->zoner =
						is_object( $this->events[ $row->_event_id ]->event_area ) && ! is_wp_error( $this->events[ $row->_event_id ]->event_area ) && is_object( $this->events[ $row->_event_id ]->event_area->area_type )
						? $this->events[ $row->_event_id ]->event_area->area_type->get_zoner()
						: false;
				$this->events[ $row->_event_id ]->states =
						is_object( $this->events[ $row->_event_id ]->zoner ) && ! is_wp_error( $this->events[ $row->_event_id ]->zoner )
						? $this->events[ $row->_event_id ]->zoner->get_stati()
						: array();
			}

			// if there is no zoner, then skip this record
			if ( ! is_object( $this->events[ $row->_event_id ]->zoner ) || is_wp_error( $this->events[ $row->_event_id ]->zoner ) )
				continue;

			// setup some local vars
			$event = $this->events[ $row->_event_id ];
			$event_name = $this->events[ $row->_event_id ]->event_name;
			$zoner = $this->events[ $row->_event_id ]->zoner;

			// if we can load the zone info for this row, then do so
			if ( isset( $row->_zone_id ) && is_callable( array( $zoner, 'get_zone_info' ) ) )
				$zone = $zoner->get_zone_info( $row->_zone_id );
			else
				$zone = (object)array( 'id' => -1, 'name' => '-' );

			$total = $this->_item_meta( $row, '_line_total', 0 );
			$subtotal = $this->_item_meta( $row, '_line_subtotal', 0 );
			if ( ! isset( $final[ $row->_event_id ] ) )
				$final[ $row->_event_id ] = array();
			// construct the finalized report row for this group
			$final[ $row->_event_id ][] = apply_filters( 'qsot-' . $this->slug . '-report-data-row', array(
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
				'event_name' => $event_name,
				'zone' => isset( $zone->name ) && ! empty( $zone->name ) ? $zone->name : $zone->abbr,
				'ticket_type' => isset( $this->ticket_types[ $row->_product_id ] ) ? $this->ticket_types[ $row->_product_id ]->_cached_title : __( '(unknown)', 'qsot-reporting' ),
				'quantity' => $row->_qty,
				'_raw' => $row,
			), $row, $event, isset( $order_meta[ $row->order_id ] ) ? $order_meta[ $row->order_id ] : array() );
		}

		return $final;
	}

	// get a very specific piece of order item meta, based on the complete item record and the name of the field to obtain
	protected function _item_meta( $item, $field, $default='' ) {
		return isset( $item->{ $field } ) ? $item->{ $field } : $default;
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

return new QSOT_Ticket_Sales_by_Date_Report();
