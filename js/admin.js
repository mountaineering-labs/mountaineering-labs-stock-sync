(function () {
	function toggle() {
		var roleEl = document.getElementById( 'wcss_role' );
		if ( ! roleEl ) {
			return;
		}
		var role = roleEl.value;
		document.querySelectorAll( '.wcss-spoke-only' ).forEach( function ( el ) {
			el.style.display = role === 'spoke' ? '' : 'none';
		} );
		document.querySelectorAll( '.wcss-hub-only' ).forEach( function ( el ) {
			el.style.display = role === 'hub' ? '' : 'none';
		} );
	}
	var roleEl = document.getElementById( 'wcss_role' );
	if ( roleEl ) {
		roleEl.addEventListener( 'change', toggle );
		toggle();
	}
})();
