<?php
/**
 * The hw_dealer post type and its fields.
 *
 * Fields are plain post meta named hw_dealer_<field> (no underscore, so Bricks'
 * own custom-field tags and WP All Import can read them too). The post title is
 * the dealer's name and the post content is the "About this dealer" text shown
 * on the dealer's page.
 */

defined( 'ABSPATH' ) || exit;

/** Field name => sanitize type. */
function hw_dealer_fields() {
	return array(
		'address'        => 'text',
		'address2'       => 'text',
		'city'           => 'text',
		'state'          => 'text',
		'zip'            => 'text',
		'country'        => 'country',
		'lat'            => 'coord',
		'lng'            => 'coord',
		'coords_manual'  => 'bool',
		'geocode_error'  => 'text',
		'timezone'       => 'timezone',
		'phone'          => 'text',
		'email'          => 'email',
		'website'        => 'url',
		'contact_name'   => 'text',
		'hours'          => 'hours',
		'hours_note'     => 'text',
		'outstanding'    => 'bool',
		'on_display'     => 'bool',
		'logo'           => 'int',
		'gallery'        => 'ids',
		'notes'          => 'textarea',
		'source'         => 'text',
	);
}

function hw_dealer_get( $post_id, $field ) {
	return get_post_meta( $post_id, 'hw_dealer_' . $field, true );
}

function hw_dealer_sanitize_field( $type, $v ) {
	switch ( $type ) {
		case 'bool':
			return $v ? '1' : '';
		case 'int':
			return $v ? (string) absint( $v ) : '';
		case 'ids':
			$ids = array_filter( array_map( 'absint', is_array( $v ) ? $v : explode( ',', (string) $v ) ) );
			return implode( ',', array_slice( array_unique( $ids ), 0, 20 ) );
		case 'coord':
			return is_numeric( $v ) ? (string) round( (float) $v, 7 ) : '';
		case 'country':
			$v = strtoupper( trim( (string) $v ) );
			return in_array( $v, array( 'US', 'CA' ), true ) ? $v : 'US';
		case 'email':
			return sanitize_email( $v );
		case 'url':
			$v = trim( (string) $v );
			if ( $v && ! preg_match( '#^https?://#i', $v ) ) {
				$v = 'https://' . $v;
			}
			return esc_url_raw( $v );
		case 'textarea':
			return sanitize_textarea_field( $v );
		case 'timezone':
			return in_array( $v, timezone_identifiers_list(), true ) ? $v : '';
		case 'hours':
			return hw_dealer_sanitize_hours( $v );
		default:
			return sanitize_text_field( (string) $v );
	}
}

function hw_dealer_register_post_type() {
	register_post_type(
		'hw_dealer',
		array(
			'labels'          => array(
				'name'               => 'Dealers',
				'singular_name'      => 'Dealer',
				'add_new'            => 'Add dealer',
				'add_new_item'       => 'Add dealer',
				'edit_item'          => 'Edit dealer',
				'new_item'           => 'New dealer',
				'view_item'          => 'View dealer',
				'search_items'       => 'Search dealers',
				'not_found'          => 'No dealers found',
				'not_found_in_trash' => 'No dealers in the trash',
				'all_items'          => 'All dealers',
				'menu_name'          => 'Dealers',
			),
			'public'          => true,
			'has_archive'     => false,
			'menu_icon'       => 'dashicons-location-alt',
			'menu_position'   => 26,
			'supports'        => array( 'title', 'editor', 'revisions' ),
			'rewrite'         => array(
				'slug'       => hw_dealer_setting( 'slug' ),
				'with_front' => false,
			),
			'show_in_rest'    => false, // Classic editor: the fields are a meta box.
			'capability_type' => 'post',
		)
	);
}
add_action( 'init', 'hw_dealer_register_post_type' );

add_action(
	'init',
	function () {
		if ( get_option( 'hw_dealer_flush_rewrites' ) ) {
			delete_option( 'hw_dealer_flush_rewrites' );
			flush_rewrite_rules();
		}
	},
	99
);

add_filter(
	'enter_title_here',
	function ( $text, $post ) {
		return 'hw_dealer' === $post->post_type ? 'Dealer name' : $text;
	},
	10,
	2
);

// Admin list columns.
add_filter(
	'manage_hw_dealer_posts_columns',
	function ( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['hw_location'] = 'Location';
				$new['hw_flags']    = 'Shown as';
				$new['hw_pin']      = 'Map pin';
			}
		}
		unset( $new['date'] );
		return $new;
	}
);

add_action(
	'manage_hw_dealer_posts_custom_column',
	function ( $col, $post_id ) {
		if ( 'hw_location' === $col ) {
			echo esc_html( trim( hw_dealer_get( $post_id, 'city' ) . ', ' . hw_dealer_get( $post_id, 'state' ), ', ' ) );
		} elseif ( 'hw_flags' === $col ) {
			$flags = array();
			if ( hw_dealer_get( $post_id, 'outstanding' ) ) {
				$flags[] = '★ ' . hw_dealer_setting( 'label_outstanding' );
			}
			if ( hw_dealer_get( $post_id, 'on_display' ) ) {
				$flags[] = hw_dealer_setting( 'label_display' );
			}
			echo esc_html( implode( ' · ', $flags ) );
		} elseif ( 'hw_pin' === $col ) {
			$err = hw_dealer_get( $post_id, 'geocode_error' );
			if ( '' === hw_dealer_get( $post_id, 'lat' ) ) {
				echo '<span style="color:#b32d2e">⚠ Not on the map' . ( $err ? ': ' . esc_html( $err ) : '' ) . '</span>';
			} elseif ( hw_dealer_get( $post_id, 'coords_manual' ) ) {
				echo 'Placed by hand';
			} else {
				echo 'From address';
			}
		}
	},
	10,
	2
);
