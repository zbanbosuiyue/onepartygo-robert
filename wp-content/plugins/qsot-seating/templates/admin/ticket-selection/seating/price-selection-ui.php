<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') ); ?>
<div class="price-selection-ui">
	<div class="price-selection-error" rel="error">
		<div class="msg" rel="msg"></div>
		<div class="error-accept">
			<a href="#" rel="close"><?php _e( 'OK', 'qsot-seating' ) ?></a>
		</div>
	</div>
	<div class="price-selection-box" rel="box">
		<div class="title-bar">
			<h4 class="price-selection-title">
				<?php _e( 'Select a price:', 'qsot-seating' ) ?>
				<div class="close" rel="close">X</div>
			</h4>
		</div>
		<div class="price-ui-content">
			<div class="for-ui field" rel="for-iu">
				<span class="label"><?php _e( 'For:', 'qsot-seating' ) ?></span>
				<span class="value selection-list" rel="sel-list"></span>
			</div>
			<div class="quantity-ui field" rel="qty-ui">
				<div class="label"><?php _e( 'How many?', 'qsot-seating' ) ?></div>
				<div class="value"><input type="number" min="0" step="1" rel="quantity" value="1" /></div>
			</div>
			<div class="available-prices field" rel="price-list-wrap">
				<div class="label"><?php _e( 'Select Pricing Option:', 'qsot-seating' ) ?></div>
				<ul rel="price-list"></ul>
			</div>
		</div>
	</div>
	<div class="price-selection-backdrop" rel="backdrop"></div>
</div>
