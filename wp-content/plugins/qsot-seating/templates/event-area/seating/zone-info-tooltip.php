<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') ); ?>
<div class="qsot-tooltip"><div class="tooltip-positioner">
	<div class="tooltip-wrap">
		<div class="zone"><span class="qslabel"><?php _e( 'Name:', 'qsot-seating' ) ?></span> <span class="zone-name value"></span></div>
		<div class="status"><span class="qslabel"><?php _e( 'Status:', 'qsot-seating' ) ?></span> <span class="status-msg value"></span></div>
		<div class="price"><span class="qslabel"><?php _e( 'Price:', 'qsot-seating' ) ?></span> <span class="zone-price value"></span></div>
		<?php do_action( 'qsots-zone-info-tooltip' ); ?>
	</div>
</div></div>
