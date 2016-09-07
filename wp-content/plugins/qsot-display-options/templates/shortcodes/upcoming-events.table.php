<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );
global $post;

$show_availability = 'yes' == apply_filters( 'qsot-get-option-value', 'yes', 'qsot-do-show-availability' );
$show_avail_count = 'yes' == apply_filters( 'qsot-get-option-value', 'yes', 'qsot-show-available-quantity' );
?>

<?php do_action( 'qsot-before-upcoming-events', $format ) ?>

<table class="event-table">

	<thead>
		<tr>
			<?php foreach ( $show_columns as $column ): ?>
				<?php
					switch ( $column ) {
						case 'date': ?><th><?php _e( 'Date', 'qsot-display-options' ) ?></th><?php break;
						case 'title': ?><th><?php _e( 'Event', 'qsot-display-options' ) ?></th><?php break;
						case 'price': ?><th><?php _e( 'Price', 'qsot-display-options' ) ?></th><?php break;
						case 'Availability': if ( $show_availability ) { ?><th><?php _e( 'Availability', 'qsot-display-options' ) ?></th><?php } break;
					}
				?>
			<?php endforeach; ?>
		</tr>
	</thead>

	<tbody>

		<?php if ( have_posts() ): while ( have_posts() ): the_post(); $product = wc_get_product( $post->ID ); ?>

			<?php do_action( 'qsot-before-upcoming-events-item', get_the_ID(), $format ) ?>

			<tr <?php post_class( 'table-item event-item' ) ?>>

				<?php do_action( 'qsot-before-upcoming-events-item-top', get_the_ID(), $format ) ?>
				
				<?php foreach ( $show_columns as $column ): ?>
					<?php
						switch ( $column ) {
							case 'date':
								?><td class="event-date"><?php
									// get the raw start and end date/time of the event
									$start = QSOT_Utils::local_timestamp( $product->start );
									$end = QSOT_Utils::local_timestamp( $product->end );
									$same_day = strtotime( 'today', $start ) == strtotime( 'today', $end );

									// format the start and end time
									$start = date( $date_format, $start );
									$end = date( $same_day ? $time_format : $date_format, $end );

									echo $start . ' ' . __( 'to', 'qsot-display-options' ) . ' ' . $end;
								?></td><?php
							break;

							case 'title':
								?><td class="event-name">
									<?php if ( $show_image && has_post_thumbnail() ): ?>
										<a href="<?php echo esc_attr( esc_url( get_permalink() ) ) ?>" rel="bookmark"><div class="event-thumb"><?php the_post_thumbnail( array( 50, 50 ) ) ?></div></a>
									<?php endif; ?>
									
									<h3 class="event-title"><a href="<?php echo esc_attr( esc_url( get_permalink() ) ) ?>" rel="bookmark"><?php echo get_the_title( $post->post_parent ? $post->post_parent : $post->ID ) ?></a></h3>
								</td><?php
							break;

							case 'price':
								?><td class="event-price"><?php echo $product->get_price_html() ?></td><?php
							break;

							case 'availability':
								if ( $show_availability ) {
									$available = apply_filters( 'qsot-get-availability', 0, $post->ID );
									$words = __( 'Available', 'qsot-display-options' );
									$text = $show_avail_count ? sprintf( '%s (%s)', apply_filters( 'qsot-get-availability-text', $words, $post->ID ), $available ) : $words;
									?><td class="event-availability"><?php $text ?></td><?php
								}
							break;
						}
					?>
				<?php endforeach; ?>

				<?php do_action( 'qsot-before-upcoming-events-item-bottom', get_the_ID(), $format ) ?>
				
				<div class="clear"></div>
			</tr>

			<?php do_action( 'qsot-after-upcoming-events-item', get_the_ID(), $format ) ?>

		<?php endwhile; else: ?>
			<tr class="<?php post_class( 'no-posts' ) ?>">
				<td colspan="<?php echo $show_availability ? 4 : 3 ?>"><?php _e( 'Sorry. No events to display.', 'qsot-display-options' ) ?></td>
			</tr>
		<?php endif; ?>
	
	</tbody>

</table>

<?php do_action( 'qsot-after-upcoming-events', $format ) ?>
