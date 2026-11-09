<?php
/**
 * Settings: one option, hw_dealer_settings, edited on Dealers > Settings.
 * Every brand-specific word, color and key lives here, never in code.
 */

defined( 'ABSPATH' ) || exit;

function hw_dealer_setting_defaults() {
	return array(
		'browser_key'        => '',
		'server_key'         => '',
		'map_id'             => '',
		'center_lat'         => '39.8',
		'center_lng'         => '-86.5',
		'zoom'               => '5',
		'countries'          => array( 'us', 'ca' ),
		'units'              => 'mi',
		'radii'              => '50, 100, 200',
		'default_radius'     => '100',
		'accent'             => '#1c4bad',
		'slug'               => 'dealer',
		'label_search'       => 'Address, city or ZIP',
		'label_outstanding'  => 'Outstanding Dealer',
		'label_regular'      => 'Authorized dealer',
		'label_display'      => 'On display',
		'label_dealer'       => 'dealer',
		'label_dealers'      => 'dealers',
		'height'             => '640px',
	);
}

function hw_dealer_settings( $refresh = false ) {
	static $cache = null;
	if ( null === $cache || $refresh ) {
		$saved = get_option( 'hw_dealer_settings', array() );
		$cache = array_merge( hw_dealer_setting_defaults(), is_array( $saved ) ? $saved : array() );
	}
	return $cache;
}

// Same request, new values: the importer copies the Google keys and then geocodes with them.
add_action( 'add_option_hw_dealer_settings', 'hw_dealer_refresh_settings' );
add_action( 'update_option_hw_dealer_settings', 'hw_dealer_refresh_settings' );
function hw_dealer_refresh_settings() {
	hw_dealer_settings( true );
}

function hw_dealer_setting( $key ) {
	$s = hw_dealer_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

/** var(--name) or var(--name, fallback), e.g. an Automatic.css or Bricks color. No ; { } or quotes, so it's safe in a style attribute. */
function hw_dealer_is_css_var( $v ) {
	return (bool) preg_match( '/^var\(\s*--[\w-]+\s*(,\s*[#\w\s.,%()-]+)?\)$/', trim( (string) $v ) );
}

/** A length like 640px or 80vh, or a CSS variable. */
function hw_dealer_valid_height( $v ) {
	$v = trim( (string) $v );
	return preg_match( '/^\d+(\.\d+)?(px|vh|svh|dvh|rem|em)$/', $v ) || hw_dealer_is_css_var( $v );
}

/** Radius choices as integers, in order, from the comma-separated setting. */
function hw_dealer_radii() {
	$radii = array_filter( array_map( 'absint', explode( ',', (string) hw_dealer_setting( 'radii' ) ) ) );
	sort( $radii );
	return $radii ? array_values( array_unique( $radii ) ) : array( 50, 100, 200 );
}

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'edit.php?post_type=hw_dealer', 'Dealer Locator settings', 'Settings', 'manage_options', 'hw-dealer-settings', 'hw_dealer_render_settings' );
	}
);

// Settings link on the Plugins screen, before Deactivate.
add_filter(
	'plugin_action_links_' . plugin_basename( HW_DEALER_FILE ),
	function ( $links ) {
		if ( current_user_can( 'manage_options' ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=hw_dealer&page=hw-dealer-settings' ) ) . '">Settings</a>' );
		}
		return $links;
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'hw_dealer_settings',
			'hw_dealer_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => 'hw_dealer_sanitize_settings',
				'default'           => hw_dealer_setting_defaults(),
			)
		);
	}
);

function hw_dealer_sanitize_settings( $in ) {
	$d   = hw_dealer_setting_defaults();
	$in  = is_array( $in ) ? $in : array();
	$out = array();
	foreach ( $d as $key => $default ) {
		$v = isset( $in[ $key ] ) ? $in[ $key ] : $default;
		switch ( $key ) {
			case 'countries':
				$v = array_values( array_intersect( array( 'us', 'ca' ), (array) $v ) );
				$v = $v ? $v : array( 'us' );
				break;
			case 'units':
				$v = 'km' === $v ? 'km' : 'mi';
				break;
			case 'center_lat':
			case 'center_lng':
				$v = is_numeric( $v ) ? (string) (float) $v : $default;
				break;
			case 'zoom':
			case 'default_radius':
				$v = (string) absint( $v );
				break;
			case 'radii':
				$v = implode( ', ', array_filter( array_map( 'absint', explode( ',', (string) $v ) ) ) );
				$v = $v ? $v : $default;
				break;
			case 'accent':
				$v = trim( (string) $v );
				$v = hw_dealer_is_css_var( $v ) ? $v : sanitize_hex_color( $v );
				$v = $v ? $v : $default;
				break;
			case 'slug':
				$v = sanitize_title( $v );
				$v = $v ? $v : $default;
				break;
			case 'height':
				$v = hw_dealer_valid_height( $v ) ? trim( $v ) : $default;
				break;
			default:
				$v = sanitize_text_field( (string) $v );
		}
		$out[ $key ] = $v;
	}

	if ( hw_dealer_setting( 'slug' ) !== $out['slug'] ) {
		update_option( 'hw_dealer_flush_rewrites', 1 );
	}
	hw_dealer_flush_cache();
	return $out;
}

function hw_dealer_render_settings() {
	$s     = hw_dealer_settings();
	$name  = function ( $k ) {
		return 'hw_dealer_settings[' . $k . ']';
	};
	$text  = function ( $k, $label, $help = '', $class = 'regular-text' ) use ( $s, $name ) {
		printf(
			'<tr><th scope="row"><label for="hwd-%1$s">%2$s</label></th><td><input type="text" class="%5$s" id="hwd-%1$s" name="%3$s" value="%4$s">%6$s</td></tr>',
			esc_attr( $k ),
			esc_html( $label ),
			esc_attr( $name( $k ) ),
			esc_attr( $s[ $k ] ),
			esc_attr( $class ),
			$help ? '<p class="description">' . wp_kses_post( $help ) . '</p>' : ''
		);
	};
	?>
	<div class="wrap">
		<h1>Dealer Locator settings</h1>
		<p>Put the finder on a page with <code>[hw_dealer_locator]</code>. Optional: <code>[hw_dealer_locator height="80vh"]</code>.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'hw_dealer_settings' ); ?>

			<h2>Google Maps</h2>
			<table class="form-table" role="presentation">
				<?php
				$text( 'browser_key', 'Browser key', 'Restricted by HTTP referrer to this site. Needs Maps JavaScript API and Places API (New).' );
				$text( 'server_key', 'Server key', 'Restricted by IP or unrestricted, never by referrer. Needs Geocoding API. Used when a dealer is saved, to find its pin.' );
				$text( 'map_id', 'Map ID', 'From Google Cloud > Map Management (JavaScript, vector). Required for the pins. Leave empty to use Google\'s demo map ID while testing.' );
				?>
			</table>

			<h2>Map and search</h2>
			<table class="form-table" role="presentation">
				<?php
				$text( 'center_lat', 'Start center latitude', '' );
				$text( 'center_lng', 'Start center longitude', 'Before a search, the map fits all dealers. This center is used only when there are none.' );
				$text( 'zoom', 'Start zoom', '' );
				?>
				<tr>
					<th scope="row">Countries</th>
					<td>
						<?php foreach ( array( 'us' => 'United States', 'ca' => 'Canada' ) as $code => $label ) : ?>
							<label style="margin-right:16px"><input type="checkbox" name="<?php echo esc_attr( $name( 'countries' ) ); ?>[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, (array) $s['countries'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
						<p class="description">Search suggestions and dealer addresses are limited to these.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hwd-units">Distance unit</label></th>
					<td><select id="hwd-units" class="regular-text" name="<?php echo esc_attr( $name( 'units' ) ); ?>">
						<option value="mi" <?php selected( $s['units'], 'mi' ); ?>>Miles</option>
						<option value="km" <?php selected( $s['units'], 'km' ); ?>>Kilometers</option>
					</select></td>
				</tr>
				<?php
				$text( 'radii', 'Distance choices', 'Comma-separated. "Any Distance" is always added.' );
				$text( 'default_radius', 'Default distance', 'Used after a search. Must be one of the choices above.' );
				?>
			</table>

			<h2>Look</h2>
			<table class="form-table" role="presentation">
				<?php
				$text( 'accent', 'Brand color', 'Hex or a CSS variable, e.g. <code>#ce030c</code> or <code>var(--primary)</code>. Pins, buttons and badges.' );
				$text( 'height', 'Finder height', 'Desktop height, e.g. <code>640px</code>, <code>80vh</code> or a CSS variable. Phones stack search, map and list.' );
				?>
			</table>

			<h2>Words</h2>
			<table class="form-table" role="presentation">
				<?php
				$text( 'label_search', 'Search placeholder' );
				$text( 'label_outstanding', 'Top-tier dealer label', 'Badge for dealers marked Outstanding.' );
				$text( 'label_regular', 'Regular dealer label' );
				$text( 'label_display', 'Display chip text', 'Shown on dealers marked "On display", e.g. "Playsets on display".' );
				$text( 'label_dealer', 'Singular noun', 'As in "1 dealer".' );
				$text( 'label_dealers', 'Plural noun', 'As in "12 dealers".' );
				?>
			</table>

			<h2>Dealer pages</h2>
			<table class="form-table" role="presentation">
				<?php $text( 'slug', 'URL slug', 'Dealer pages live at <code>' . esc_html( home_url( '/' ) ) . '<strong>' . esc_html( $s['slug'] ) . '</strong>/dealer-name/</code>. Lay them out with a Bricks template for this post type.' ); ?>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
