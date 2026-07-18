/**
 * Aftercare RUM beacon (~2 KB, no dependencies).
 *
 * Uses native PerformanceObserver to capture LCP, CLS, INP and TTFB for a
 * sampled fraction of visits and posts each final value to the Aftercare REST
 * endpoint via sendBeacon on page hide.
 */
( function () {
	'use strict';

	var cfg = window.aftercareRum;
	if ( ! cfg || ! cfg.endpoint || ! ( 'PerformanceObserver' in window ) ) {
		return;
	}
	// Client-side sampling: only this fraction of visits reports.
	if ( Math.random() * 100 >= ( parseInt( cfg.sampleRate, 10 ) || 10 ) ) {
		return;
	}

	var pageUrl = location.href.split( '#' )[ 0 ];
	var metrics = {};
	var sent = false;

	function observe( type, buffered, cb ) {
		try {
			var po = new PerformanceObserver( function ( list ) {
				cb( list.getEntries() );
			} );
			po.observe( { type: type, buffered: buffered } );
			return po;
		} catch ( e ) {
			return null;
		}
	}

	// LCP: last candidate before first input / page hide.
	observe( 'largest-contentful-paint', true, function ( entries ) {
		var last = entries[ entries.length - 1 ];
		if ( last ) {
			metrics.LCP = last.renderTime || last.loadTime || last.startTime;
		}
	} );

	// CLS: session-window sum (simplified: total of shifts without recent input).
	var clsValue = 0;
	observe( 'layout-shift', true, function ( entries ) {
		entries.forEach( function ( entry ) {
			if ( ! entry.hadRecentInput ) {
				clsValue += entry.value;
			}
		} );
		metrics.CLS = clsValue;
	} );

	// INP (approximation): worst interaction duration from the Event Timing API.
	var worstInteraction = 0;
	observe( 'event', true, function ( entries ) {
		entries.forEach( function ( entry ) {
			if ( entry.interactionId && entry.duration > worstInteraction ) {
				worstInteraction = entry.duration;
				metrics.INP = worstInteraction;
			}
		} );
	} );

	// TTFB from navigation timing.
	try {
		var nav = performance.getEntriesByType( 'navigation' )[ 0 ];
		if ( nav && nav.responseStart > 0 ) {
			metrics.TTFB = nav.responseStart;
		}
	} catch ( e ) {}

	function send() {
		if ( sent ) {
			return;
		}
		sent = true;
		Object.keys( metrics ).forEach( function ( name ) {
			var value = metrics[ name ];
			if ( typeof value !== 'number' || ! isFinite( value ) ) {
				return;
			}
			var payload = JSON.stringify( { metric: name, value: value, url: pageUrl } );
			if ( navigator.sendBeacon ) {
				navigator.sendBeacon( cfg.endpoint, new Blob( [ payload ], { type: 'application/json' } ) );
			} else {
				var xhr = new XMLHttpRequest();
				xhr.open( 'POST', cfg.endpoint, true );
				xhr.setRequestHeader( 'Content-Type', 'application/json' );
				xhr.send( payload );
			}
		} );
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			send();
		}
	} );
	window.addEventListener( 'pagehide', send );
}() );
