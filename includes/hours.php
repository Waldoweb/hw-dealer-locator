<?php
/**
 * Opening hours and dealer timezones.
 *
 * Stored as hw_dealer_hours: array( 'mon' => array( '09:00', '17:00' ), 'sun' => array(), ... ).
 * An empty array is "Closed". When no day has hours at all, the dealer has no hours
 * entered and the finder shows nothing about them (not "Closed").
 *
 * "Open now" is worked out in the dealer's own timezone, guessed from the
 * state or province and adjustable per dealer.
 */

defined( 'ABSPATH' ) || exit;

function hw_dealer_days() {
	return array(
		'mon' => 'Monday',
		'tue' => 'Tuesday',
		'wed' => 'Wednesday',
		'thu' => 'Thursday',
		'fri' => 'Friday',
		'sat' => 'Saturday',
		'sun' => 'Sunday',
	);
}

function hw_dealer_sanitize_hours( $in ) {
	$out = array();
	$in  = is_array( $in ) ? $in : array();
	foreach ( array_keys( hw_dealer_days() ) as $day ) {
		$d    = isset( $in[ $day ] ) && is_array( $in[ $day ] ) ? array_values( $in[ $day ] ) : array();
		$open = isset( $d[0] ) ? hw_dealer_clean_time( $d[0] ) : '';
		$shut = isset( $d[1] ) ? hw_dealer_clean_time( $d[1] ) : '';
		$out[ $day ] = ( $open && $shut && $open !== $shut ) ? array( $open, $shut ) : array();
	}
	return $out;
}

/** "9:00 AM", "17:30" or "5pm" => "HH:MM", or '' if unreadable. */
function hw_dealer_clean_time( $t ) {
	$t = strtolower( trim( (string) $t ) );
	if ( ! preg_match( '/^(\d{1,2})(?::(\d{2}))?\s*(am|pm|a\.m\.|p\.m\.)?$/', $t, $m ) ) {
		return '';
	}
	$h   = (int) $m[1];
	$min = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
	$ap  = isset( $m[3] ) ? $m[3][0] : '';
	if ( 'p' === $ap && $h < 12 ) {
		$h += 12;
	} elseif ( 'a' === $ap && 12 === $h ) {
		$h = 0;
	}
	if ( $h > 24 || $min > 59 ) {
		return '';
	}
	return sprintf( '%02d:%02d', $h, $min );
}

function hw_dealer_has_hours( $hours ) {
	foreach ( (array) $hours as $d ) {
		if ( ! empty( $d ) ) {
			return true;
		}
	}
	return false;
}

/** For the finder: 7 entries indexed like JS getDay() (0 = Sunday), each [openMin, closeMin] or null. */
function hw_dealer_hours_for_js( $hours ) {
	if ( ! hw_dealer_has_hours( $hours ) ) {
		return null;
	}
	$mins = function ( $t ) {
		list( $h, $m ) = array_map( 'intval', explode( ':', $t ) );
		return $h * 60 + $m;
	};
	$out = array();
	foreach ( array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ) as $day ) {
		$d     = isset( $hours[ $day ] ) ? $hours[ $day ] : array();
		$out[] = $d ? array( $mins( $d[0] ), $mins( $d[1] ) ) : null;
	}
	return $out;
}

/** "09:00" => "9 AM", "13:30" => "1:30 PM". */
function hw_dealer_format_time( $t ) {
	list( $h, $m ) = array_map( 'intval', explode( ':', $t ) );
	$ap            = ( $h >= 12 && $h < 24 ) ? 'PM' : 'AM';
	$h12           = $h % 12 ? $h % 12 : 12;
	return $m ? sprintf( '%d:%02d %s', $h12, $m, $ap ) : sprintf( '%d %s', $h12, $ap );
}

/** Hours table HTML for dealer pages (Bricks tag and shortcode). The note replaces the table when set. */
function hw_dealer_hours_html( $post_id ) {
	$note  = hw_dealer_get( $post_id, 'hours_note' );
	$hours = hw_dealer_get( $post_id, 'hours' );
	if ( $note ) {
		return '<p class="hwdl-hours-note">' . esc_html( $note ) . '</p>';
	}
	if ( ! hw_dealer_has_hours( $hours ) ) {
		return '';
	}
	$html = '<table class="hwdl-hours">';
	foreach ( hw_dealer_days() as $key => $label ) {
		$d     = isset( $hours[ $key ] ) ? $hours[ $key ] : array();
		$html .= '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( $d ? hw_dealer_format_time( $d[0] ) . ' – ' . hw_dealer_format_time( $d[1] ) : 'Closed' ) . '</td></tr>';
	}
	return $html . '</table>';
}

/** WP Store Locator hours (array( 'monday' => array( '9:00 AM,5:00 PM' ) )) => ours. Only the first range per day is kept. */
function hw_dealer_hours_from_wpsl( $wpsl ) {
	$map = array( 'monday' => 'mon', 'tuesday' => 'tue', 'wednesday' => 'wed', 'thursday' => 'thu', 'friday' => 'fri', 'saturday' => 'sat', 'sunday' => 'sun' );
	$in  = array();
	foreach ( $map as $long => $short ) {
		$ranges       = isset( $wpsl[ $long ] ) ? (array) $wpsl[ $long ] : array();
		$in[ $short ] = $ranges ? explode( ',', (string) $ranges[0] ) : array();
	}
	return hw_dealer_sanitize_hours( $in );
}

/**
 * Best-guess IANA timezone from country and state/province. States split across
 * zones get their majority zone; staff can change it per dealer.
 */
function hw_dealer_guess_timezone( $country, $state ) {
	$state = strtoupper( trim( (string) $state ) );
	$zones = array(
		'America/New_York'    => 'CT DE DC FL GA IN KY ME MD MA MI NH NJ NY NC OH PA RI SC VT VA WV ON QC',
		'America/Chicago'     => 'AL AR IL IA KS LA MN MS MO NE ND OK SD TN TX WI MB',
		'America/Denver'      => 'CO ID MT NM UT WY',
		'America/Phoenix'     => 'AZ',
		'America/Los_Angeles' => 'CA NV OR WA',
		'America/Anchorage'   => 'AK',
		'Pacific/Honolulu'    => 'HI',
		'America/Edmonton'    => 'AB NT',
		'America/Regina'      => 'SK',
		'America/Vancouver'   => 'BC YT',
		'America/Halifax'     => 'NS NB PE',
		'America/St_Johns'    => 'NL',
		'America/Iqaluit'     => 'NU',
	);
	// California is "CA" the state; Canada as a country is handled by the province list.
	foreach ( $zones as $tz => $codes ) {
		if ( in_array( $state, explode( ' ', $codes ), true ) ) {
			if ( 'CA' === $country && in_array( $state, array( 'CA', 'NV', 'OR', 'WA' ), true ) ) {
				continue;
			}
			return $tz;
		}
	}
	return wp_timezone_string();
}

function hw_dealer_timezone( $post_id ) {
	$tz = hw_dealer_get( $post_id, 'timezone' );
	return $tz ? $tz : hw_dealer_guess_timezone( hw_dealer_get( $post_id, 'country' ), hw_dealer_get( $post_id, 'state' ) );
}
