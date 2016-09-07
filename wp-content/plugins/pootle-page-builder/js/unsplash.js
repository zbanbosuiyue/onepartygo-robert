/**
 * Shramee unsplash image select
 */
function ShrameeUnsplashImageDialog( $ ) {
	var $dlg = $( '#shramee-unsplash-wrap,#shramee-unsplash-overlay' );
	if ( ! $dlg.length ) {
		var $dlog = $( '<div/>' );
		$dlog
			.attr( 'id', 'shramee-unsplash-wrap' )
			.css( 'display', 'none' )
			.append(
				$( '<div/>' ).attr( 'id', 'shramee-unsplash-header' ).html( 'Search for images on unsplash' )
					.append(
						$( '<div/>' ).attr( {
							class : 'dashicons dashicons-no', id : 'shramee-unsplash-close'
						} )
					)
			)
			.append(
				$( '<div/>' ).attr( 'id', 'shramee-unsplash-content' )
					.append(
						$( '<div/>' )
							.attr( 'id', 'shramee-unsplash-search-field-wrap' )
							.append(
								$( '<input>' )
									.attr( { placeholder : 'Search images', id : 'shramee-unsplash-search-field' } )
							)
							.append(
								$( '<a/>' )
									.attr( { href : '#', id : 'shramee-unsplash-search' } )
									.html( '<span class="dashicons dashicons-search"></span>Search images' )
							)
					)
					.append(
						$( '<div/>' ).attr( 'id', 'shramee-unsplash-images' )
					)
			)
			.append(
				$( '<div/>' ).attr( 'id', 'shramee-unsplash-footer' )
					.append(
						$( '<div/>' ).attr( 'id', 'shramee-unsplash-done' )
					)
			);
		$( 'body' )
			.append( '<style>#shramee-unsplash-overlay,#shramee-unsplash-wrap{position:fixed;top:0;right:0;bottom:0;left:0;z-index:999997;background:rgba(0,0,0,.25)}#shramee-unsplash-wrap{text-align:center;margin:auto;top:25px;left:25px;right:25px;bottom:25px;background:#fff;padding:25px;overflow:auto}#shramee-unsplash-content,#shramee-unsplash-footer,#shramee-unsplash-header{position:absolute;top:0;left:0;right:0;padding:7px;background:#ddd;text-align:left}#shramee-unsplash-header{border-bottom:1px solid #aaa;z-index:1}#shramee-unsplash-content{text-align:center;padding:25px;top:39px;bottom:52px;background:#fff;overflow:auto}#shramee-unsplash-close{position:absolute;right:0;top:0;bottom:0;padding:9px;height:auto;width:auto;background:rgba(0,0,0,.25);color:#000;opacity:.5;cursor:pointer}#shramee-unsplash-close:hover{opacity:.7}#shramee-unsplash-wrap *{vertical-align:middle}#shramee-unsplash-search-field{width:50%;min-width:340px;padding:7px 43px 7px 11px;-webkit-border-radius:3px;border-radius:3px;border:1px solid #aaa;box-shadow:0 0 2px 0 rgba(0,0,0,.25);-webkit-box-shadow:0 0 2px 0 rgba(0,0,0,.25)}#shramee-unsplash-search{font-size:0;position:relative;left:-43px;color:#333;display:inline-block;padding:11px}#shramee-unsplash-wrap .image{height:250px;width:250px;display:inline-block;cursor:pointer;background:center/cover}#shramee-unsplash-images{margin:25px auto}#shramee-unsplash-images .image{margin:2px}#shramee-unsplash-images .selected-img{border:3px solid #008ec2;position:relative}#shramee-unsplash-images .selected-img:before{display:block;content:"";background:rgba(0,125,214,.25);position:absolute;top:0;right:0;bottom:0;left:0}#shramee-unsplash-images .selected-img:after{position:relative;display:block;content:"\\f147";background:#008ec2;color:#fff;font:normal 400 50px/1 dashicons;width:50px;padding:2px 3px 0 0;text-align:center}#shramee-unsplash-footer{position:absolute;margin:0;top:auto;bottom:0;left:0;right:0;height:52px;border-top:1px solid #aaa}#shramee-unsplash-done{position:absolute;right:9px;top:9px;bottom:9px;padding:0;line-height:34px;height:auto;width:auto;background:#008ec2;-webkit-border-radius:3px;border-radius:3px;border:1px solid rgba(0,0,0,.16);border-bottom:2px solid rgba(0,0,0,.35);color:#fff;cursor:pointer}#shramee-unsplash-done:after,#shramee-unsplash-done:before{vertical-align:middle;display:block;float:left}#shramee-unsplash-done:after{font-family:sans-serif;content:"Done";height:34px;line-height:32px;padding:0 11px;}</style>' )
			.append( $( '<div/>' ).attr( 'id', 'shramee-unsplash-overlay' ) )
			.append( $dlog );
		$dlg = $( '#shramee-unsplash-wrap,#shramee-unsplash-overlay' );
		var $close = $( '#shramee-unsplash-close' ),
			$done = $( '#shramee-unsplash-done' ),
			$btn  = $( '#shramee-unsplash-search' ),
			$f    = $( '#shramee-unsplash-search-field' ),
			$imgs = $( '#shramee-unsplash-images' );

		$( '#shramee-unsplash-overlay, #shramee-unsplash-close' ).click( function ( e ) {
			ShrameeUnsplashImageDialog.multiple = false;
			$imgs.html( '<h4>Type in search keywords above...</h4>' );
			$dlg.hide();
		} );

		$done.click( function() {
			var url;

			if ( ShrameeUnsplashImageDialog.multiple ) {
				url = [];
				$imgs.find( '.selected-img' ).each( function () {
					var $t = $( this );
					url.push( $t.data( 'image' ) );
				} );
			} else {
				url = $imgs.find( '.selected-img' ).data( 'image' );
			}

			if ( 'function' == typeof ShrameeUnsplashImageDialog.callback ) {
				ShrameeUnsplashImageDialog.callback( url );
				ShrameeUnsplashImageDialog.callback = null;
			}
			$close.click();
		} );

		$f.keypress( function ( e ) {
			if ( e.which == 13 ) {
				$btn.click();
			}
		} );
		$btn.click( function ( e ) {
			e.preventDefault();
			var
				url = 'https://api.unsplash.com/photos/search?client_id=6e7fb4dfb5dfbdcd500ce33d8a6fed84ea535704a33aa57efd9e60b9a032a5bb&per_page=25&query=',
				qry = $f.val().replace( ' ', ',' );

			$imgs.html( '<h4>Searching images...</h4>' );
			$.ajax( url + qry )
				.done( function ( json ) {
					$imgs.html( '' );
					if ( ! json || ! json.length ) {
						$imgs.html( '<p>Couldn\'t find any images matching <b>' + qry + '</b>...</p>' );
					}
					$.each( json, function ( i, v ) {
						$imgs
							.append(
								$( '<div/>' )
									.addClass( 'image' )
									.css( 'background-image', 'url(' + v.urls.small + ')' )
									.data( 'image', v.urls.regular )
									.data( 'ratio', v.height / v.width )
							);
					} );
				} );
		} );
		$imgs.click( function ( e ) {
			var $img = $( e.target ),
			    url  = $img.data( 'image' );

			if ( ShrameeUnsplashImageDialog.multiple ) {
				$img.toggleClass( 'selected-img' );
			} else {
				$imgs.find( '.selected-img' ).removeClass( 'selected-img' );
				$img.toggleClass( 'selected-img' );
			}
		} );
	}
	return $dlg;
}

function ShrameeUnsplashImage( callback, keywords ){
	var $ = jQuery;

	ShrameeUnsplashImageDialog.callback = callback;
	ShrameeUnsplashImageDialog( $ ).show();
	var $search = $( '#shramee-unsplash-search-field-wrap' ).show();

	if ( typeof keywords == 'string' ) {
		$search.hide();
		$( '#shramee-unsplash-search-field' ).val( keywords );
		$( '#shramee-unsplash-search' ).click();
	}
}

function ShrameeUnsplashImages( callback, keywords ){
	ShrameeUnsplashImageDialog.multiple = true;
	ShrameeUnsplashImage( callback, keywords );
}