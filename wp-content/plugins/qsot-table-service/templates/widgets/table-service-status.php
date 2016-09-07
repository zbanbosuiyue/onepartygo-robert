<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );
extract( $widget_args );
extract( $instance );

$ts_product = wc_get_product( $table_service_product->id );
?>

<?php echo $before_widget ?>
<div class="qsotts-table-service-status <?php echo esc_attr( $class ) ?>">
	<?php echo $before_title ?><?php echo str_replace(
		array(
			'%product_title%'
		),
		array(
			$ts_product->get_title(),
		),
		$title
	) ?><?php echo $after_title ?>

	<div class="qsotts-fields">
		<div class="qsotts-field">
			<label><?php _e( 'Table:', 'qsot-table-service' ) ?></label>
			<span class="qsotts-value"><?php echo $ts_product->get_title() ?></span>
		</div>
		<div class="qsotts-field">
			<label><?php _e( 'Min Spend:', 'qsot-table-service' ) ?></label>
			<span class="qsotts-value"><?php echo $min_spend ?></span>
		</div>
		<div class="qsotts-field">
			<label><?php _e( 'Remaining:', 'qsot-table-service' ) ?></label>
			<span class="qsotts-value"><?php echo $remaining ?></span>
		</div>
	</div>
</div>
<?php echo $after_widget ?>
