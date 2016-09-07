<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') ); ?>
<div class="ticket-form ticket-selection-section">
	<div class="form-inner">
		<div class="title-wrap">
			<div class="form-title" rel="title"></div>
		</div>
		<div rel="owns"></div>
		<div class="field">
			<label class="section-heading"><?php _e( 'Reserve some tickets:', 'qsot-ga-multi-price' ) ?></label>
			<div class="availability-message helper"></div>
			<span rel="tt_edit"></span>
			<input type="number" step="1" min="0" max="<?php echo esc_attr( $max ) ?>" rel="qty" name="quantity" value="1" class="very-short" />
			<input type="button" value="<?php echo esc_attr( apply_filters( 'qsot-get-option-value', __( 'Reserve', 'opentickets-community-edition' ), 'qsot-reserve-button-text' ) ) ?>" rel="reserve-btn" class="button" />
		</div>
	</div>
	<div class="actions" rel="actions">
		<a href="<?php echo esc_attr( $cart_url ) ?>" class="button" rel="cart-btn"><?php echo apply_filters( 'qsot-get-option-value', __( 'Proceed to Cart', 'opentickets-community-edition' ), 'qsot-proceed-button-text' ) ?></a>
	</div>
</div>
