<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') ); ?>
<?php if ( 'yes' == apply_filters( 'qsot-get-option-value', 'no', 'qsot-locked-reservations' ) ): ?>
	<div class="inner" rel="own-item">
		<a href="#" class="remove-link" rel="remove-btn"><?php _e( 'X', 'qsot-ga-multi-price' ) ?></a>
		<span rel="tt_display"></span>
		<span rel="qty"></span>
	</div>
<?php else: ?>
	<div class="inner" rel="own-item">
		<a href="#" class="remove-link" rel="remove-btn"><?php _e( 'X', 'qsot-ga-multi-price' ) ?></a>
		<span rel="tt_display"></span>
		<input type="hidden" name="ticket-type[]" value="" rel="ticket-type" />
		<input type="number" step="1" min="0" max="<?php echo esc_attr( $max ) ?>" rel="qty" name="quantity[]" value="1" class="very-short" />
	</div>
<?php endif; ?>
