<?php
/**
 * Dealers > Import: from WP Store Locator (wpsl_stores) or a CSV file.
 *
 * WPSL import keeps each store's slug, coordinates, hours and status, copies the
 * Google keys if ours are empty, and can read extra fields a site added with ACF
 * (field names are set on the import screen). Each dealer remembers its source
 * (hw_dealer_source = "wpsl:<id>"), so running it again updates instead of duplicating.
 * Preview first: it lists every store with any problems found, and changes nothing.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'edit.php?post_type=hw_dealer', 'Import dealers', 'Import', 'manage_options', 'hw-dealer-import', 'hw_dealer_render_import' );
	}
);

function hw_dealer_import_extra_defaults() {
	return array(
		'outstanding'  => 'outstanding_dealer',
		'logo'         => 'logo',
		'photo'        => 'location_image',
		'about'        => 'about_this_dealer',
		'contact_name' => 'contact_name',
		'on_display'   => '',
	);
}

/** ACF true/false and checkbox values ("1", "true", array( "true" )) => bool. */
function hw_dealer_truthy( $v ) {
	$v = maybe_unserialize( $v );
	if ( is_array( $v ) ) {
		return (bool) array_filter( $v, 'hw_dealer_truthy' );
	}
	return in_array( strtolower( trim( (string) $v ) ), array( '1', 'true', 'yes', 'on', 'y' ), true );
}

function hw_dealer_state_codes() {
	return explode( ' ', 'AL AK AZ AR CA CO CT DE DC FL GA HI ID IL IN IA KS KY LA ME MD MA MI MN MS MO MT NE NV NH NJ NM NY NC ND OH OK OR PA RI SC SD TN TX UT VT VA WA WV WI WY AB BC MB NB NL NS NT NU ON PE QC SK YT' );
}

/** Full state or province name => code, for sources that spell them out. */
function hw_dealer_state_code( $s ) {
	$s = trim( (string) $s );
	if ( in_array( strtoupper( $s ), hw_dealer_state_codes(), true ) ) {
		return strtoupper( $s );
	}
	$names = array( 'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR', 'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE', 'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS', 'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD', 'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV', 'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY', 'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK', 'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY', 'alberta' => 'AB', 'british columbia' => 'BC', 'manitoba' => 'MB', 'new brunswick' => 'NB', 'newfoundland and labrador' => 'NL', 'nova scotia' => 'NS', 'ontario' => 'ON', 'prince edward island' => 'PE', 'quebec' => 'QC', 'saskatchewan' => 'SK' );
	$k     = strtolower( $s );
	return isset( $names[ $k ] ) ? $names[ $k ] : $s;
}

/**
 * Turn one source row into dealer fields, fixing what can be fixed and listing problems.
 *
 * @param array $f  Dealer fields (see hw_dealer_fields()) plus 'name', 'slug', 'status', 'about'.
 * @return array    The same, with 'warnings' (string[]).
 */
function hw_dealer_import_clean( $f ) {
	$w          = array();
	$codes      = hw_dealer_state_codes();
	$f['state'] = hw_dealer_state_code( isset( $f['state'] ) ? $f['state'] : '' );
	$f['city']  = trim( isset( $f['city'] ) ? $f['city'] : '' );

	// City and state entered the wrong way round ("KY" as the city, "Crittenden" as the state).
	if ( in_array( strtoupper( $f['city'] ), $codes, true ) && ! in_array( $f['state'], $codes, true ) ) {
		$w[]        = sprintf( 'City and state were swapped ("%s", "%s"); fixed', $f['city'], $f['state'] );
		$tmp        = $f['state'];
		$f['state'] = strtoupper( $f['city'] );
		$f['city']  = $tmp;
	} elseif ( $f['state'] && ! in_array( $f['state'], $codes, true ) ) {
		$w[] = sprintf( 'State "%s" isn\'t a US state or Canadian province code', $f['state'] );
	}
	if ( empty( $f['lat'] ) || empty( $f['lng'] ) ) {
		$w[] = 'No coordinates; will be looked up from the address';
	}
	if ( empty( $f['phone'] ) ) {
		$w[] = 'No phone';
	}
	if ( empty( $f['address'] ) ) {
		$w[] = 'No street address';
	}
	$f['warnings'] = $w;
	return $f;
}

/** @return array[] Cleaned dealers from WPSL, keyed by WPSL post ID. */
function hw_dealer_wpsl_rows( $extra, $skip_default_hours ) {
	$wpsl_settings = get_option( 'wpsl_settings', array() );
	$default_hours = isset( $wpsl_settings['editor_hours']['dropdown'] ) ? hw_dealer_hours_from_wpsl( $wpsl_settings['editor_hours']['dropdown'] ) : null;

	$posts = get_posts(
		array(
			'post_type'      => 'wpsl_stores',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	update_meta_cache( 'post', wp_list_pluck( $posts, 'ID' ) );

	$rows  = array();
	$names = array();
	foreach ( $posts as $p ) {
		$m     = function ( $k ) use ( $p ) {
			return $k ? get_post_meta( $p->ID, $k, true ) : '';
		};
		$hours = $m( 'wpsl_hours' );
		$hours = is_array( $hours ) ? hw_dealer_hours_from_wpsl( $hours ) : array();
		$note  = '';
		if ( $skip_default_hours && $default_hours && $hours === $default_hours ) {
			$hours = array();
			$note  = 'default';
		}
		$photo = (int) $m( $extra['photo'] );
		$about = $m( $extra['about'] );
		$f     = array(
			'name'         => html_entity_decode( $p->post_title, ENT_QUOTES, 'UTF-8' ),
			'slug'         => $p->post_name,
			'status'       => $p->post_status,
			'about'        => trim( $about ? $about : $p->post_content ),
			'address'      => $m( 'wpsl_address' ),
			'address2'     => $m( 'wpsl_address2' ),
			'city'         => $m( 'wpsl_city' ),
			'state'        => $m( 'wpsl_state' ),
			'zip'          => $m( 'wpsl_zip' ),
			'country'      => $m( 'wpsl_country_iso' ) ? $m( 'wpsl_country_iso' ) : ( false !== stripos( $m( 'wpsl_country' ), 'canada' ) ? 'CA' : 'US' ),
			'lat'          => $m( 'wpsl_lat' ),
			'lng'          => $m( 'wpsl_lng' ),
			'phone'        => $m( 'wpsl_phone' ),
			'email'        => $m( 'wpsl_email' ),
			'website'      => $m( 'wpsl_url' ),
			'hours'        => $hours,
			'outstanding'  => hw_dealer_truthy( $m( $extra['outstanding'] ) ),
			'on_display'   => hw_dealer_truthy( $m( $extra['on_display'] ) ),
			'logo'         => (int) $m( $extra['logo'] ),
			'gallery'      => $photo ? (string) $photo : '',
			'contact_name' => $m( $extra['contact_name'] ),
			'source'       => 'wpsl:' . $p->ID,
		);
		$f = hw_dealer_import_clean( $f );
		if ( 'default' === $note ) {
			$f['warnings'][] = 'Hours were WP Store Locator\'s default (9–5 weekdays); left empty';
		}
		if ( 'publish' !== $p->post_status ) {
			$f['warnings'][] = 'Not published in WP Store Locator (' . $p->post_status . '); imported the same way';
		}
		$key = strtolower( preg_replace( '/[^a-z0-9]/i', '', $f['name'] ) );
		if ( isset( $names[ $key ] ) ) {
			$f['warnings'][] = 'Possible duplicate of WPSL #' . $names[ $key ];
		} else {
			$names[ $key ] = $p->ID;
		}
		$rows[ $p->ID ] = $f;
	}
	return $rows;
}

/** Existing dealers keyed by hw_dealer_source. */
function hw_dealer_by_source() {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT pm.meta_value, pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'hw_dealer_source' AND p.post_type = 'hw_dealer' AND p.post_status <> 'trash'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$out  = array();
	foreach ( $rows as $r ) {
		$out[ $r->meta_value ] = (int) $r->post_id;
	}
	return $out;
}

/**
 * Create or update one dealer from import fields.
 *
 * @return array{id: int, action: string}|WP_Error
 */
function hw_dealer_import_one( $f, $existing_id = 0 ) {
	$postarr = array(
		'post_type'    => 'hw_dealer',
		'post_title'   => $f['name'],
		'post_status'  => isset( $f['status'] ) && in_array( $f['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ? $f['status'] : 'publish',
		'post_content' => isset( $f['about'] ) ? wp_kses_post( $f['about'] ) : '',
	);
	if ( ! empty( $f['slug'] ) ) {
		$postarr['post_name'] = $f['slug'];
	}
	if ( $existing_id ) {
		$postarr['ID'] = $existing_id;
	}
	$id = wp_insert_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	foreach ( hw_dealer_fields() as $field => $type ) {
		if ( array_key_exists( $field, $f ) ) {
			update_post_meta( $id, 'hw_dealer_' . $field, wp_slash( hw_dealer_sanitize_field( $type, $f[ $field ] ) ) );
		}
	}
	if ( ! empty( $f['lat'] ) && ! empty( $f['lng'] ) ) {
		// Imported coordinates are trusted like a hand-placed pin, until the address is edited.
		update_post_meta( $id, 'hw_dealer_geocoded_for', md5( hw_dealer_address_line( $id ) . '|' . hw_dealer_get( $id, 'country' ) ) );
		delete_post_meta( $id, 'hw_dealer_geocode_error' );
	} else {
		hw_dealer_maybe_geocode( $id, true );
	}
	return array(
		'id'     => $id,
		'action' => $existing_id ? 'updated' : 'created',
	);
}

/** Header names accepted in a CSV (lowercased, spaces and dashes as underscores) => field. */
function hw_dealer_csv_columns() {
	return array(
		'name'         => 'name',
		'dealer'       => 'name',
		'title'        => 'name',
		'address'      => 'address',
		'street'       => 'address',
		'address2'     => 'address2',
		'address_2'    => 'address2',
		'city'         => 'city',
		'state'        => 'state',
		'province'     => 'state',
		'zip'          => 'zip',
		'postal_code'  => 'zip',
		'zip_code'     => 'zip',
		'country'      => 'country',
		'lat'          => 'lat',
		'latitude'     => 'lat',
		'lng'          => 'lng',
		'lon'          => 'lng',
		'longitude'    => 'lng',
		'phone'        => 'phone',
		'email'        => 'email',
		'website'      => 'website',
		'url'          => 'website',
		'contact_name' => 'contact_name',
		'contact'      => 'contact_name',
		'notes'        => 'notes',
		'about'        => 'about',
		'outstanding'  => 'outstanding',
		'on_display'   => 'on_display',
		'display'      => 'on_display',
		'hours_note'   => 'hours_note',
		'slug'         => 'slug',
	);
}

/** @return array[]|WP_Error Cleaned rows. */
function hw_dealer_csv_rows( $file ) {
	$fh = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) {
		return new WP_Error( 'csv', 'The file could not be read.' );
	}
	$head = fgetcsv( $fh );
	if ( ! $head ) {
		return new WP_Error( 'csv', 'The file is empty.' );
	}
	$map  = hw_dealer_csv_columns();
	$cols = array();
	foreach ( $head as $i => $h ) {
		$h = strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', $h ) ) );
		$h = preg_replace( '/[\s\-]+/', '_', $h );
		if ( isset( $map[ $h ] ) ) {
			$cols[ $i ] = $map[ $h ];
		}
	}
	if ( ! in_array( 'name', $cols, true ) ) {
		return new WP_Error( 'csv', 'The first row must be column names, including a "name" column.' );
	}
	$rows = array();
	$line = 1;
	while ( ( $r = fgetcsv( $fh ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		++$line;
		$f = array();
		foreach ( $cols as $i => $field ) {
			$f[ $field ] = isset( $r[ $i ] ) ? trim( $r[ $i ] ) : '';
		}
		if ( empty( $f['name'] ) ) {
			continue;
		}
		$f['country']     = isset( $f['country'] ) && preg_match( '/^(ca|can|canada)$/i', $f['country'] ) ? 'CA' : 'US';
		$f['outstanding'] = isset( $f['outstanding'] ) && hw_dealer_truthy( $f['outstanding'] );
		$f['on_display']  = isset( $f['on_display'] ) && hw_dealer_truthy( $f['on_display'] );
		$f['source']      = 'csv:' . sanitize_title( $f['name'] . '-' . ( isset( $f['city'] ) ? $f['city'] : '' ) );
		$f                = hw_dealer_import_clean( $f );
		$rows[ $line ]    = $f;
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return $rows;
}

function hw_dealer_render_import() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$extra = hw_dealer_import_extra_defaults();
	foreach ( $extra as $k => $v ) {
		if ( isset( $_REQUEST[ 'extra_' . $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$extra[ $k ] = sanitize_key( wp_unslash( $_REQUEST[ 'extra_' . $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}
	$step         = isset( $_REQUEST['step'] ) ? sanitize_key( $_REQUEST['step'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$skip_default = ! isset( $_REQUEST['step'] ) || ! empty( $_REQUEST['skip_default_hours'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$update       = ! empty( $_REQUEST['update_existing'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$wpsl_count   = (int) wp_count_posts( 'wpsl_stores' )->publish;
	$existing     = hw_dealer_by_source();
	$report       = null;

	if ( $step && ! check_admin_referer( 'hw_dealer_import' ) ) {
		return;
	}

	if ( 'wpsl_import' === $step || 'csv' === $step ) {
		if ( 'csv' === $step ) {
			$rows = empty( $_FILES['csv']['tmp_name'] ) ? new WP_Error( 'csv', 'Choose a CSV file.' ) : hw_dealer_csv_rows( $_FILES['csv']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} else {
			$rows = hw_dealer_wpsl_rows( $extra, $skip_default );
			$s    = get_option( 'hw_dealer_settings', array() );
			$w    = get_option( 'wpsl_settings', array() );
			$s    = is_array( $s ) ? $s : array();
			$copy = array();
			foreach ( array( 'browser_key' => 'api_browser_key', 'server_key' => 'api_server_key' ) as $ours => $theirs ) {
				if ( empty( $s[ $ours ] ) && ! empty( $w[ $theirs ] ) ) {
					$s[ $ours ] = $w[ $theirs ];
					$copy[]     = $ours;
				}
			}
			if ( $copy ) {
				update_option( 'hw_dealer_settings', array_merge( hw_dealer_settings(), $s ) );
			}
		}
		if ( is_wp_error( $rows ) ) {
			$report = array( 'error' => $rows->get_error_message() );
		} else {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$report = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => array(), 'keys' => isset( $copy ) ? $copy : array() );
			foreach ( $rows as $f ) {
				$had = isset( $existing[ $f['source'] ] ) ? $existing[ $f['source'] ] : 0;
				if ( $had && ! $update ) {
					++$report['skipped'];
					continue;
				}
				$r = hw_dealer_import_one( $f, $had );
				if ( is_wp_error( $r ) ) {
					$report['failed'][] = $f['name'] . ': ' . $r->get_error_message();
				} else {
					++$report[ $r['action'] ];
				}
			}
			hw_dealer_flush_cache();
			$existing = hw_dealer_by_source();
		}
	}
	?>
	<div class="wrap">
		<h1>Import dealers</h1>

		<?php if ( $report ) : ?>
			<?php if ( isset( $report['error'] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $report['error'] ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-success"><p>
					<?php
					printf( 'Created %d, updated %d, skipped %d already imported.', (int) $report['created'], (int) $report['updated'], (int) $report['skipped'] );
					if ( $report['keys'] ) {
						echo ' Copied WP Store Locator\'s Google keys into Dealers &gt; Settings.';
					}
					?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hw_dealer' ) ); ?>">See the dealers</a>
				</p>
				<?php if ( $report['failed'] ) : ?>
					<p><strong>Failed:</strong></p><ul><?php foreach ( $report['failed'] as $msg ) : ?><li><?php echo esc_html( $msg ); ?></li><?php endforeach; ?></ul>
				<?php endif; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<h2>From WP Store Locator</h2>
		<?php if ( ! $wpsl_count && ! post_type_exists( 'wpsl_stores' ) && ! get_option( 'wpsl_settings' ) ) : ?>
			<p>WP Store Locator isn't on this site.</p>
		<?php else : ?>
			<p><?php echo (int) $wpsl_count; ?> published stores. Keep WP Store Locator active while importing. Preview first: it changes nothing.</p>
			<form method="post">
				<?php wp_nonce_field( 'hw_dealer_import' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Extra fields</th><td>
						<p class="description" style="margin-bottom:8px">Custom fields (meta keys, e.g. from ACF) this site added to its stores. Leave a box empty to skip it.</p>
						<?php
						$labels = array(
							'outstanding'  => 'Outstanding flag',
							'on_display'   => 'On display flag',
							'logo'         => 'Logo (image ID)',
							'photo'        => 'Photo (image ID, becomes the first gallery photo)',
							'about'        => 'About text (dealer page)',
							'contact_name' => 'Contact name',
						);
						foreach ( $labels as $k => $label ) {
							printf( '<p><label style="display:inline-block;width:300px">%s</label><input type="text" name="extra_%s" value="%s" class="regular-text code"></p>', esc_html( $label ), esc_attr( $k ), esc_attr( $extra[ $k ] ) );
						}
						?>
					</td></tr>
					<tr><th scope="row">Options</th><td>
						<p><label><input type="checkbox" name="skip_default_hours" value="1" <?php checked( $skip_default ); ?>> Leave hours empty when they're WP Store Locator's default (probably never filled in)</label></p>
						<p><label><input type="checkbox" name="update_existing" value="1" <?php checked( $update ); ?>> Update dealers already imported (otherwise they're skipped)</label></p>
					</td></tr>
				</table>
				<p>
					<button class="button" name="step" value="wpsl_preview">Preview</button>
					<button class="button button-primary" name="step" value="wpsl_import" onclick="return confirm('Import the stores from WP Store Locator now?')">Import</button>
				</p>
			</form>

			<?php
			if ( 'wpsl_preview' === $step ) :
				$rows     = hw_dealer_wpsl_rows( $extra, $skip_default );
				$problems = count( array_filter( wp_list_pluck( $rows, 'warnings' ) ) );
				?>
				<h3>Preview: <?php echo count( $rows ); ?> stores, <?php echo (int) $problems; ?> with notes</h3>
				<table class="widefat striped">
					<thead><tr><th>Store</th><th>Location</th><th>Flags</th><th>Extras</th><th>Notes</th><th>Status here</th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $wid => $f ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $f['name'] ); ?></strong><br><code>/<?php echo esc_html( $f['slug'] ); ?>/</code> · <a href="<?php echo esc_url( get_edit_post_link( $wid ) ); ?>">WPSL #<?php echo (int) $wid; ?></a></td>
							<td><?php echo esc_html( trim( $f['city'] . ', ' . $f['state'] . ' ' . $f['zip'], ', ' ) ); ?> <?php echo esc_html( $f['country'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', array_filter( array( $f['outstanding'] ? 'Outstanding' : '', $f['on_display'] ? 'On display' : '' ) ) ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', array_filter( array( $f['logo'] ? 'logo' : '', $f['gallery'] ? 'photo' : '', $f['about'] ? 'about' : '', $f['contact_name'] ? 'contact' : '', hw_dealer_has_hours( $f['hours'] ) ? 'hours' : '' ) ) ) ); ?></td>
							<td><?php echo $f['warnings'] ? '<ul style="margin:0">' . implode( '', array_map( function ( $x ) { return '<li>' . esc_html( $x ) . '</li>'; }, $f['warnings'] ) ) . '</ul>' : ''; // phpcs:ignore ?></td>
							<td><?php echo isset( $existing[ $f['source'] ] ) ? '<a href="' . esc_url( get_edit_post_link( $existing[ $f['source'] ] ) ) . '">Imported</a>' : 'New'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>

		<h2 style="margin-top:32px">From a CSV file</h2>
		<p>First row: column names. Recognized: <code>name</code> (required), <code>address</code>, <code>address2</code>, <code>city</code>, <code>state</code>, <code>zip</code>, <code>country</code> (US or CA), <code>phone</code>, <code>email</code>, <code>website</code>, <code>contact_name</code>, <code>notes</code>, <code>about</code>, <code>outstanding</code> and <code>on_display</code> (yes/no), <code>hours_note</code>, <code>lat</code>, <code>lng</code>, <code>slug</code>. Rows without lat/lng are looked up from the address (about a second each).</p>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'hw_dealer_import' ); ?>
			<p><input type="file" name="csv" accept=".csv,text/csv"></p>
			<p><label><input type="checkbox" name="update_existing" value="1"> Update dealers already imported from a CSV (matched by name and city)</label></p>
			<p><button class="button button-primary" name="step" value="csv">Import CSV</button></p>
		</form>
	</div>
	<?php
}
