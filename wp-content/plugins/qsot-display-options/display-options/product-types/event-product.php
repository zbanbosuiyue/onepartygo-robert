<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// special product type that encompases an event in a product shell.
// basically a copy from wc_simple_product, with modifications for using an event internally
class QSOT_Event_Product extends WC_Product {
	// create the object, based on the event post
	public function __construct( $event ) {
		$this->product_type = 'qsot-event-product';
		parent::__construct( $event );

		// load the ea info, and OTCE pricing options data
		$this->event_area_id = get_post_meta( $this->id, '_event_area_id', true );
		$this->event_area = apply_filters( 'qsot-get-event-area', false, $this->event_area_id );
		$this->area_type = isset( $this->event_area->area_type ) && is_object( $this->event_area->area_type ) && ! is_wp_error( $this->event_area->area_type ) ? $this->event_area->area_type : false;
		$this->pricing_options = get_post_meta( $this->event_area_id, '_pricing_options', true );

		// if there is an OTCE prcing option, then use that to derive the proper pricing for this faux product
		if ( $this->pricing_options ) {
			$this->regular_price = get_post_meta( $this->pricing_options, '_regular_price', true );
			$this->sale_price = get_post_meta( $this->pricing_options, '_sale_price', true );
			$this->price = get_post_meta( $this->pricing_options, '_price', true );
		}
	}

	// find the url to use for the add to cart link
	public function add_to_cart_url() {
		$url = $this->is_purchasable() && $this->is_in_stock() && $this->is_single_priced() ? remove_query_arg( 'added-to-cart', add_query_arg( 'add-to-cart', $this->id ) ) : get_permalink( $this->id );

		return apply_filters( 'woocommerce_product_add_to_cart_url', $url, $this );
	}

	// find the text to use for the add to cart link
	public function add_to_cart_text() {
		$text = $this->is_single_priced() ? '' : ( $this->is_purchasable() && $this->is_in_stock() ? __( 'Select Options', 'qsot-display-options' ) : __( 'View Event', 'qsot-display-options' ) );
		$text = ! $text ? ( $this->is_purchasable() && $this->is_in_stock() ? __( 'Add to Cart', 'qsot-display-options' ) : __( 'View Event', 'qsot-display-options' ) ) : $text;

		return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
	}

	// fake visibility
	public function is_visible() { return true; }

	// fake stock
	public function is_in_stock() { return apply_filters( 'qsot-get-availability', 0, $this->id ) > 0; }

	// fake purchasable
	public function is_pubchasable() { return apply_filters( 'qsot-can-sell-tickets-to-event', false, $this->id ); }

	// figure out if this event is general admission or not
	public function is_single_priced() {
		// if the area type is not present, bail
		if ( ! is_object( $this->area_type ) )
			return true;

		return ! ( isset( $this->area_type->price_struct ) && is_object( $this->area_type->price_struct ) );
	}

	// find the title to display for this event
	public function get_title() {
		$title = $this->post->post_title;

		return apply_filters( 'woocommerce_product_title', $title, $this );
	}

	// get the displayable price. could be multiple prices
	public function get_price_html( $price = '' ) {
		// if this event is not multipriced, then use the only price available
		if ( $this->is_single_priced() )
			return parent::get_price_html( $price );

		// if the base price is not set, then there is most likely an empty price in our list of prices, so bail
		if ( '' === $this->price )
			return apply_filters( 'woocommerce_get_price_html', apply_filters( 'woocommerce_variable_empty_price_html', '', $this ), $this );

		// find all the prices
		$raw_prices = $this->area_type->get_ticket_type( array( 'event' => $this->id ) );

		// if we could not find the pricing struct, then fall back to OTCE price
		if ( ! isset( $raw_prices, $raw_prices['0'] ) )
			return parent::get_price_html( $price );

		// find the min and max, regular and sale prices, accross all prices
		$min = $max = $min_sale = $max_sale = null;
		foreach ( $raw_prices as $sub_group => $group ) {
			foreach ( $group as $raw_price ) {
				$price = $raw_price->regular_price;
				$sale_price = $raw_price->sale_price;
				if ( isset( $price ) && '' !== $price ) {
					// find the bounds
					$min = ( null !== $min ) ? min( $price, $min ) : $price;
					$max = ( null !== $max ) ? max( $price, $max ) : $price;
					if ( '' !== $sale_price && null !== $sale_price ) {
						$min_sale = ( null !== $min_sale ) ? min( $sale_price, $min_sale ) : $sale_price;
						$max_sale = ( null !== $max_sale ) ? max( $sale_price, $max_sale ) : $sale_price;
					} else {
						$min_sale = ( null !== $min_sale ) ? min( $price, $min_sale ) : $price;
						$max_sale = ( null !== $max_sale ) ? max( $price, $max_sale ) : $price;
					}
				}
			}
		}

		$sale_price = $price = '';
		// format price and sale price
		$price = ( $min !== $max ) ? sprintf( _x( '%1$s&ndash;%2$s', 'Price range: from-to', 'qsot-display-options' ), wc_price( $min ), wc_price( $max ) ) : wc_price( $min );
		// sale_price is ACTUALLY representative of the 'regular' price
		if ( $min !== $min_sale || $max !== $max_sale )
			$sale_price = ( $min_sale !== $max_sale ) ? sprintf( _x( '%1$s&ndash;%2$s', 'Price range: from-to', 'qsot-display-options' ), wc_price( $min_sale ), wc_price( $max_sale ) ) : wc_price( $min_sale );

		// if they are not equal, then create html showing that there has been a reduction
		if ( '' !== $sale_price && $price !== $sale_price ) {
			$price = apply_filters( 'woocommerce_variable_sale_price_html', $this->get_price_html_from_to( $price, $sale_price ) . $this->get_price_suffix(), $this );
		// otherwise, if they are equal
		} else {
			// if the price is free, say so
			if ( $min == 0 && $max == 0 ) {
				$price = __( 'Free!', 'woocommerce' );
				$price = apply_filters( 'woocommerce_variable_free_price_html', $price, $this );
			// otherwise, show the price in a range format
			} else {
				$price = apply_filters( 'woocommerce_variable_price_html', $price . $this->get_price_suffix(), $this );
			}
		}

		return apply_filters( 'woocommerce_get_price_html', $price, $this );
	}

	// ignore all grouped product logic, since it is impossible to be grouped, since it is a new, unregister post type
	public function grouped_product_sync() {
		return;
	}
}
