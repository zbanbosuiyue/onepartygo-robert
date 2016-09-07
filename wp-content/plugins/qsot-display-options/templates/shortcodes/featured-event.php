<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );
global $post;

$show_availability = 'yes' == apply_filters( 'qsot-get-option-value', 'yes', 'qsot-do-show-availability' );
$show_avail_count = 'yes' == apply_filters( 'qsot-get-option-value', 'yes', 'qsot-show-available-quantity' );
?>

<?php do_action( 'qsot-before-featured-event', $format ) ?>

<ul class="event-list featured">

	<?php if ( have_posts() ): while ( have_posts() ): the_post(); $product = wc_get_product( $post->ID ); ?>

		<?php do_action( 'qsot-before-featured-event-item', get_the_ID(), $format ) ?>

		<li <?php post_class( 'list-item event-item' ) ?>>

			<?php do_action( 'qsot-before-featured-event-item-top', get_the_ID(), $format ) ?>

			<?php if ( $show_image && has_post_thumbnail() ): ?>
				<div class="event-thumb"><a href="<?php echo esc_attr( esc_url( get_permalink() ) ) ?>" rel="bookmark"><?php the_post_thumbnail( 'medium' ) ?></a></div>
			<?php endif; ?>

			<h3 class="event-title"><a href="<?php echo esc_attr( esc_url( get_permalink() ) ) ?>" rel="bookmark"><?php echo get_the_title( $post->post_parent ? $post->post_parent : $post->ID ) ?></a></h3>

			<?php if ( ! empty( $show_meta ) ): ?>

				<div class="event-meta">
					<?php foreach ( $show_meta as $field ): ?>
						<?php
							switch ( $field ) {
								case 'date':
									?><div class="item-date"><?php
										// get the raw start and end date/time of the event
										$start = QSOT_Utils::local_timestamp( $product->start );
										$end = QSOT_Utils::local_timestamp( $product->end );
										$same_day = strtotime( 'today', $start ) == strtotime( 'today', $end );

										// format the start and end time
										$start = date( $date_format, $start );
										$end = date( $same_day ? $time_format : $date_format, $end );

										echo $start . ' ' . __( 'to', 'qsot-display-options' ) . ' ' . $end;
									?></div><?php
								break;

								case 'price':
									?><div class="item-price"><?php echo $product->get_price_html() ?></div><?php
								break;

								case 'availability':
									if ( $show_availability ) {
										$available = apply_filters( 'qsot-get-availability', 0, $post->ID );
										$words = __( 'Available', 'qsot-display-options' );
										$text = $show_avail_count ? sprintf( '%s (%s)', apply_filters( 'qsot-get-availability-text', $words, $post->ID ), $available ) : $words;
										?><div class="item-availability"><?php echo $text ?></div><?php
									}
								break;
							}
						?>
					<?php endforeach; ?>
				</div>

			<?php endif; ?>

			<?php if ( $show_desc ): ?>
				<div class="event-description"><?php echo force_balance_tags( get_the_excerpt() ) ?></div>
			<?php endif;?>

			<?php do_action( 'qsot-before-featured-event-item-bottom', get_the_ID(), $format ) ?>
			
			<div class="clear"></div>
		</li>

		<?php do_action( 'qsot-after-featured-event-item', get_the_ID(), $format ) ?>

	<?php endwhile; else: ?>
		<li class="<?php post_class( 'no-posts' ) ?>">
			<p><?php _e( 'Sorry. No events to display.', 'qsot-display-options' ) ?></p>
		</li>
	<?php endif; ?>

</ul>

<?php do_action( 'qsot-after-featured-event', $format ) ?>
