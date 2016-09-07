<?php ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) ? die( header( 'Location: /' ) ) : null;

// add the admin functionality of the reporting extension
class QSOT_Reporting_Reports {
	// holder for otce plugin settings
	protected static $o = null;
	protected static $options = null;

	// holder for our list of reports
	protected static $reports = array();

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

		// allow reports to register themselves
		add_action( 'qsot-reporting-register-report', array( __CLASS__, 'register_report' ), 10, 1 );
		add_filter( 'qsot-reporting-get-reports', array( __CLASS__, 'get_reports_list' ), 10, 1 );

		// handle the output of our custom reports
		add_filter( 'qsot_admin_reports', array( __CLASS__, 'admin_reports' ), 1000, 1 );

		// allow other classes to get a report by the slug
		add_filter( 'qsot-reporting-get-report', array( __CLASS__, 'get_report' ), 100, 2 );

		// late load stuff
		add_action( 'plugins_loaded', array( __CLASS__, 'plugins_loaded' ), 10 );

		// load all reports
		do_action( 'qsot-load-includes', 'reports', '#^.+\.report\.php$#i');

		// register and load assets
		add_action( 'admin_init', array( __CLASS__, 'register_admin_assets' ), 10 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ), 10, 1 );

		// register some admin ajax events
		$aj = QSOT_Ajax::instance();
		$aj->register( 'find-event', array( __CLASS__, 'aj_find_event' ), 'edit_posts', 10, 'qsot-admin-report-ajax' );
	}

	// after plugins are loaded, add more hooks
	public static function plugins_loaded() {
		// figure out the admin page slug
		$hook = apply_filters( 'qsot-get-menu-slug', '' );
		if ( '' === $hook )
			return;

		// when the reports page loads, call an appropriate header setup function
		add_action( 'load-' . $hook, array( __CLASS__, 'report_header' ), 10 );
	}

	// register the assets used by this extension in the admin
	public static function register_admin_assets() {
		// get the rusable values
		$url = QSOT_Reporting_Launcher::plugin_url();
		$version = QSOT_Reporting_Launcher::version();

		// register the advanced reporting javascript
		wp_register_script( 'qsot-adv-reporting-tools', $url . 'assets/js/utils/tools.js', array( 'qsot-admin-tools' ), $version );
		wp_register_script( 'qsot-adv-reporting', $url . 'assets/js/admin/ui.js', array( 'qsot-adv-reporting-tools' ), $version );

		// localize the tools now, because we have all the needed info at this point
		wp_localize_script( 'qsot-adv-reporting-tools', '_qsot_reporting_settings', array(
			'nonce' => wp_create_nonce( 'qsot-admin-report-ajax' ),
			'msgs' => array(
			),
		) );
	}

	// load the assets used by this extension in the admin
	public static function enqueue_admin_assets( $hook ) {
		// if this is not the reporting page, then bail
		if ( 'toplevel_page_opentickets' !== $hook )
			return;

		// otherwise queue the assets we know we need
		wp_enqueue_script( 'qsot-adv-reporting' );

		// and tell others we are on the reports page
		do_action( 'qsot-load-reporting-assets' );
	}

	// fetch a report based on the slug
	public static function get_report( $current, $slug ) {
		return is_string( $slug ) && strlen( $slug ) && isset( self::$reports[ $slug ] ) ? self::$reports[ $slug ] : $current;
	}

	// call the appropriate header functions when a report page loads
	public static function report_header() {
		// figure out the current report slug
		$current = isset( $_REQUEST['tab'] ) ? $_REQUEST['tab'] : false;

		// if there is a report in our list with that slug, then call the appropriate header functions
		if ( $current && isset( self::$reports[ $current ] ) ) {
			self::$reports[ $current ]->maybe_report_run();
			self::$reports[ $current ]->report_header();
		}
	}

	// fetch a list of our registered reports
	public static function get_reports_list( $list ) {
		return self::$reports;
	}

	// function to handle the registering of a report
	public static function register_report( $report ) {
		// bail if the supplied report is not a report
		if ( ! is_object( $report ) || ! ( $report instanceof QSOT_Reporting_Report ) )
			return;

		// add the report to our internal list
		self::$reports[ $report->slug() ] = $report;
	}

	// infuse our list of reports into the master report list for the core OTCE plugin
	public static function admin_reports( $reports ) {
		// cycle through our list of reports, and add them to the master list
		foreach ( self::$reports as $slug => $report ) {
			// normalize the name and description
			$name = apply_filters( 'the_title', $report->title() );
			$desc = apply_filters( 'the_content', $report->description() );

			// add the report to the list
			$reports[ $slug ] = array(
				'title' => $name,
				'reports' => array( 
					'report-1' => array(
						'title' => $name,
						'description' => $desc,
						'callback' => array( &$report, 'draw_report_form' ),
						'function' => array( &$report, 'draw_report_form' ),
					),
				),
			);
		}

		return $reports;
	}

	// generic ajax method to find products based on a search string
	public static function aj_find_event( $resp ) {
		// get the search string
		$search = isset( $_REQUEST['q'] ) ? $_REQUEST['q'] : '';

		// fetch and normalize the options for the search
		$options = wp_parse_args( $_REQUEST, array(
			'only_parents' => 0,
			'include_all' => 0,
			'orderby' => 'start',
			'order' => 'asc',
		) );
		$options['order'] = strtolower( $options['order'] );

		// construct the first query
		$qargs = array(
			'post_type' => 'qsot-event',
			'post_status' => array( 'publish' ),
			'posts_per_page' => -1,
			's' => $search,
			'fields' => 'ids',
		);
		if ( current_user_can( 'edit_private_posts' ) )
			$qargs['post_status'][] = 'private';
		if ( apply_filters( 'qsot-show-hidden-events', current_user_can( 'edit_posts' ) ) )
			$qargs['post_status'][] = 'hidden';
		$order = in_array( $options['order'], array( 'asc', 'desc' ) ) ? $options['order'] : 'asc';
		if ( $options['only_parents'] )
			$qargs['post_parent'] = 0;
		if ( $options['orderby'] ) {
			switch ( $options['orderby'] ) {
				case 'id': $qargs['orderby'] = array( 'id' => $order, 'post_parent' => 'asc' ); break;
				default:
					$qargs['orderby'] = array( 'meta_value' => $order, 'post_parent' => 'asc' );
					$qargs['meta_key'] = '_start';
					$qargs['meta_type'] = 'DATETIME';
				break;
			}
		}

		// run the query
		$ids = get_posts( $qargs );

		// if there are no results, then bail
		if ( empty( $ids ) ) {
			$resp['e'][] = __( 'No Results found', 'opentickets-community-edition' );
			return $resp;
		}
		
		// get all results
		$posts = get_posts( array( 'post__in' => $ids, 'post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'post__in' ) );

		// if there are no results, then bail
		if ( empty( $posts ) ) {
			$resp['e'][] = __( 'No Results found', 'opentickets-community-edition' );
			return $resp;
		}

		// construct the results
		$resp['r'] = array();
		if ( $options['include_all'] )
			$resp['r'][] = array( 'id' => 0, 'text' => __( '[All Events]', 'qsot-reports' ) );
		foreach ( $posts as $post ) {
			$title = apply_filters( 'the_title', $post->post_title, $post->ID );
			$resp['r'][] = array( 'id' => $post->ID, 'text' => $post->post_parent ? $title : sprintf( __( 'All "%s" events', 'qsot-reports' ), $title ) );
		}
		$resp['s'] = true;

		return $resp;
	}

	// setup the options that are available to control our 'Display Options'
	protected static function _setup_admin_options() {
	}
}

/*
// the base report class, used as the part class for all registered reports
abstract class QSOT_Reporting_Report {
	protected $proper_name = '';
	protected $report_description = '';
	protected $args = array();

	// constructor
	public function __construct( $args='' ) {
		// if there were any passed settings, then store them
		if ( $args )
			$this->args = wp_parse_args( $args, $this->args );
	}

	// return the proper name of the report
	public function title() { return $this->proper_name; }

	// return the slug of this report
	public function slug() { return sanitize_title_with_dashes( $this->proper_name ); }

	// return the description of the report
	public function description() { return $this->report_description; }

	// maybe run the report, if the validation passes
	public function maybe_report_run() {
		// check the nonce that was sent in the request
		if ( ! $this->_verify_nonce() )
			return;

		$this->report_run();
	}

	// draw the report form wrapper, and call the report form internal function
	public function draw_report_form() {
		$multipart = isset( $this->args['multipart'] ) && $this->args['multipart'] ? 'enctype="multipart/form-data"' : '';
		?>
			<div class="reports-wrapper">
				<form action="<?php echo esc_attr( esc_url( remove_query_arg( 'updated' ) ) ) ?>" method="POST" id="report-form" <?php echo $multipart ?> role="form">

					<div class="form-inner">
						<?php $this->report_form() ?>
					</div>

					<div class="actions">
						<?php $this->_create_nonce() ?>
						<input type="hidden" name="report-to-run" value="<?php echo esc_attr( $this->slug() ) ?>" />
						<input type="submit" class="button" value="Submit" role="submit" />
					</div>

				</form>

				<script language="javascript">
					if ( ( 'function' == typeof jQuery || 'object' == typeof jQuery ) && 'object' == typeof QS && 'object' == typeof QS.Reports )
						QS.Reports.setup_search_boxes( '#report-form' );
				</script>

				<div class="result-container" id="results" role="results"></div>
			</div>
		<?php
	}

	// verify the nonce we received
	private function _verify_nonce( $action ) {
		return wp_verify_nonce( $_POST['n'], 'reporting-ajax' );
	}

	// create the nonce field used in the reporting forms
	private function _create_nonce() {
		wp_nonce_field( 'reporting-ajax', 'n' );
	}

	// require several functions in the child class
	abstract public function report_run();
	abstract public function report_header();
	abstract public function report_form();
}
*/

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_Reporting_Reports::pre_init();
