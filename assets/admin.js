( function () {
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.omcp-copy' );
		if ( ! btn ) {
			return;
		}
		var text = btn.parentNode.querySelector( 'pre' ).textContent;
		var done = function () {
			var old = btn.textContent;
			btn.textContent = '✓';
			setTimeout( function () { btn.textContent = old; }, 1200 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			document.body.appendChild( ta );
			ta.select();
			document.execCommand( 'copy' );
			document.body.removeChild( ta );
			done();
		}
	} );

	document.querySelectorAll( '.omcp-group-toggle' ).forEach( function ( toggle ) {
		var boxes = toggle.closest( 'fieldset' ).querySelectorAll( 'input[name="tools[]"]' );
		var sync = function () {
			toggle.checked = Array.prototype.every.call( boxes, function ( b ) { return b.checked; } );
		};
		sync();
		boxes.forEach( function ( b ) { b.addEventListener( 'change', sync ); } );
		toggle.addEventListener( 'change', function () {
			boxes.forEach( function ( b ) { b.checked = toggle.checked; } );
		} );
	} );
} )();
