<?php
/**
 * Dealer fields for dealer-page templates.
 *
 * Bricks: dynamic data tags in the "HW Dealer" group, e.g. {hw_dealer_phone}.
 * {hw_dealer_logo} and {hw_dealer_gallery} work in Image and Image Gallery elements.
 * Anywhere else: [hw_dealer_field name="phone"].
 */

defined( 'ABSPATH' ) || exit;

function hw_dealer_tags() {
	return array(
		'address'        => 'Address (one line)',
		'street'         => 'Street address',
		'city'           => 'City',
		'state'          => 'State / province',
		'zip'            => 'ZIP / postal code',
		'phone'          => 'Phone',
		'phone_link'     => 'Phone (tel: link URL)',
		'email'          => 'Email',
		'website'        => 'Website URL',
		'contact_name'   => 'Contact name',
		'hours'          => 'Hours table (or the hours note)',
		'notes'          => 'Notes',
		'directions_url' => 'Directions URL (Google Maps)',
		'finder_url'     => 'This dealer in the finder (needs the finder page URL as {hw_dealer_finder_url:/find-a-dealer/})',
		'outstanding'    => 'Outstanding label (empty when not)',
		'display'        => 'Display chip text (empty when not)',
		'logo'           => 'Logo (image)',
		'gallery'        => 'Photos (gallery)',
		'lat'            => 'Latitude',
		'lng'            => 'Longitude',
	);
}

/**
 * @param string $field  Tag name without the hw_dealer_ prefix.
 * @param string $arg    Optional ":arg" from the tag.
 * @return string|int[]  Attachment IDs for logo and gallery, text otherwise.
 */
function hw_dealer_field_value( $post_id, $field, $arg = '', $context = 'text' ) {
	if ( 'hw_dealer' !== get_post_type( $post_id ) ) {
		return '';
	}
	switch ( $field ) {
		case 'address':
			return hw_dealer_address_line( $post_id );
		case 'street':
			return trim( hw_dealer_get( $post_id, 'address' ) . ' ' . hw_dealer_get( $post_id, 'address2' ) );
		case 'phone_link':
			$p = preg_replace( '/[^\d+]/', '', hw_dealer_get( $post_id, 'phone' ) );
			return $p ? 'tel:' . $p : '';
		case 'hours':
			return hw_dealer_hours_html( $post_id );
		case 'directions_url':
			$a = hw_dealer_address_line( $post_id );
			return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $a ? $a : hw_dealer_get( $post_id, 'lat' ) . ',' . hw_dealer_get( $post_id, 'lng' ) );
		case 'finder_url':
			return $arg ? add_query_arg( 'dealer', $post_id, home_url( $arg ) ) : '';
		case 'outstanding':
			return hw_dealer_get( $post_id, 'outstanding' ) ? hw_dealer_setting( 'label_outstanding' ) : '';
		case 'display':
			return hw_dealer_get( $post_id, 'on_display' ) ? hw_dealer_setting( 'label_display' ) : '';
		case 'logo':
			$id = (int) hw_dealer_get( $post_id, 'logo' );
			if ( 'image' === $context ) {
				return $id ? array( $id ) : array();
			}
			return $id ? (string) wp_get_attachment_url( $id ) : '';
		case 'gallery':
			$ids = array_map( 'intval', array_filter( explode( ',', (string) hw_dealer_get( $post_id, 'gallery' ) ) ) );
			return 'image' === $context ? $ids : implode( ',', $ids );
		default:
			if ( ! array_key_exists( $field, hw_dealer_fields() ) || 'hours' === $field ) {
				return '';
			}
			return (string) hw_dealer_get( $post_id, $field );
	}
}

add_shortcode(
	'hw_dealer_field',
	function ( $atts ) {
		$atts = shortcode_atts( array( 'name' => '', 'arg' => '' ), $atts, 'hw_dealer_field' );
		$v    = hw_dealer_field_value( get_the_ID(), sanitize_key( $atts['name'] ), $atts['arg'] );
		return 'hours' === $atts['name'] ? $v : esc_html( $v );
	}
);

add_filter(
	'bricks/dynamic_tags_list',
	function ( $tags ) {
		foreach ( hw_dealer_tags() as $key => $label ) {
			$tags[] = array(
				'name'  => '{hw_dealer_' . $key . '}',
				'label' => $label,
				'group' => 'HW Dealer',
			);
		}
		return $tags;
	}
);

/** "{hw_dealer_finder_url:/find/}" => array( 'finder_url', '/find/' ), or null if not ours. */
function hw_dealer_parse_tag( $tag ) {
	if ( ! is_string( $tag ) || ! preg_match( '/^\{?hw_dealer_([a-z_]+)(?::([^}]*))?\}?$/', $tag, $m ) ) {
		return null;
	}
	return array_key_exists( $m[1], hw_dealer_tags() ) ? array( $m[1], isset( $m[2] ) ? $m[2] : '' ) : null;
}

add_filter(
	'bricks/dynamic_data/render_tag',
	function ( $tag, $post, $context = 'text' ) {
		$parsed = hw_dealer_parse_tag( $tag );
		if ( ! $parsed ) {
			return $tag;
		}
		$post_id = is_object( $post ) ? $post->ID : get_the_ID();
		return hw_dealer_field_value( $post_id, $parsed[0], $parsed[1], $context );
	},
	20,
	3
);

$hw_dealer_render_content = function ( $content, $post, $context = 'text' ) {
	if ( ! is_string( $content ) || false === strpos( $content, '{hw_dealer_' ) ) {
		return $content;
	}
	$post_id = is_object( $post ) ? $post->ID : get_the_ID();
	return preg_replace_callback(
		'/\{hw_dealer_([a-z_]+)(?::([^}]*))?\}/',
		function ( $m ) use ( $post_id ) {
			if ( ! array_key_exists( $m[1], hw_dealer_tags() ) ) {
				return $m[0];
			}
			$v = hw_dealer_field_value( $post_id, $m[1], isset( $m[2] ) ? $m[2] : '' );
			return 'hours' === $m[1] ? $v : esc_html( $v );
		},
		$content
	);
};
add_filter( 'bricks/dynamic_data/render_content', $hw_dealer_render_content, 20, 3 );
add_filter( 'bricks/frontend/render_data', $hw_dealer_render_content, 20, 2 );
