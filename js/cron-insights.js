/**
 * Cron Insights for WP Crontrol Pro.
 *
 * Renders a density visualization of the site's scheduled cron events over the coming week.
 *
 *   data.start_time    Unix timestamp of the start of the window.
 *   data.end_time      Unix timestamp of the end of the window.
 *   data.busiest_hour  Index (0-167) of the hour with the most events.
 *   data.weekly        { scheduleType: number[168] } event counts per hour.
 *   data.hours         { hourIndex: { eventName: { minute: count } } } sparse per-minute counts.
 *   data.event_schedules { eventName: scheduleLabel } each hook's schedule label.
 *   data.non_recurring [ { name, next_run } ] one-time events within the window.
 */
( function () {
	'use strict';

	// A palette of maximally-distinct colours.
	const PALETTE = [
		'#46ae41', '#112fb8', '#d23f31', '#9f92eb',
		'#f1ac51', '#315321', '#732266', '#eb4cbd',
		'#5cd2d9', '#32729d', '#843ddc', '#957a39',
		'#f197b4', '#a1628c', '#813c21', '#44a194',
		'#9fce7f', '#3f65e1', '#3e417e', '#417e2d',
	];

	function paletteColor( index ) {
		return PALETTE[ index % PALETTE.length ];
	}

	let eventColors = {};
	let eventSchedules = {};

	const HOUR = 3600;
	const MINUTE = 60;
	const i18n = ( window.wpCrontrolInsights && window.wpCrontrolInsights.i18n ) || {};
	const shortDay = new Intl.DateTimeFormat( undefined, { weekday: 'short' } );

	// Format day and time for chart labels.
	function formatDayTime( timestamp ) {
		const date = new Date( timestamp * 1000 );
		const day = shortDay.format( date );
		const hours = date.getHours().toString().padStart( 2, '0' );
		const minutes = date.getMinutes().toString().padStart( 2, '0' );
		return day + ' ' + hours + ':' + minutes;
	}

	// Renders each chart's legend as an HTML grid (into a "<canvas id>-legend" container) rather
	// than Chart.js's flowing canvas legend, so the labels line up in aligned columns. Clicking
	// an item toggles that dataset's visibility, matching the native legend behavior.
	const htmlLegend = {
		id: 'htmlLegend',
		afterUpdate( chart ) {
			const container = document.getElementById( chart.canvas.id + '-legend' );
			if ( ! container ) {
				return;
			}

			container.innerHTML = '';

			chart.options.plugins.legend.labels.generateLabels( chart ).forEach( ( item ) => {
				const button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'crontrol-insights-legend-item' + ( item.hidden ? ' is-hidden' : '' );
				button.addEventListener( 'click', () => {
					chart.setDatasetVisibility( item.datasetIndex, ! chart.isDatasetVisible( item.datasetIndex ) );
					chart.update();
				} );

				const swatch = document.createElement( 'span' );
				swatch.className = 'crontrol-insights-legend-swatch';
				swatch.style.backgroundColor = item.fillStyle;

				const label = document.createElement( 'span' );
				label.className = 'crontrol-insights-legend-label';
				label.textContent = item.text;

				// replace ` (` with a <br>:
				const labelParts = item.text.split( ' (' );
				if ( labelParts.length > 1 ) {
					label.textContent = '';
					label.appendChild( document.createTextNode( labelParts[ 0 ] ) );
					label.appendChild( document.createElement( 'br' ) );
					label.appendChild( document.createTextNode( '(' + labelParts.slice( 1 ).join( ' (' ) ) );
				}

				button.appendChild( swatch );
				button.appendChild( label );
				container.appendChild( button );
			} );
		},
	};

	let minutelyChartInstance = null;

	// Create the weekly chart from the precomputed per-hour buckets. The schedule order,
	// labels, and colors come from the registered schedules passed in from PHP.
	function createWeeklyChart( weekly, schedules, startTime, hours ) {
		const labels = [];
		for ( let i = 0; i < 168; i++ ) {
			const timestamp = startTime + ( i * HOUR );
			const hour = new Date( timestamp * 1000 ).getHours();

			if ( i === 0 ) {
				labels.push( i18n.now );
			} else if ( hour === 0 ) {
				labels.push( shortDay.format( new Date( timestamp * 1000 ) ) );
			} else {
				labels.push( '' );
			}
		}

		const datasets = [];
		schedules.forEach( ( schedule, i ) => {
			const series = weekly[ schedule.slug ];
			if ( series && series.some( ( v ) => v > 0 ) ) {
				datasets.push( {
					label: schedule.label,
					data: series,
					backgroundColor: paletteColor( i ),
					borderWidth: 0,
				} );
			}
		} );

		const ctx = document.getElementById( 'crontrol-insights-weekly' ).getContext( '2d' );
		return new Chart( ctx, {
			type: 'bar',
			data: { labels, datasets },
			plugins: [ htmlLegend ],
			options: {
				responsive: true,
				maintainAspectRatio: true,
				aspectRatio: 3,
				animation: false,
				scales: {
					// autoSkip is disabled so the day labels always render; without it
					// Chart.js thins the 168 ticks and lands on the blank in-between labels.
					x: {
						stacked: true,
						grid: { display: false },
						ticks: { autoSkip: false, maxRotation: 0, minRotation: 0 },
					},
					y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
				},
				plugins: {
					legend: {
						display: false,
					},
					tooltip: {
						mode: 'index',
						position: 'nearest',
						xAlign: 'left',
						yAlign: 'center',
						// Hide schedules/events with no events at the hovered time.
						filter: ( item ) => item.parsed.y > 0,
						callbacks: {
							title: function ( context ) {
								return formatDayTime( startTime + ( context[ 0 ].dataIndex * HOUR ) );
							},
							label: function ( context ) {
								return context.dataset.label + ': ' + context.parsed.y.toLocaleString();
							},
							footer: function ( context ) {
								const total = context.reduce( ( sum, item ) => sum + item.parsed.y, 0 );
								return ( i18n.total_label ) + ': ' + total.toLocaleString();
							},
						},
					},
				},
				onClick: function ( evt, activeElements ) {
					if ( activeElements.length > 0 ) {
						updateMinutelyChart( activeElements[ 0 ].index, hours, startTime );
					}
				},
			},
		} );
	}

	// Create the minutely chart for a given hour from the precomputed per-minute buckets.
	function createMinutelyChart( hourIndex, hours, startTime ) {
		const hourStart = startTime + ( hourIndex * HOUR );
		const minutelyData = ( hours && hours[ hourIndex ] ) || {};

		const labels = [];
		for ( let i = 0; i < 60; i++ ) {
			if ( i % 5 === 0 ) {
				const date = new Date( ( hourStart + ( i * MINUTE ) ) * 1000 );
				const hours24 = date.getHours().toString().padStart( 2, '0' );
				const minutes = date.getMinutes().toString().padStart( 2, '0' );
				labels.push( hours24 + ':' + minutes );
			} else {
				labels.push( '' );
			}
		}

		const datasets = [];
		// Sort event names alphabetically so the legend and stacking order are predictable.
		Object.keys( minutelyData ).sort().forEach( ( eventName ) => {
			// Expand the sparse { minute: count } map from PHP into a dense 60-slot array.
			const series = new Array( 60 ).fill( 0 );
			const counts = minutelyData[ eventName ];
			for ( const minute in counts ) {
				if ( Object.prototype.hasOwnProperty.call( counts, minute ) ) {
					series[ minute ] = counts[ minute ];
				}
			}
			const scheduleLabel = eventSchedules[ eventName ];
			datasets.push( {
				label: scheduleLabel ? eventName + ' (' + scheduleLabel + ')' : eventName,
				data: series,
				backgroundColor: eventColors[ eventName ] || PALETTE[ 0 ],
				borderWidth: 0,
			} );
		} );

		const ctx = document.getElementById( 'crontrol-insights-minutely' ).getContext( '2d' );

		if ( minutelyChartInstance ) {
			minutelyChartInstance.destroy();
		}

		minutelyChartInstance = new Chart( ctx, {
			type: 'bar',
			data: { labels, datasets },
			plugins: [ htmlLegend ],
			options: {
				responsive: true,
				maintainAspectRatio: true,
				aspectRatio: 3,
				animation: false,
				scales: {
					x: { stacked: true, grid: { display: false } },
					y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
				},
				plugins: {
					legend: {
						display: false,
					},
					tooltip: {
						mode: 'index',
						position: 'nearest',
						xAlign: 'left',
						yAlign: 'center',
						// Hide schedules/events with no events at the hovered time.
						filter: ( item ) => item.parsed.y > 0,
						callbacks: {
							title: function ( context ) {
								return formatDayTime( hourStart + ( context[ 0 ].dataIndex * MINUTE ) );
							},
							label: function ( context ) {
								return context.dataset.label + ': ' + context.parsed.y.toLocaleString();
							},
							footer: function ( context ) {
								const total = context.reduce( ( sum, item ) => sum + item.parsed.y, 0 );
								return ( i18n.total_label || 'Total' ) + ': ' + total.toLocaleString();
							},
						},
					},
				},
			},
		} );

		return minutelyChartInstance;
	}

	function updateMinutelyChart( hourIndex, hours, startTime ) {
		const hourStart = startTime + ( hourIndex * HOUR );
		const title = document.getElementById( 'crontrol-insights-minutely-title' );
		title.textContent = ( i18n.selectedHour ) + ': ' + formatDayTime( hourStart );
		createMinutelyChart( hourIndex, hours, startTime );
	}

	// Build sparse tick labels for an hourly series: the weekday at each midnight, the hour every
	// six hours, and blanks in between so the 168 ticks stay legible.
	function hourlyLabels( timestamps ) {
		return timestamps.map( ( timestamp, i ) => {
			const date = new Date( timestamp * 1000 );
			const hour = date.getHours();

			if ( i === 0 || hour === 0 ) {
				return shortDay.format( date );
			}
			if ( hour % 6 === 0 ) {
				return hour.toString().padStart( 2, '0' ) + ':00';
			}
			return '';
		} );
	}

	// Shared options for the historical line charts.
	function lineChartOptions( title ) {
		return {
			responsive: true,
			maintainAspectRatio: true,
			aspectRatio: 3,
			animation: false,
			interaction: { mode: 'index', intersect: false },
			scales: {
				x: { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 0, minRotation: 0 } },
				y: { beginAtZero: true, ticks: { precision: 0 } },
			},
			plugins: {
				legend: { display: false },
				tooltip: {
					position: 'nearest',
					filter: ( item ) => item.parsed.y !== null && item.parsed.y > 0,
					callbacks: { title },
				},
			},
		};
	}

	// Errors over the recent window as a line chart.
	function createErrorsChart( metrics ) {
		const series = metrics && metrics.errors_warnings;
		const canvas = document.getElementById( 'crontrol-insights-errors' );
		if ( ! canvas || ! series || ! series.labels.length ) {
			return;
		}

		const options = lineChartOptions( ( context ) => formatDayTime( series.labels[ context[ 0 ].dataIndex ] ) );

		new Chart( canvas.getContext( '2d' ), {
			type: 'line',
			data: {
				labels: hourlyLabels( series.labels ),
				datasets: [
					{
						label: i18n.errors,
						data: series.errors,
						borderColor: '#d23f31',
						backgroundColor: 'rgba(210, 63, 49, 0.15)',
						fill: true,
						borderWidth: 2,
						pointRadius: 0,
						tension: 0,
					},
				],
			},
			options,
		} );
	}

	// Slowest hooks vs. baseline as a horizontal grouped bar chart, or an empty-state message.
	function createSlowHooksChart( metrics ) {
		const slow = ( metrics && metrics.slow_hooks ) || [];
		const canvas = document.getElementById( 'crontrol-insights-slow' );
		const empty = document.getElementById( 'crontrol-insights-slow-empty' );
		if ( ! canvas ) {
			return;
		}

		if ( ! slow.length ) {
			canvas.classList.add( 'hidden' );
			if ( empty ) {
				empty.textContent = i18n.noAnomalies;
				empty.classList.remove( 'hidden' );
			}
			return;
		}

		const unit = i18n.milliseconds;

		new Chart( canvas.getContext( '2d' ), {
			type: 'bar',
			data: {
				labels: slow.map( ( item ) => item.hook ),
				datasets: [
					{
						label: i18n.baselineAvg,
						data: slow.map( ( item ) => item.baseline_avg ),
						backgroundColor: '#32729d',
						borderWidth: 0,
					},
					{
						label: i18n.recentAvg,
						data: slow.map( ( item ) => item.recent_avg ),
						backgroundColor: '#d23f31',
						borderWidth: 0,
					},
				],
			},
			options: {
				indexAxis: 'y',
				responsive: true,
				maintainAspectRatio: true,
				aspectRatio: 3,
				animation: false,
				scales: {
					x: { beginAtZero: true, ticks: { precision: 0 } },
					y: { grid: { display: false } },
				},
				plugins: {
					legend: { position: 'bottom' },
					tooltip: {
						callbacks: {
							label: ( context ) => {
								const base = context.dataset.label + ': ' + context.parsed.x.toLocaleString() + ' ' + unit;
								if ( context.datasetIndex === 1 ) {
									return base + ' (+' + Math.round( slow[ context.dataIndex ].increase_pct ) + '%)';
								}
								return base;
							},
						},
					},
				},
			},
		} );
	}

	// Initialize the visualization from the precomputed data.
	function init() {
		const config = window.wpCrontrolInsights || {};
		const data = config.data || {};
		const container = document.querySelector( '.crontrol-insights' );

		if ( ! container ) {
			return;
		}

		const startTime = data.start_time;
		const hours = data.hours || {};
		eventSchedules = data.event_schedules || {};

		// Assign each event name appearing in the minutely data a color from the palette,
		// sorted so the assignment is stable no matter which hour is selected.
		const uniqueNames = new Set();
		Object.keys( hours ).forEach( ( hourIndex ) => {
			Object.keys( hours[ hourIndex ] ).forEach( ( name ) => uniqueNames.add( name ) );
		} );
		eventColors = {};
		Array.from( uniqueNames ).sort().forEach( ( name, i ) => {
			eventColors[ name ] = paletteColor( i );
		} );

		createWeeklyChart( data.weekly || {}, data.schedules || [], startTime, hours );

		const busiestHour = data.busiest_hour || 0;
		const hourStart = startTime + ( busiestHour * HOUR );
		const title = document.getElementById( 'crontrol-insights-minutely-title' );
		title.textContent = ( i18n.busiestHour ) + ': ' + formatDayTime( hourStart );
		createMinutelyChart( busiestHour, hours, startTime );

		// Historical metrics charts, built from the aggregated event metrics.
		const metrics = config.metrics || {};
		createErrorsChart( metrics );
		// This chart isn't shown currently because it can be misleading when the slowest hooks have high variance. Need to figure out a clearer way to surface this info.
		// createSlowHooksChart( metrics );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
