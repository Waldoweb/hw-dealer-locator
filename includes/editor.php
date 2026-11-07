<?php
/**
 * Dealer editor: address with a draggable map pin, contact details, hours,
 * flags, logo and photo gallery, all in meta boxes on the classic editor.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'add_meta_boxes_hw_dealer',
	function () {
		add_meta_box( 'hw-dealer-location', 'Location', 'hw_dealer_box_location', 'hw_dealer', 'normal', 'high' );
		add_meta_box( 'hw-dealer-contact', 'Contact', 'hw_dealer_box_contact', 'hw_dealer', 'normal', 'high' );
		add_meta_box( 'hw-dealer-hours', 'Hours', 'hw_dealer_box_hours', 'hw_dealer', 'normal', 'default' );
		add_meta_box( 'hw-dealer-photos', 'Photos', 'hw_dealer_box_photos', 'hw_dealer', 'normal', 'default' );
		add_meta_box( 'hw-dealer-flags', 'Shown as', 'hw_dealer_box_flags', 'hw_dealer', 'side', 'default' );
		add_meta_box( 'hw-dealer-logo', 'Logo', 'hw_dealer_box_logo', 'hw_dealer', 'side', 'default' );
	}
);

// The editor itself holds "About this dealer" for the dealer's page.
add_action(
	'edit_form_after_title',
	function ( $post ) {
		if ( 'hw_dealer' === $post->post_type ) {
			echo '<h2 style="padding:16px 0 4px;font-size:14px;font-weight:600">About this dealer <span style="font-weight:400;color:#646970">(shown on the dealer\'s page)</span></h2>';
		}
	}
);

function hw_dealer_input( $post_id, $field, $label, $attrs = '' ) {
	printf(
		'<p class="hwd-field"><label for="hwd-%1$s">%2$s</label><input type="text" id="hwd-%1$s" name="hw_dealer[%1$s]" value="%3$s" %4$s></p>',
		esc_attr( $field ),
		esc_html( $label ),
		esc_attr( hw_dealer_get( $post_id, $field ) ),
		$attrs // phpcs:ignore WordPress.Security.EscapeOutput -- fixed strings from this file.
	);
}

function hw_dealer_box_location( $post ) {
	wp_nonce_field( 'hw_dealer_save', 'hw_dealer_nonce' );
	$id      = $post->ID;
	$err     = hw_dealer_get( $id, 'geocode_error' );
	$country = hw_dealer_get( $id, 'country' );
	$country = $country ? $country : strtoupper( (string) current( (array) hw_dealer_setting( 'countries' ) ) );
	if ( $err ) {
		echo '<div class="notice notice-warning inline"><p><strong>Map pin:</strong> ' . esc_html( $err ) . '</p></div>';
	}
	echo '<div class="hwd-grid">';
	hw_dealer_input( $id, 'address', 'Street address', 'class="hwd-wide" autocomplete="off"' );
	hw_dealer_input( $id, 'address2', 'Address line 2', 'class="hwd-wide"' );
	hw_dealer_input( $id, 'city', 'City' );
	hw_dealer_input( $id, 'state', 'State / province', 'maxlength="2" style="text-transform:uppercase" placeholder="OH"' );
	hw_dealer_input( $id, 'zip', 'ZIP / postal code' );
	echo '<p class="hwd-field"><label for="hwd-country">Country</label><select id="hwd-country" name="hw_dealer[country]">';
	foreach ( array( 'US' => 'United States', 'CA' => 'Canada' ) as $code => $label ) {
		printf( '<option value="%s" %s>%s</option>', esc_attr( $code ), selected( $country, $code, false ), esc_html( $label ) );
	}
	echo '</select></p></div>';

	$manual = hw_dealer_get( $id, 'coords_manual' );
	?>
	<div class="hwd-map-wrap">
		<div id="hwd-map" class="hwd-map" aria-label="Dealer location map"></div>
		<p class="hwd-map-help">
			<span class="hwd-manual-on" <?php echo $manual ? '' : 'hidden'; ?>>Pin placed by hand. Address changes won't move it. <button type="button" class="button-link" id="hwd-reset-pin">Use the address instead</button></span>
			<span class="hwd-manual-off" <?php echo $manual ? 'hidden' : ''; ?>>The pin comes from the address when you save. If it's in the wrong spot, drag it.</span>
		</p>
		<input type="hidden" id="hwd-lat" name="hw_dealer[lat]" value="<?php echo esc_attr( hw_dealer_get( $id, 'lat' ) ); ?>">
		<input type="hidden" id="hwd-lng" name="hw_dealer[lng]" value="<?php echo esc_attr( hw_dealer_get( $id, 'lng' ) ); ?>">
		<input type="hidden" id="hwd-manual" name="hw_dealer[coords_manual]" value="<?php echo $manual ? '1' : ''; ?>">
	</div>
	<details class="hwd-advanced">
		<summary>Time zone</summary>
		<p class="hwd-field"><label for="hwd-timezone">Time zone for "Open now"</label>
			<select id="hwd-timezone" name="hw_dealer[timezone]">
				<option value="">Automatic (<?php echo esc_html( hw_dealer_guess_timezone( $country, hw_dealer_get( $id, 'state' ) ) ); ?>)</option>
				<?php
				foreach ( array( 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Phoenix', 'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu', 'America/Halifax', 'America/St_Johns', 'America/Regina', 'America/Edmonton', 'America/Vancouver' ) as $tz ) {
					printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $tz ), selected( hw_dealer_get( $id, 'timezone' ), $tz, false ) );
				}
				?>
			</select>
		</p>
	</details>
	<?php
}

function hw_dealer_box_contact( $post ) {
	$id = $post->ID;
	echo '<div class="hwd-grid">';
	hw_dealer_input( $id, 'phone', 'Phone', 'type="tel"' );
	hw_dealer_input( $id, 'website', 'Website', 'placeholder="https://"' );
	hw_dealer_input( $id, 'email', 'Email (not shown in the finder)' );
	hw_dealer_input( $id, 'contact_name', 'Contact name' );
	echo '</div>';
	printf(
		'<p class="hwd-field"><label for="hwd-notes">Notes</label><textarea id="hwd-notes" name="hw_dealer[notes]" rows="2" class="hwd-wide">%s</textarea><span class="description">A line or two shown in the finder\'s dealer popup, e.g. "Ask for Eli."</span></p>',
		esc_textarea( hw_dealer_get( $id, 'notes' ) )
	);
}

function hw_dealer_box_hours( $post ) {
	$hours = hw_dealer_get( $post->ID, 'hours' );
	$hours = is_array( $hours ) ? $hours : array();
	echo '<p class="description">Leave every day closed if you don\'t know the hours. The finder then shows no hours at all, rather than "Closed".</p>';
	echo '<table class="hwd-hours"><tbody>';
	foreach ( hw_dealer_days() as $key => $label ) {
		$d    = isset( $hours[ $key ] ) ? $hours[ $key ] : array();
		$open = (bool) $d;
		printf(
			'<tr data-day="%1$s"><th>%2$s</th><td><label><input type="checkbox" class="hwd-open" %3$s> Open</label></td><td class="hwd-times" %6$s><input type="time" name="hw_dealer[hours][%1$s][0]" value="%4$s"> – <input type="time" name="hw_dealer[hours][%1$s][1]" value="%5$s"></td><td class="hwd-closed" %7$s>Closed</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			checked( $open, true, false ),
			esc_attr( $open ? $d[0] : '09:00' ),
			esc_attr( $open ? $d[1] : '17:00' ),
			$open ? '' : 'hidden',
			$open ? 'hidden' : ''
		);
	}
	echo '</tbody></table>';
	echo '<p><button type="button" class="button" id="hwd-copy-mon">Copy Monday to Tuesday–Friday</button></p>';
	printf(
		'<p class="hwd-field"><label for="hwd-hours_note">Instead of hours, show this</label><input type="text" id="hwd-hours_note" name="hw_dealer[hours_note]" value="%s" class="hwd-wide" placeholder="By appointment"><span class="description">Replaces the hours table and the open/closed status. Leave empty to show the hours above.</span></p>',
		esc_attr( hw_dealer_get( $post->ID, 'hours_note' ) )
	);
}

function hw_dealer_box_photos( $post ) {
	$ids = array_filter( explode( ',', (string) hw_dealer_get( $post->ID, 'gallery' ) ) );
	echo '<p class="description">Up to 10 photos, shown in the finder\'s dealer popup. Drag to reorder.</p>';
	echo '<ul class="hwd-gallery" id="hwd-gallery">';
	foreach ( $ids as $aid ) {
		$img = wp_get_attachment_image( $aid, 'thumbnail' );
		if ( $img ) {
			printf( '<li data-id="%d">%s<button type="button" class="hwd-remove" aria-label="Remove photo">×</button></li>', (int) $aid, $img ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	echo '</ul>';
	printf( '<input type="hidden" id="hwd-gallery-ids" name="hw_dealer[gallery]" value="%s">', esc_attr( implode( ',', $ids ) ) );
	echo '<p><button type="button" class="button" id="hwd-add-photos">Add photos</button></p>';
}

function hw_dealer_box_logo( $post ) {
	$aid = (int) hw_dealer_get( $post->ID, 'logo' );
	echo '<div id="hwd-logo-preview">' . ( $aid ? wp_get_attachment_image( $aid, 'medium', false, array( 'style' => 'max-width:100%;height:auto' ) ) : '' ) . '</div>';
	printf( '<input type="hidden" id="hwd-logo" name="hw_dealer[logo]" value="%s">', $aid ? (int) $aid : '' );
	echo '<p><button type="button" class="button" id="hwd-pick-logo">' . ( $aid ? 'Change logo' : 'Choose logo' ) . '</button> <button type="button" class="button-link" id="hwd-clear-logo" ' . ( $aid ? '' : 'hidden' ) . '>Remove</button></p>';
	echo '<p class="description">Shown on the dealer\'s page, not in the finder.</p>';
}

function hw_dealer_box_flags( $post ) {
	$s = hw_dealer_settings();
	printf(
		'<p><label><input type="checkbox" name="hw_dealer[outstanding]" value="1" %s> ★ %s</label></p>',
		checked( (bool) hw_dealer_get( $post->ID, 'outstanding' ), true, false ),
		esc_html( $s['label_outstanding'] )
	);
	printf(
		'<p><label><input type="checkbox" name="hw_dealer[on_display]" value="1" %s> %s</label></p>',
		checked( (bool) hw_dealer_get( $post->ID, 'on_display' ), true, false ),
		esc_html( $s['label_display'] )
	);
}

add_action(
	'save_post_hw_dealer',
	function ( $post_id ) {
		if ( ! isset( $_POST['hw_dealer_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['hw_dealer_nonce'] ), 'hw_dealer_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['hw_dealer'] ) && is_array( $_POST['hw_dealer'] ) ? wp_unslash( $_POST['hw_dealer'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below.

		// Unchecked day boxes disable their time inputs, so they aren't posted: treat as closed.
		if ( isset( $in['hours'] ) || isset( $in['hours_note'] ) ) {
			$in['hours'] = isset( $in['hours'] ) ? $in['hours'] : array();
		}
		foreach ( array( 'outstanding', 'on_display', 'coords_manual' ) as $flag ) {
			$in[ $flag ] = ! empty( $in[ $flag ] );
		}
		if ( isset( $in['state'] ) ) {
			$in['state'] = strtoupper( $in['state'] );
		}

		$skip = array( 'geocode_error', 'source' );
		foreach ( hw_dealer_fields() as $field => $type ) {
			if ( in_array( $field, $skip, true ) || ! array_key_exists( $field, $in ) ) {
				continue;
			}
			update_post_meta( $post_id, 'hw_dealer_' . $field, hw_dealer_sanitize_field( $type, $in[ $field ] ) );
		}
		if ( ! empty( $in['coords_manual'] ) ) {
			delete_post_meta( $post_id, 'hw_dealer_geocode_error' );
		}
		hw_dealer_maybe_geocode( $post_id );
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'hw_dealer' !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_style( 'hw-dealer-admin', plugins_url( 'assets/admin.css', HW_DEALER_FILE ), array(), HW_DEALER_VERSION );
		wp_enqueue_script( 'hw-dealer-admin', plugins_url( 'assets/admin.js', HW_DEALER_FILE ), array( 'jquery', 'jquery-ui-sortable' ), HW_DEALER_VERSION, true );
		$s = hw_dealer_settings();
		wp_localize_script(
			'hw-dealer-admin',
			'hwDealerAdmin',
			array(
				'key'    => $s['browser_key'],
				'mapId'  => $s['map_id'] ? $s['map_id'] : 'DEMO_MAP_ID',
				'center' => array( (float) $s['center_lat'], (float) $s['center_lng'] ),
				'zoom'   => (int) $s['zoom'],
			)
		);
	}
);
