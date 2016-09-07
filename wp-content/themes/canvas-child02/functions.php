<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// BEGIN ENQUEUE PARENT ACTION
// AUTO GENERATED - Do not modify or remove comment markers above or below:

if ( !function_exists( 'chld_thm_cfg_parent_css' ) ):
    function chld_thm_cfg_parent_css() {
        wp_enqueue_style( 'chld_thm_cfg_parent', trailingslashit( get_template_directory_uri() ) . 'style.css', array(  ) );
    }
endif;
add_action( 'wp_enqueue_scripts', 'chld_thm_cfg_parent_css', 10 );

// END ENQUEUE PARENT ACTION

// if the user comes to the page via the app, then load a secondary style
function opg_load_secondary_style() {
	// if we are not in the app, bail now
	$mode = isset( $_GET['viewMode'] ) ? strtolower( $_GET['viewMode'] ) : ( isset( $_GET['viewmode'] ) ? strtolower( $_GET['viewmode'] ) : false );
	if ( 'inpartygoapp' !== $mode )
		return false;

	// add the stylesheet
	wp_enqueue_style( 'opg-app-style', trailingslashit( get_stylesheet_directory_uri() ) . 'app.css', array() );
}
add_action( 'init', 'opg_load_secondary_style', 1 );
