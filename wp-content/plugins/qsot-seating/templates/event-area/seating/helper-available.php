<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') ); ?>
<span><?php echo sprintf(
	__( 'There are currently %s tickets available.', 'qsot-seating' ),
	( 'yes' == apply_filters( 'qsot-get-option-value', 'no', 'qsot-show-available-quantity' ) ) ? '<span class="available"></span>' : ''
) ?></span>
