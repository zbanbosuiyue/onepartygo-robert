<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// find the default template
$template = apply_filters( 'qsot-locate-template', '', array( 'shortcodes/upcoming-events.php' ), false, false );

// if the template exists, load it
if ( $template )
	include $template;
