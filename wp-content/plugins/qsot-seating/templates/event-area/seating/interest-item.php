<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') ); ?>
<div class="item" rel="interest-item">
	<a href="#" class="remove-link" rel="remove-btn">X</a>
	<span class="pending"><?php _e( 'Pending', 'qsot-seating' ) ?></span>
	<span rel="tt_display"></span>
	<input type="hidden" name="zone[]" value="" rel="zone" />
	<input type="hidden" name="ticket-type[]" value="" rel="ticket-type" />
	<input type="button" value="<?php _e( 'Pending', 'qsot-seating' ) ?>" class="button continue-btn" rel="continue-btn" />
</div>
