<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') ); ?>
<div class="owns-wrap field" rel="owns-wrap">
	<label class="section-heading"><?php _e( 'Your current reservations are:', 'qsot-seating' ) ?></label>
	<div class="owns-list" rel="owns-list"></div>
	<input type="button" value="<?php echo esc_attr( apply_filters( 'qsot-get-option-value', __( 'Update', 'opentickets-community-edition' ), 'qsot-update-button-text' ) ) ?>" rel="update-btn" class="button update-btn" />
</div>
