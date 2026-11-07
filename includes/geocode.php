<?php
/**
 * Geocoding with the server key, so staff never type coordinates.
 *
 * A dealer is geocoded when it's saved without coordinates, or when its address
 * changes, unless its pin was placed by hand (hw_dealer_coords_manual). Failures
 * are stored in hw_dealer_geocode_error and shown in the editor and dealer list.
 */

defined( 'ABSPATH' ) || exit;

function hw_dealer_address_line( $post_id ) {
	$parts = array(
		hw_dealer_get( $post_id, 'address' ),
		hw_dealer_get( $post_id, 'address2' ),
		hw_dealer_get( $post_id, 'city' ),
		trim( hw_dealer_get( $post_id, 'state' ) . ' ' . hw_dealer_get( $post_id, 'zip' ) ),
	);
	return implode( ', ', array_filter( array_map( 'trim', $parts ) ) );
}

/**
 * @return array{lat: float, lng: float}|WP_Error
 */
function hw_dealer_geocode( $address, $country ) {
	$key = hw_dealer_setting( 'server_key' );
	if ( ! $key ) {
		return new WP_Error( 'no_key', 'No Google server key in Dealers > Settings' );
	}
	if ( '' === trim( $address ) ) {
		return new WP_Error( 'no_address', 'No address' );
	}
	$url = add_query_arg(
		array(
			'address'    => rawurlencode( $address ),
			'components' => 'country:' . rawurlencode( $country ? $country : 'US' ),
			'key'        => rawurlencode( $key ),
		),
		'https://maps.googleapis.com/maps/api/geocode/json'
	);
	$res = wp_remote_get( $url, array( 'timeout' => 10 ) );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'http', 'Google could not be reached: ' . $res->get_error_message() );
	}
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	$status = isset( $body['status'] ) ? $body['status'] : 'UNKNOWN';
	if ( 'OK' !== $status || empty( $body['results'][0]['geometry']['location'] ) ) {
		$msg = 'ZERO_RESULTS' === $status ? 'Google could not find this address' : 'Google said ' . $status;
		if ( ! empty( $body['error_message'] ) ) {
			$msg .= ': ' . $body['error_message'];
		}
		return new WP_Error( 'geocode', $msg );
	}
	$r   = $body['results'][0];
	$loc = $r['geometry']['location'];
	return array(
		'lat'     => (float) $loc['lat'],
		'lng'     => (float) $loc['lng'],
		// Only a street-level match is trustworthy; a city or ZIP centroid gets a warning.
		'approx'  => ! empty( $r['partial_match'] ) || 'APPROXIMATE' === $r['geometry']['location_type'],
	);
}

/** Geocode if needed. Call after the address fields are saved. */
function hw_dealer_maybe_geocode( $post_id, $force = false ) {
	if ( hw_dealer_get( $post_id, 'coords_manual' ) && ! $force ) {
		return;
	}
	$address = hw_dealer_address_line( $post_id );
	$hash    = md5( $address . '|' . hw_dealer_get( $post_id, 'country' ) );
	$has     = '' !== hw_dealer_get( $post_id, 'lat' );
	if ( ! $force && $has && get_post_meta( $post_id, 'hw_dealer_geocoded_for', true ) === $hash ) {
		return;
	}

	$r = hw_dealer_geocode( $address, hw_dealer_get( $post_id, 'country' ) );
	if ( is_wp_error( $r ) ) {
		update_post_meta( $post_id, 'hw_dealer_geocode_error', $r->get_error_message() );
		return;
	}
	update_post_meta( $post_id, 'hw_dealer_lat', (string) round( $r['lat'], 7 ) );
	update_post_meta( $post_id, 'hw_dealer_lng', (string) round( $r['lng'], 7 ) );
	update_post_meta( $post_id, 'hw_dealer_geocoded_for', $hash );
	update_post_meta( $post_id, 'hw_dealer_geocode_error', $r['approx'] ? 'Approximate match. Check the pin and drag it if needed.' : '' );
}
