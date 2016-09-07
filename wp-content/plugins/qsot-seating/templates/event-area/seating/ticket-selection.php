<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') ); ?>
<div class="ticket-form ticket-selection-section woocommerce">
	<div class="form-inner">
		<div class="title-wrap">
			<div class="form-title" rel="title"></div>
		</div>
		<div rel="owns"></div>
		<div class="selection-nosvg" rel="nosvg"></div>
	</div>
	<div class="actions" rel="actions">
		<a href="<?php echo esc_attr( $cart_url ) ?>" class="button" rel="cart-btn"><?php echo esc_attr( apply_filters( 'qsot-get-option-value', __( 'Proceed to Cart', 'opentickets-community-edition' ), 'qsot-proceed-button-text' ) ) ?></a>
	</div>
</div>
