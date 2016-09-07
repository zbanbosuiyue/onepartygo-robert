<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );
$show_available_qty = apply_filters( 'qsot-get-option-value', true, 'qsot-show-available-quantity' );
?>
<div class="qsfix"></div>
<div class="qsot-event-area-ticket-selection">
	<?php do_action( 'qsot-before-ticket-selection-form', $event, $area, $reserved, $total_reserved ); ?>
	<?php list( $stp, $nstp ) = ! empty( $total_reserved ) ? array( 'two', 2 ) : array( 'one', 1 ); ?>

	<?php if ( isset( $area->prices ) && is_array( $area->prices ) && count( $area->prices ) && ! $area->is_soldout ): // multiprice ?>
		<div class="qsot-ticket-selection show-if-js"></div>
		<div class="remove-if-js no-js-message">
			<p>
				<?php _e( 'For a better experience, certain features of javascript area required. Currently you either do not have these features, or you do not have them enabled. Despite this, you can still purchase your tickets, using 2 simple steps.', 'qsot-ga-multi-price' ) ?>
			</p>
			<p>
				<?php _e( '<strong>STEP 1:</strong> Below, select the type of ticket you wish to purchase, enter the number of tickets you wish to purchase, then click <span class="button-name">Reserve Tickets</span>.', 'qsot-ga-multi-price' ) ?>
			</p>
			<p>
				<?php _e( '<strong>STEP 2:</strong> Finally, once you have successfully Reserved your Tickets, click <span class="button-name">Proceed to Cart</span> to complete your order.', 'qsot-ga-multi-price' ) ?>
			</p>
		</div>

		<?php do_action( 'qsot-after-ticket-selection-no-js-message', $event, $area, $reserved, $total_reserved ); ?>

		<div class="event-area-image"><?php do_action( 'qsot-draw-event-area-image', $event, $area, $reserved, $total_reserved ) ?></div>

		<?php if ( ( $errors = apply_filters( 'qsot-zoner-non-js-error-messages', array() ) ) && count( $errors ) ): ?>
			<div class="messages">
				<?php foreach ( $errors as $e ): ?>
					<div class="error"><?php echo $e ?></div>
				<?php endforeach; ?>
			</div>
			<div class="qs-shim"></div>
		<?php endif; ?>

		<?php if ( isset( $_GET['rmvd'] ) ): ?>
			<div class="messages">
				<div class="msg"><?php _e( 'Successfully removed your reservations.', 'opentickets-community-edition' ) ?></div>
			</div>
			<div class="qs-shim"></div>
		<?php endif; ?>

		<div class="event-area-ticket-selection-form empty-if-js woocommerce" rel="ticket-selection">
			<div class="step-one ticket-selection-section">
				<form class="submittable" action="<?php echo esc_attr( remove_query_arg( array( 'rmvd' ) ) ) ?>" method="post">
					<div class="title-wrap">
						<?php if ( 2 === $nstp ): ?>
							<?php if ( 1 == count( $area->prices ) ): ?>
								<h3><?php printf( __( '%sSTEP 2%s: Adjust or Review your tickets:', 'qsot-ga-multi-price' ), '<span class="step-name">', '</span>' ) ?></h3>
							<?php else: ?>
								<h3><?php printf( __( '%sSTEP 2%s: Adjust or Review:', 'qsot-ga-multi-price' ), '<span class="step-name">', '</span>' ) ?></h3>
							<?php endif; ?>
						<?php else: ?>
							<?php if ( 1 == count( $area->prices ) ): ?>
								<h3><?php printf( __( '%sSTEP 1%s: How many?', 'qsot-ga-multi-price' ), '<span class="step-name">', '</span>' ) ?></h3>
							<?php else: ?>
								<h3><?php printf( __( '%sSTEP 1%s: Select the price and quantity:', 'qsot-ga-multi-price' ), '<span class="step-name">', '</span>' ) ?></h3>
							<?php endif; ?>
						<?php endif; ?>
					</div>

					<div class="field">
						<div class="availability-message helper">
							<?php if ( 'yes' == $show_available_qty ): ?>
								<?php printf(
									__( 'Currently, there are %s%s%s tickets available for purchase.', 'qsot-ga-multi-price' ),
									'<span class="available">',
									$event->meta->available,
									'</span>'
								) ?>
							<?php else: ?>
								<?php __( 'Currently, there are tickets available for purchase.', 'qsot-ga-multi-price' ) ?>
							<?php endif; ?>
						</div>

						<?php if ( ! empty( $total_reserved ) && isset( $area->prices ) ): ?>
							<label class="section-heading"><?php _e( 'Your current reservations are:', 'qsot-seating' ) ?></label>

							<?php foreach ( $area->prices as $price ): ?>
								<?php if ( ! isset( $reserved[ $price->product_id ] ) ) continue; /* if there are no current reservations of this type, skip it */ ?>
								<div class="you-have">
									<a href="<?php echo esc_attr( add_query_arg( array(
										'remove_reservations_multi' => 1,
										'ttid' => $price->product_id,
										'submission' => wp_create_nonce( 'ticket-selection-step-two-' . $price->product_id )
									) ) ) ?>" class="remove-link">X</a>

									<input type="number" min="0" max="<?php echo $event->meta->available ?>" step="1" class="very-short"
											name="gamp-ticket-count[<?php echo esc_attr( $price->product_id ) ?>]" value="<?php echo esc_attr( $reserved[ $price->product_id ] ) ?>" />
									<label for="ticket-count">
										"<span class="ticket-name"><?php echo $price->product_name ?></span>"
										(<span class="ticket-price"><?php echo $price->product_display_price ?></span>).
									</label>
								</div>
							<?php endforeach; ?>
							
							<input type="button" value="<?php echo esc_attr( apply_filters( 'qsot-get-option-value', __( 'Update', 'opentickets-community-edition' ), 'qsot-update-button-text' ) ) ?>" rel="update-btn" class="button" />
						<?php endif; ?>

						<label class="section-heading"><?php echo __( 'Reserve some tickets:', 'opentickets-community-edition' ) ?></label>
						<?php if ( count( $area->prices ) > 1 ): ?>
							<select name="ticket-type">
								<?php foreach ( $area->prices as $price ): ?>
									<option value="<?php echo esc_attr( $price->product_id ) ?>"><?php echo $price->product_name . ' (' . $price->product_raw_price . ')' ?></option>
								<?php endforeach; ?>
							</select>
							<input type="number" min="0" max="<?php echo $event->meta->available ?>" step="1" class="very-short" name="ticket-count" value="1" />
						<?php elseif ( 1 == count( $area->prices ) ): ?>
							<label for="ticket-count">
								<span class="ticket-name"><?php echo $area->prices[0]->product_name ?></span>
								(<span class="ticket-price"><?php echo $area->prices[0]->product_raw_price ?></span>)
							</label>
							<input type="hidden" name="ticket-type" value="<?php echo esc_attr( $area->prices[0]->product_id ) ?>" />
							<input type="number" min="0" max="<?php echo $event->meta->available ?>" step="1" class="very-short" name="ticket-count" value="1" />
						<?php endif; ?>
					</div>

					<?php do_action( 'qsot-event-area-ticket-selection-no-js-step-' . $stp, $event, $area, $reserved, $total_reserved ); ?>

					<div class="qsot-form-actions">
						<?php wp_nonce_field( 'ticket-selection-step-' . $stp, 'submission' ) ?>
						<input type="hidden" name="qsot-step" value="<?php echo $nstp ?>" />
						<input type="submit" value="<?php echo esc_attr( apply_filters( 'qsot-get-option-value', __( 'Reserve', 'opentickets-community-edition' ), 'qsot-reserve-button-text' ) ) ?>" class="button" />
					</div>
				</form>
			</div>
			<?php if ( $total_reserved > 0 ): ?>
				<div class="actions" rel="actions">
					<a href="<?php echo esc_attr( $cart_url ) ?>" class="button" rel="cart-btn"><?php echo apply_filters( 'qsot-get-option-value', __( 'Proceed to Cart', 'opentickets-community-edition' ), 'qsot-proceed-button-text' ) ?></a>
				</div>
			<?php endif; ?>
		</div>
	<?php elseif ( $area->is_soldout ): ?>
		<div class="event-area-image"><?php do_action( 'qsot-draw-event-area-image', $event, $area, $reserved, $total_reserved ) ?></div>
		<p><?php _e( 'We are sorry. This event is sold out!', 'qsot-ga-multi-price' ) ?></p>
	<?php else: ?>
		<div class="event-area-image"><?php do_action( 'qsot-draw-event-area-image', $event, $area, $reserved, $total_reserved ) ?></div>
		<p><?php _e( 'We are sorry. There are currently no tickets available for this event. Check back soon!', 'qsot-ga-multi-price' ) ?></p>
	<?php endif; ?>
	
	<?php do_action( 'qsot-after-ticket-selection-form', $event, $area, $reserved, $total_reserved ); ?>

	<script>
		if (typeof jQuery == 'function') (function($) {
			$('.remove-if-js').remove();
			$('.empty-if-js').empty();
			$('.hide-if-js').hide();
			$('.show-if-js').show();
		})(jQuery);
	</script>
</div>
