/**
 * Aftercare admin: renders SVG sparklines into elements carrying a
 * data-sparkline attribute (JSON array of numbers). A data-budget attribute
 * adds a dashed budget line; points above budget are marked amber/red.
 */
( function () {
	'use strict';

	var NS = 'http://www.w3.org/2000/svg';

	function el( name, attrs ) {
		var node = document.createElementNS( NS, name );
		Object.keys( attrs ).forEach( function ( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );
		return node;
	}

	function renderSparkline( container ) {
		var values;
		try {
			values = JSON.parse( container.getAttribute( 'data-sparkline' ) || '[]' );
		} catch ( e ) {
			return;
		}
		values = values.filter( function ( v ) {
			return typeof v === 'number' && isFinite( v );
		} );
		if ( values.length < 2 ) {
			container.textContent = '';
			return;
		}

		var budget = parseFloat( container.getAttribute( 'data-budget' ) || '0' );
		var w = 100;
		var h = 30;
		var pad = 2;
		var max = Math.max.apply( null, values.concat( budget > 0 ? [ budget ] : [] ) );
		var min = Math.min.apply( null, values.concat( budget > 0 ? [ budget ] : [] ) );
		if ( max === min ) {
			max = min + 1;
		}

		var x = function ( i ) {
			return pad + ( i / ( values.length - 1 ) ) * ( w - 2 * pad );
		};
		var y = function ( v ) {
			return h - pad - ( ( v - min ) / ( max - min ) ) * ( h - 2 * pad );
		};

		var svg = el( 'svg', { viewBox: '0 0 ' + w + ' ' + h, preserveAspectRatio: 'none', role: 'img' } );

		if ( budget > 0 ) {
			svg.appendChild( el( 'line', {
				x1: 0,
				y1: y( budget ),
				x2: w,
				y2: y( budget ),
				stroke: '#d97706',
				'stroke-width': '0.6',
				'stroke-dasharray': '2,2'
			} ) );
		}

		var d = values.map( function ( v, i ) {
			return ( i === 0 ? 'M' : 'L' ) + x( i ).toFixed( 2 ) + ' ' + y( v ).toFixed( 2 );
		} ).join( ' ' );

		svg.appendChild( el( 'path', {
			d: d,
			fill: 'none',
			stroke: '#0f766e',
			'stroke-width': '1.5',
			'stroke-linejoin': 'round',
			'stroke-linecap': 'round'
		} ) );

		// Mark the latest point; red when over budget.
		var last = values[ values.length - 1 ];
		svg.appendChild( el( 'circle', {
			cx: x( values.length - 1 ),
			cy: y( last ),
			r: '2',
			fill: budget > 0 && last > budget ? '#b91c1c' : '#0f766e'
		} ) );

		container.textContent = '';
		container.appendChild( svg );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-sparkline]' ).forEach( renderSparkline );
	} );
}() );
