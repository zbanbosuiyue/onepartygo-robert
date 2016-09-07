<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') ); ?>
<div class="item multiple" rel="own-item">
	<a href="#" class="remove-link" rel="remove-btn">X</a>
	<span rel="tt_display"></span>
	<input type="hidden" name="zone[]" value="" rel="zone" />
	<input type="hidden" name="ticket-type[]" value="" rel="ticket-type" />
	<input type="number" step="1" min="0" max="<?php echo intval( $max ) ?>" rel="qty" name="quantity[]" value="1" class="very-short" />
</div>
