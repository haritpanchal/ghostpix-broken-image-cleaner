/**
 * Keeps the scan status on the tools screen moving while a scan is running.
 *
 * Each tick asks the server to examine one batch, then either updates the
 * count or reloads once the scan has finished so the finished panel and the
 * results table are rendered together.
 */
( function ( $ ) {
	var config = window.ghostpixBrokenImageCleaner;
	var $progress = $( '#ghostpix-broken-image-cleaner-progress' );

	if ( ! config || ! $progress.length ) {
		return;
	}

	function tick() {
		$.post( config.ajaxUrl, {
			action: config.action,
			nonce: config.nonce
		} )
			.done( function ( payload ) {
				if ( ! payload || ! payload.success || ! payload.data ) {
					window.setTimeout( tick, 3000 );
					return;
				}

				if ( ! payload.data.running ) {
					window.location.reload();
					return;
				}

				$progress.text( payload.data.message );
				window.setTimeout( tick, 400 );
			} )
			.fail( function () {
				window.setTimeout( tick, 3000 );
			} );
	}

	tick();
}( jQuery ) );
