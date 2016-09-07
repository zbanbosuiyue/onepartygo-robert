<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') ); ?>
<div class="field">
	<label class="section-heading"><?php _e( 'Reserve some tickets:', 'qsot-seating' ) ?></label>
	<div class="availability-message helper"></div>
	<span rel="tt_edit"></span>
	<input type="number" step="1" min="0" max="<?php echo intval( $max ) ?>" rel="qty" name="quantity" value="1" class="very-short" />
	<input type="button" value="<?php echo esc_attr( apply_filters( 'qsot-get-option-value', __( 'Reserve', 'opentickets-community-edition' ), 'qsot-reserve-button-text' ) ) ?>" rel="reserve-btn" class="button reserve-btn" />
</div>
