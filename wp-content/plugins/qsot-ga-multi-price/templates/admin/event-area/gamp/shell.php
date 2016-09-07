<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') ); ?>
<div class="two-columns">
	<div class="column left"><div class="inner-wrap"><div class="inner">
		<label class="colhead"><?php _e( 'Price Structures', 'qsot-ga-multi-price' ) ?></label>
		<input type="hidden" name="qsot-gamp-n" value="1" role="save-nonce" />
		<ul class="price-structs" role="struct-list"></ul>
		<button class="button" role="add-struct-btn"><?php _e( 'Add', 'qsot-ga-multi-price' ) ?></button>
	</div></div></div>
	<div class="column right edit-struct-form" role="struct-edit"><div class="inner-wrap"><div class="inner">
		<label class="colhead"><?php _e( 'Edit Structure', 'qsot-ga-multi-price' ) ?></label>
		<div class="struct-field">
			<label for="structure-name"><?php _e( 'Structure Name', 'qsot-ga-multi-price' ) ?>:</label>
			<input type="text" role="struct-name" id="struct-name" class="widefat" />
		</div>
		<div class="struct-field two-columns no-deco">
			<div class="column left"><div class="inner-wrap"><div class="inner">
				<label class="colhead"><?php _e( 'Use these Prices', 'qsot-ga-multi-price' ) ?></label>
				<ul class="use-prices price-list" role="used-list"></ul>
			</div></div></div>
			<div class="column right"><div class="inner-wrap"><div class="inner">
				<label class="colhead"><?php _e( 'Do not use these Prices', 'qsot-ga-multi-price' ) ?></label>
				<ul class="available-prices price-list" role="available-list"></ul>
			</div></div></div>
			<div class="clear"></div>
		</div>
		<button class="done-btn button" role="end-edit-btn"><?php _e( 'Done Editing', 'qsot-ga-multi-price' ) ?></button>
	</div></div></div>
	<div class="clear"></div>
</div>
