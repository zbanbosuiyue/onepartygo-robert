<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// the core box-office plugin class
class qsot_bo_core {
	// setup the class's functionality
	public static function pre_init() {
		add_action( 'qsot-box-office-activation', array( __CLASS__, 'on_activation' ) );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'update_last_payment' ) );
	}

	// upon payment confirimation, update the 'last payment' meta data
	public static function update_last_payment( $order_id ) {
		update_post_meta( $order_id, '_qsot_last_payment', current_time( 'timestamp' ) );
	}

	// during the plugin activation process, run our setup code
	public static function on_activation() {
		self::_register_roles();
	}

	// register the various new roles
	protected static function _register_roles() {
		// start with a copy of the editor role
		$editor = get_role('editor');
		$roles = array();

		// box-office users are basically editors
		$bocaps = $editor->capabilities;

		// basic additional caps for BOX OFFICE users
		$bo_add_caps = array(
			// allow BO users to create/edit users. required so they can take phone orders
			'create_users',
			'edit_users',
			'list_users',
			// allow them to view woocommerce reports
			'view_woocommerce_reports',
		);

		// post type related caps. BO users must be able to do all manage orders
		$pts = array( 'shop_order' );
		$ptcaps = array( 'edit_%pt%', 'read_%pt%', 'delete_%pt%', 'edit_%pt%s', 'edit_others_%pt%s', 'publish_%pt%s', 'read_private_%pt%s', 'delete_%pt%s', 'delete_private_%pt%s',
			'delete_published_%pt%s', 'delete_others_%pt%s', 'edit_private_%pt%s', 'edit_published_%pt%s' );
		foreach ( $pts as $pt )
			foreach ( $ptcaps as $ptcap )
				$bo_add_caps[] = str_replace( '%pt%', $pt, $ptcap );

		// post type related caps with limited access. BO users need to be able to view all coupons and products, so they can add either to an order
		$pts = array( 'product', 'shop_coupon' );
		$ptcaps = array( 'read_%pt%', 'read_private_%pt%s' );
		foreach ( $pts as $pt )
			foreach ( $ptcaps as $ptcap )
				$bo_add_caps[] = str_replace( '%pt%', $pt, $ptcap );

		// taxonomy related caps. BO users need to be able to assign product terms to products
		$taxes = array( 'product_terms' );
		//$taxcaps = array('manage_%tax%', 'edit_%tax%', 'delete_%tax%', 'assign_%tax%');
		$taxcaps = array( 'assign_%tax%' );
		foreach ( $taxes as $tax )
			foreach ( $taxcaps as $taxcap )
				$bo_add_caps[] = str_replace( '%tax%', $tax, $taxcap );

		// eliminate dupe roles
		$bo_add_caps = array_unique( $bo_add_caps );

		// assign them all active
		foreach ( $bo_add_caps as $cap ) $bocaps[ $cap ] = 1;

		// hard remove some, because we do not want them to have too much access
		$remove = array( 'delete_posts', 'delete_others_posts', 'delete_pages', 'delete_others_pages' );
		foreach ( $remove as $role ) unset( $bocaps[ $role ] );

		// add box-office role to list
		$roles['box-office'] = array(
			'name' => __( 'Box Office', 'qsot-box-office' ),
			'caps' => $bocaps,
		);

		
		// additional roles for BOX OFFICE MANAGER users. start with the BO role as a base
		$bomcaps = $bocaps;

		// basic additional caps
		$bom_add_caps = array( 'delete_posts', 'delete_others_posts', 'delete_pages', 'delete_others_pages' );

		// post type related caps. BOM users should be able to fully edit all products, orders, and coupons, as a generic part of their job
		$pts = array( 'product', 'shop_order', 'shop_coupon' );
		$ptcaps = array( 'edit_%pt%', 'read_%pt%', 'delete_%pt%', 'edit_%pt%s', 'edit_others_%pt%s', 'publish_%pt%s', 'read_private_%pt%s', 'delete_%pt%s', 'delete_private_%pt%s',
			'delete_published_%pt%s', 'delete_others_%pt%s', 'edit_private_%pt%s', 'edit_published_%pt%s' );
		foreach ( $pts as $pt )
			foreach ( $ptcaps as $ptcap )
				$bom_add_caps[] = str_replace( '%pt%', $pt, $ptcap );

		// taxonomy related caps. BOM users should be able to perform all actions with product terms, for creating new tickets and such
		$taxes = array( 'product_terms' );
		$taxcaps = array( 'manage_%tax%', 'edit_%tax%', 'delete_%tax%', 'assign_%tax%' );
		foreach ( $taxes as $tax )
			foreach ( $taxcaps as $taxcap )
				$bom_add_caps[] = str_replace( '%tax%', $tax, $taxcap );

		// unique the list of roles and add them to the actual list
		$bom_add_caps = array_unique( $bom_add_caps );
		foreach ( $bom_add_caps as $cap ) $bomcaps[$cap] = 1;

		// add box office manager role
		$roles['box-office-manager'] = array(
			'name' => __( 'Box Office Manager', 'qsot-box-office' ),
			'caps' => $bomcaps,
		);

		
		// additional roles for CONTENT MANAGER users
		$cocaps = $bomcaps;

		// remove static roles. CM users should not be able to do anything with users
		$remove = array( 'create_users', 'edit_users', 'list_users' );
		foreach ( $remove as $role) unset($cocaps[$role]);

		// remove post type related roles. CM users should not be able to do anything with coupons or orders, but should keep all abilities to change products
		$pts = array( 'shop_order', 'shop_coupon' );
		$ptcaps = array( 'edit_%pt%', 'read_%pt%', 'delete_%pt%', 'edit_%pt%s', 'edit_others_%pt%s', 'publish_%pt%s', 'read_private_%pt%s', 'delete_%pt%s', 'delete_private_%pt%s',
			'delete_published_%pt%s', 'delete_others_%pt%s', 'edit_private_%pt%s', 'edit_published_%pt%s' );
		foreach ( $pts as $pt )
			foreach ( $ptcaps as $ptcap )
				unset( $cocaps[ str_replace( '%pt%', $pt, $ptcap ) ] );

		// basic additional caps. CM users should be able to change the theme options, since this is central to their role
		$com_add_caps = array( 'edit_theme_options' );

		// unique the list of roles and add them to the actual list
		$com_add_caps = array_unique( $com_add_caps );
		foreach ( $com_add_caps as $cap ) $cocaps[ $cap ] = 1;

		// add content manager role
		$roles['content-manager'] = array(
			'name' => __( 'Content Manager', 'qsot-box-office' ),
			'caps' => $cocaps,
		);

		// not sure this is useful, since this runs at activation. only use would be if a plugin was already active that hooked in here, prior to this plugin's activation
		//$roles = apply_filters( 'qsot-roles-to-add', $roles );

		// actually add the roles
		foreach ( $roles as $slug => $role ) {
			if ( isset( $_GET['qsot-refresh-roles'] ) && $_GET['qsot-refresh-roles'] == 1 ) remove_role( $slug );
			add_role( $slug, $role['name'], $role['caps'] );
		}
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	qsot_bo_core::pre_init();
