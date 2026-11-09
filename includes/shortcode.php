<?php
/**
 * [hw_dealer_locator height="640px"]: the finder. The markup is a static shell;
 * assets/locator.js fills the list, map and popup from the inline dealer data.
 */

defined( 'ABSPATH' ) || exit;

// Registered on demand too: block themes render the page content (and so the shortcode) before wp_enqueue_scripts.
function hw_dealer_register_assets() {
	if ( wp_script_is( 'hw-dealer-locator', 'registered' ) ) {
		return;
	}
	wp_register_style( 'hw-dealer-locator', plugins_url( 'assets/locator.css', HW_DEALER_FILE ), array(), hw_dealer_asset_ver( 'assets/locator.css' ) );
	wp_register_script( 'hw-dealer-clusterer', plugins_url( 'assets/vendor/markerclusterer.min.js', HW_DEALER_FILE ), array(), '2.5.3', true );
	wp_register_script( 'hw-dealer-locator', plugins_url( 'assets/locator.js', HW_DEALER_FILE ), array( 'hw-dealer-clusterer' ), hw_dealer_asset_ver( 'assets/locator.js' ), true );
}
add_action( 'wp_enqueue_scripts', 'hw_dealer_register_assets' );

function hw_dealer_icon( $name ) {
	$paths = array(
		'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'locate' => '<circle cx="12" cy="12" r="3.5"/><circle cx="12" cy="12" r="7.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22"/>',
		'x'      => '<path d="M6 6l12 12M18 6 6 18"/>',
		'fit'    => '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
		'left'   => '<path d="M15 18l-6-6 6-6"/>',
		'right'  => '<path d="M9 18l6-6-6-6"/>',
	);
	return '<svg class="hwdl-i" viewBox="0 0 24 24" aria-hidden="true">' . $paths[ $name ] . '</svg>';
}

add_shortcode(
	'hw_dealer_locator',
	function ( $atts ) {
		// One finder per page: the script drives the first #hwdl. Not enforced here, because
		// SEO plugins and builders often render content an extra time to build excerpts.
		$s    = hw_dealer_settings();
		$atts = shortcode_atts( array( 'height' => $s['height'] ), $atts, 'hw_dealer_locator' );

		$config = array(
			'key'        => $s['browser_key'],
			'mapId'      => $s['map_id'] ? $s['map_id'] : 'DEMO_MAP_ID',
			'center'     => array( (float) $s['center_lat'], (float) $s['center_lng'] ),
			'zoom'       => (int) $s['zoom'],
			'countries'  => array_values( (array) $s['countries'] ),
			'units'      => $s['units'],
			'radii'      => hw_dealer_radii(),
			'radius'     => (int) $s['default_radius'],
			'labels'     => array(
				'top'     => $s['label_outstanding'],
				'regular' => $s['label_regular'],
				'display' => $s['label_display'],
				'one'     => $s['label_dealer'],
				'many'    => $s['label_dealers'],
			),
			'dealers'    => hw_dealer_payload(),
		);

		hw_dealer_register_assets();
		wp_enqueue_style( 'hw-dealer-locator' );
		wp_enqueue_script( 'hw-dealer-locator' );

		$height = hw_dealer_valid_height( $atts['height'] ) ? trim( $atts['height'] ) : $s['height'];
		$style  = '--hwdl-accent:' . $s['accent'] . ';--hwdl-height:' . $height;

		ob_start();
		?>
		<div class="hwdl" id="hwdl" style="<?php echo esc_attr( $style ); ?>">
			<div class="hwdl-search">
				<div class="hwdl-searchbox">
					<?php echo hw_dealer_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<input id="hwdl-q" type="text" role="combobox" autocomplete="off" placeholder="<?php echo esc_attr( $s['label_search'] ); ?>" aria-label="<?php echo esc_attr( $s['label_search'] ); ?>" aria-autocomplete="list" aria-controls="hwdl-suggest" aria-expanded="false">
					<button type="button" class="hwdl-where" id="hwdl-where" hidden></button>
					<button type="button" class="hwdl-icon-btn" id="hwdl-clear" aria-label="Clear search" hidden><?php echo hw_dealer_icon( 'x' ); // phpcs:ignore ?></button>
					<button type="button" class="hwdl-icon-btn hwdl-locate" id="hwdl-locate" aria-label="Use my location" title="Use my location"><?php echo hw_dealer_icon( 'locate' ); // phpcs:ignore ?></button>
				</div>
				<ul class="hwdl-suggest" id="hwdl-suggest" role="listbox" hidden></ul>
				<div class="hwdl-radius" id="hwdl-radius" hidden></div>
			</div>
			<div class="hwdl-results" id="hwdl-results" role="region" aria-label="Dealer list">
				<div class="hwdl-res-head" id="hwdl-count" aria-live="polite"></div>
				<ul class="hwdl-list" id="hwdl-list">
					<?php // Plain links until the script takes over, for search engines and no-JS visitors. ?>
					<?php foreach ( $config['dealers'] as $d ) : ?>
						<li class="hwdl-item"><a class="hwdl-item-title" href="<?php echo esc_url( $d['url'] ); ?>"><?php echo esc_html( isset( $d['name'] ) ? $d['name'] : '' ); ?></a><div class="hwdl-item-meta"><?php echo esc_html( trim( ( isset( $d['city'] ) ? $d['city'] : '' ) . ', ' . ( isset( $d['st'] ) ? $d['st'] : '' ), ', ' ) ); ?></div></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<div class="hwdl-map-wrap">
				<div class="hwdl-map" id="hwdl-map" aria-label="Dealer map"></div>
				<button type="button" class="hwdl-fit" id="hwdl-fit" title="Zoom back out to show every result" hidden><?php echo hw_dealer_icon( 'fit' ); // phpcs:ignore ?>Reset view</button>
				<?php // Details: a card over the map on desktop (non-modal), a bottom sheet on phones (modal). ?>
				<dialog class="hwdl-modal" id="hwdl-dialog" aria-labelledby="hwdl-detail-title">
					<div class="hwdl-modal-inner">
						<button type="button" class="hwdl-icon-btn hwdl-modal-close" id="hwdl-dialog-close" aria-label="Close details"><?php echo hw_dealer_icon( 'x' ); // phpcs:ignore ?></button>
						<div id="hwdl-dialog-body"></div>
					</div>
				</dialog>
			</div>
			<dialog class="hwdl-lightbox" id="hwdl-lightbox" aria-label="Photo">
				<figure><img id="hwdl-lb-img" alt=""><figcaption id="hwdl-lb-cap"></figcaption></figure>
				<button type="button" class="hwdl-lb-btn hwdl-lb-prev" id="hwdl-lb-prev" aria-label="Previous photo"><?php echo hw_dealer_icon( 'left' ); // phpcs:ignore ?></button>
				<button type="button" class="hwdl-lb-btn hwdl-lb-next" id="hwdl-lb-next" aria-label="Next photo"><?php echo hw_dealer_icon( 'right' ); // phpcs:ignore ?></button>
				<button type="button" class="hwdl-lb-btn hwdl-lb-close" id="hwdl-lb-close" aria-label="Close photo"><?php echo hw_dealer_icon( 'x' ); // phpcs:ignore ?></button>
			</dialog>
			<div class="hwdl-toast" id="hwdl-toast" role="status" aria-live="polite" hidden></div>
			<script type="application/json" id="hwdl-data"><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?></script>
			<noscript><p>Turn on JavaScript to use the dealer map.</p></noscript>
		</div>
		<?php
		return ob_get_clean();
	}
);
