<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );
$show_available_qty = apply_filters( 'qsot-get-option-value', true, 'qsot-show-available-quantity' );
?>
<div class="qsfix"></div>
<div class="qsot-event-area-ticket-selection">
	<?php do_action( 'qsot-before-ticket-selection-form', $event, $area, $reserved, $total_reserved ); ?>
	<?php list( $stp, $nstp ) = ! empty( $total_reserved ) ? array( 'two', 2 ) : array( 'one', 1 ); ?>

	<?php if ( ! apply_filters( 'qsot-can-sell-tickets-to-event', false, $event->ID ) ): ?>

		<div class="event-area-image"><?php do_action('qsot-draw-event-area-image', $event, $area, $reserved) ?></div>
		<p><?php _e( 'We are sorry. No more tickets can be sold for this event.', 'qsot-seating' ) ?></p>

	<?php elseif ( isset( $area->prices ) && is_array( $area->prices ) && count( $area->prices ) && ! $area->is_soldout ): ?>

		<div class="qsot-ticket-selection show-if-js"></div>
		<div class="remove-if-js no-js-message">
			<p>
				<?php _e('For a better experience, certain features of javascript area required. Currently you either do not have these features, or you do not have them enabled. Despite this, you can still purchase your tickets, using 3 simple steps.','qsot-seating') ?>
			</p>
			<p>
				<?php echo sprintf( '<strong class="step-name">%s:</strong> %s <span class="button-name">%s</span>', __( 'STEP 1', 'qsot-seating' ), __( 'Select the seat you wish to purchase tickets for, then click', 'qsot-seating' ), apply_filters( 'qsot-get-option-value', __( 'Reserve', 'opentickets-community-edition' ), 'qsot-reserve-button-text' ) ) ?>
			</p>
			<p>
				<?php echo sprintf( '<strong class="step-name">%s:</strong> %s <span class="button-name">%s</span>', __( 'STEP 2', 'qsot-seating' ), __( 'Below, select the type of ticket you wish to purchase, enter the number of tickets you wish to purchase, then click', 'qsot-seating' ), apply_filters( 'qsot-get-option-value', __( 'Reserve', 'opentickets-community-edition' ), 'qsot-reserve-button-text' ) ) ?>
			</p>
			<p>
				<?php echo sprintf( '<strong class="step-name">%s:</strong> %s <span class="button-name">%s</span> %s', __( 'STEP 3', 'qsot-seating' ), __( 'Finally, once you have successfully Reserved your Tickets, click', 'qsot-seating' ), apply_filters( 'qsot-get-option-value', __( 'Proceed to Cart', 'opentickets-community-edition' ), 'qsot-proceed-button-text' ), __( 'to complete your order.', 'qsot-seating' ) ) ?>
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
			<div class="step-one ticket-selection-section"><div class="form-inner">
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
					<?php if ( ! empty( $interests ) && isset( $area->prices, $area->struct->prices[0] ) ): ?>
						<div class="sub-section">
							<h4><?php _e( 'We need your attention on:', 'qsot-seating' ) ?></h4>

							<form id="attention" class="submittable" action="<?php echo esc_attr( remove_query_arg( array( 'rmvd' ) ) ) ?>" method="post">
								<?php foreach ( $interests as $zone_id => $tt2qty ): ?>
									<?php
										if ( ! ( $zone = $edata['zones'][ $zone_id . '' ] ) || ! is_object( $zone ) )
											continue;

										$zone_name = apply_filters( 'the_title', isset( $zone->name ) && strlen( trim( $zone->name ) ) > 0 ? $zone->name : $zone->abbr );
										$multiple = ( $zone->capacity > 1 && isset( $edata['stati'], $edata['stati'][ $zone_id ] ) && $edata['stati'][ $zone_id ][1] > 1 );
										$prices = isset( $area->prices[ $zone_id . '' ] ) ? $area->prices[ $zone_id . '' ] : $area->prices[0];
									?>
									<?php foreach ( $tt2qty as $tt_id => $qty ): ?>
										<div class="you-have">
											<a href="<?php echo esc_attr( add_query_arg( array(
												'remove_reservations_seated' => $zone_id,
												'ttid' => 0,
												'submission' => wp_create_nonce( 'ticket-selection-remove-0-' . $zone_id )
											) ) ) ?>" class="remove-link">X</a>

											<label for="ticket-count">
												<?php if ( ! $multiple ): ?>
													<input type="hidden" name="ticket-count[<?php echo esc_attr( $zone_id ) ?>]" value="1" />
													<span class="ticket-count">1</span> <?php echo __( 'x', 'qsot-seating' ) ?>
												<?php endif; ?>
												<span class="zone-name"><?php echo $zone_name ?></span>:
											</label>

											<input type="hidden" value="<?php echo esc_attr( $zone_id ) ?>" name="seat[<?php echo esc_attr( $zone_id ) ?>]" />
											<select name="ticket_type[<?php echo esc_attr( $zone_id ) ?>]">
												<?php foreach ( $prices as $price ): ?>
													<option value="<?php echo esc_attr( $price['product_id'] ) ?>"><?php echo apply_filters( 'the_title', $price['product_name'] ) ?></option>
												<?php endforeach; ?>
											</select>

											<?php if ( $multiple ): ?>
												<input type="number" min="0" max="<?php echo $area->meta['available'] ?>" step="1" class="very-short"
														name="ticket-count[<?php echo esc_attr( $zone_id ) ?>]" value="<?php echo esc_attr( $qty ) ?>" />
											<?php endif; ?>
										</div>
									<?php endforeach; ?>
								<?php endforeach; ?>

								<?php do_action( 'qsot-event-area-ticket-selection-no-js-step-one', $event, $area, $reserved ); ?>

								<div class="qsot-form-actions">
									<?php wp_nonce_field( 'ticket-selection-step-two', 'submission' ) ?>
									<input type="hidden" name="qsot-step" value="2" />
									<input type="submit" value="<?php echo esc_attr( apply_filters( 'qsot-get-option-value', __( 'Reserve', 'opentickets-community-edition' ), 'qsot-reserve-button-text' ) ) ?>" class="button" />
								</div>
							</form>
						</div>

					<?php endif; ?>

					<?php if ( ! empty( $reserved ) && isset( $area->prices ) ): ?>

						<div class="sub-section">
							<h4><?php _e( 'Your current reservations are:', 'qsot-seating' ) ?></h4>

							<?php foreach ( $reserved as $zone_id => $tt2qty ): ?>
								<?php
									if ( ! ( $zone = $edata['zones'][ $zone_id . '' ] ) || ! is_object( $zone ) )
										continue;

									$zone_name = apply_filters( 'the_title', isset( $zone->name ) && strlen( trim( $zone->name ) ) > 0 ? $zone->name : $zone->abbr );
									$prices = isset( $area->prices[ $zone_id . '' ] ) ? $area->prices[ $zone_id . '' ] : $area->prices[0];
								?>
								<?php foreach ( $tt2qty as $tt_id => $qty ): ?>
									<?php
										$q = isset( $qty['reserved'] ) ? $qty['reserved'] : ( isset( $qty['interest'] ) ? $qty['interest'] : 0 );
										$multiple = ( $zone->capacity > 1 && isset( $edata['stati'], $edata['stati'][ $zone_id ] ) && $edata['stati'][ $zone_id ][1] + $q > 1 );
										$price = wp_parse_args( isset( $edata['ticket_types'][ $tt_id ] ) ? $edata['ticket_types'][ $tt_id ] : array(), array(
											'product_name' => 'ticket',
											'product_raw_price' => '',
										) );
									?>
									<div class="you-have">
										<a href="<?php echo esc_attr( add_query_arg( array(
											'remove_reservations_seated' => $zone_id,
											'ttid' => $tt_id,
											'submission' => wp_create_nonce( 'ticket-selection-remove-' . $tt_id . '-' . $zone_id )
										) ) ) ?>" class="remove-link">X</a>

										<label for="ticket-count">
											<span class="ticket-count"><?php echo apply_filters( 'the_title', $q ) ?></span> <?php echo __( 'x', 'qsot-seating' ) ?>
											<span class="zone-name"><?php echo $zone_name ?></span>:
											<span class="ticket-name"><?php echo apply_filters( 'the_title', $price['product_name'] ) ?></span>
										</label>

									</div>
								<?php endforeach; ?>
							<?php endforeach; ?>

							<?php do_action( 'qsot-event-area-ticket-selection-no-js-step-one', $event, $area, $reserved ); ?>
						</div>
						
					<?php endif; ?>

					<h4><?php _e( 'Select a seat:', 'opentickets-community-edition' ) ?></h4>
					<form id="select-seat" class="submittable" action="<?php echo esc_attr( remove_query_arg( array( 'rmvd' ) ) ) ?>" method="post">
						<select name="seat">
							<?php foreach ( $edata['zones'] as $zone ): ?>
								<?php
									$stats = isset( $edata['stati'], $edata['stati'][ $zone->id . '' ] ) ? $edata['stati'][ $zone->id . '' ] : array( 0, 0 );
									// skip empty zones
									if ( $stats[1] <= 0 )
										continue;

									// figure out the number to show is remaining
									$remaining = ( 'yes' == $show_available_qty ) ? $stats[1] : __( 'some', 'qsot-seating' );
									// normalize the name
									$name = sprintf(
										'%s [%s available]',
										isset( $zone->name ) && strlen( trim( $zone->name ) ) ? $zone->name : $zone->abbr,
										$remaining
									);
								?>
								<option value="<?php echo esc_attr( $zone->id ) ?>"><?php echo apply_filters( 'the_title', $name ) ?></option>
							<?php endforeach; ?>
						</select>

						<?php do_action('qsot-event-area-ticket-selection-no-js-step-one', $event, $area, $reserved); ?>

						<div class="qsot-form-actions">
							<?php wp_nonce_field('ticket-selection-step-one', 'submission') ?>
							<input type="hidden" name="qsot-step" value="1" />
							<input type="submit" value="<?php echo esc_attr( apply_filters( 'qsot-get-option-value', __( 'Reserve', 'opentickets-community-edition' ), 'qsot-reserve-button-text' ) ) ?>" class="button" />
						</div>
					</form>
				</div>
			</div></div>

			<?php if ( is_array( $reserved ) && $reserved ): ?>
				<div class="actions" rel="actions" style="display:block;">
					<a href="<?php echo esc_attr( WC()->cart->get_cart_url() ) ?>" class="button" rel="cart-btn"><?php echo apply_filters( 'qsot-get-option-value', __( 'Proceed to Cart', 'opentickets-community-edition' ), 'qsot-proceed-button-text' ) ?></a>
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
