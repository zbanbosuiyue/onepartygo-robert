<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') ); ?>
<li class="price-struct" role="item" data-struct-id="{{struct_id}}">
	<span class="struct-name" role="name">{{struct_name}}</span>
	<span class="struct-count" role="price-count">{{struct_price_cnt}}</span>
	<a href="#" class="icon edit-struct" role="edit-btn"><?php _e( 'Edit', 'qsot-ga-multi-price' ) ?></a>
	<a href="#" class="icon remove-struct" role="remove-btn"><?php _e( 'Remove', 'qsot-ga-multi-price' ) ?></a>
	<input type="hidden" name="gamp-struct-settings[{{struct_id}}]" value="{{struct_json}}" role="struct-settings" />
</li>
