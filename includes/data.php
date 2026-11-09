<?php
/**
 * The finder's data: every published dealer with a pin, as one array sent to the
 * browser, which sorts by distance itself. About 100 dealers is a few dozen KB.
 * Cached in a transient that's cleared whenever a dealer or the settings change.
 */

defined( 'ABSPATH' ) || exit;

function hw_dealer_flush_cache() {
	delete_transient( 'hw_dealer_payload' );
}

add_action( 'save_post_hw_dealer', 'hw_dealer_flush_cache', 20 );
add_action(
	'transition_post_status',
	function ( $new, $old, $post ) {
		if ( 'hw_dealer' === $post->post_type ) {
			hw_dealer_flush_cache();
		}
	},
	10,
	3
);
add_action(
	'deleted_post',
	function ( $post_id, $post ) {
		if ( $post && 'hw_dealer' === $post->post_type ) {
			hw_dealer_flush_cache();
		}
	},
	10,
	2
);
// Photo alt text or crops can change too, and a deleted photo must drop out.
add_action( 'edit_attachment', 'hw_dealer_flush_cache' );
add_action( 'delete_attachment', 'hw_dealer_flush_cache' );
// Meta written outside the editor (imports, WP-CLI, scripts).
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hw_dealer_hook ) {
	add_action(
		$hw_dealer_hook,
		function ( $meta_id, $post_id, $key ) {
			if ( 0 === strpos( (string) $key, 'hw_dealer_' ) && 'hw_dealer' === get_post_type( $post_id ) ) {
				hw_dealer_flush_cache();
			}
		},
		10,
		3
	);
}
unset( $hw_dealer_hook );

function hw_dealer_payload() {
	$cached = get_transient( 'hw_dealer_payload' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$ids = get_posts(
		array(
			'post_type'      => 'hw_dealer',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		)
	);
	update_meta_cache( 'post', $ids );

	$out = array();
	foreach ( $ids as $id ) {
		$lat = hw_dealer_get( $id, 'lat' );
		$lng = hw_dealer_get( $id, 'lng' );
		if ( '' === $lat || '' === $lng ) {
			continue;
		}
		$note    = hw_dealer_get( $id, 'hours_note' );
		$gallery = array();
		foreach ( array_filter( explode( ',', (string) hw_dealer_get( $id, 'gallery' ) ) ) as $aid ) {
			$thumb = wp_get_attachment_image_src( $aid, 'medium' );
			$full  = wp_get_attachment_image_src( $aid, 'large' );
			if ( $thumb && $full ) {
				$gallery[] = array(
					't'   => $thumb[0],
					'f'   => $full[0],
					'w'   => $full[1],
					'h'   => $full[2],
					'alt' => (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ),
				);
			}
		}
		$out[] = array_filter(
			array(
				'id'      => $id,
				'name'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'url'     => get_permalink( $id ),
				'lat'     => (float) $lat,
				'lng'     => (float) $lng,
				'addr'    => trim( hw_dealer_get( $id, 'address' ) . ( hw_dealer_get( $id, 'address2' ) ? ', ' . hw_dealer_get( $id, 'address2' ) : '' ) ),
				'city'    => hw_dealer_get( $id, 'city' ),
				'st'      => hw_dealer_get( $id, 'state' ),
				'zip'     => hw_dealer_get( $id, 'zip' ),
				'phone'   => hw_dealer_get( $id, 'phone' ),
				'web'     => hw_dealer_get( $id, 'website' ),
				'notes'   => hw_dealer_get( $id, 'notes' ),
				'top'     => (bool) hw_dealer_get( $id, 'outstanding' ),
				'show'    => (bool) hw_dealer_get( $id, 'on_display' ),
				'note'    => $note,
				'hours'   => $note ? null : hw_dealer_hours_for_js( hw_dealer_get( $id, 'hours' ) ),
				'tz'      => hw_dealer_timezone( $id ),
				'photos'  => $gallery,
			),
			function ( $v ) {
				return null !== $v && '' !== $v && array() !== $v && false !== $v;
			}
		);
	}

	set_transient( 'hw_dealer_payload', $out, DAY_IN_SECONDS );
	return $out;
}
