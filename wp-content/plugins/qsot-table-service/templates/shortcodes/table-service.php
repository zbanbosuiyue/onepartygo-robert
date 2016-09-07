<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );
$ts_product = wc_get_product( $table_service_product->id );
?>

<?php wc_print_notices(); ?>

<div class="qsotts-product-rows">
	<?php foreach ( $products as $post ): $GLOBALS['product'] = $product = wc_get_product( $post->ID ); ?>
		<div class="qsotts-product-row">
			<?php QSOT_Templates::include_template( 'shortcodes/parts/product-image.php', array() ); ?>
			<div class="qsotts-product-data">
				<h2 class="qsotts-product-name"><a href="<?php echo esc_url( get_permalink( $product->id ) ) ?>" title="View more details about <?php echo esc_attr( $product->get_title() ) ?>"><?php echo $product->get_title() ?></a></h2>
				<div class="qsotts-product-description"><?php
					$full = strip_tags( $post->post_content );
					$desc = substr( $full, 0, 200 ) . ( strlen( $full ) > 200 ? '...' . '<a href="' . esc_url( get_permalink( $post->ID ) ) . '">' . __( 'more', 'qsot-table-service' ) . '</a>' : '' );
					echo $desc;
				?></div>
				<div class="qsotts-price"><label><?php _e( 'Price', 'qsot-table-service' ) ?>:</label><?php woocommerce_template_single_price() ?></div>
				<div class="qsotts-cart"><?php woocommerce_template_single_add_to_cart() ?></div>
			</div>
		</div>
	<?php endforeach; ?>
</div>
