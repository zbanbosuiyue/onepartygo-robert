<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') ); ?>
<div class="add-tickets-ui" rel="add-ui">
	<div class="ticket-form ts-section">
		<div class="owned-block section" rel="owned">
			<h4 rel="title"><?php _e( 'Currently Owned', 'qsot-ga-multi-price' ) ?></h4>
			<div class="owned-list" rel="owned-list"></div>
		</div>

		<div class="select-block section" rel="select">
			<h4 rel="title"><?php _e( 'Select Tickets', 'qsot-ga-multi-price' ) ?></h4>
			<div class="fields" rel="fields">
				<select name="ttid" rel="ticket-type-list"></select>
				<input type="number" name="qty" min="1" max="1000000" step="1" class="quantity" rel="ticket-count"/>
				<input type="button" class="button" rel="add-btn" value="<?php echo esc_attr( __( 'Add Tickets', 'opentickets-community-edition' ) ) ?>" />
			</div>
		</div>
	</div>
	<div class="image-wrap" rel="image-wrap"></div>
</div>
