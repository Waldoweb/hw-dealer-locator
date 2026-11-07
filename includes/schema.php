<?php
/**
 * LocalBusiness structured data on each dealer's page. A separate JSON-LD block,
 * so it sits alongside whatever an SEO plugin outputs for the page itself.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_head',
	function () {
		if ( ! is_singular( 'hw_dealer' ) ) {
			return;
		}
		$id   = get_queried_object_id();
		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'LocalBusiness',
			'@id'      => get_permalink( $id ) . '#dealer',
			'name'     => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'url'      => hw_dealer_get( $id, 'website' ) ? hw_dealer_get( $id, 'website' ) : get_permalink( $id ),
			'address'  => array_filter(
				array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => trim( hw_dealer_get( $id, 'address' ) . ' ' . hw_dealer_get( $id, 'address2' ) ),
					'addressLocality' => hw_dealer_get( $id, 'city' ),
					'addressRegion'   => hw_dealer_get( $id, 'state' ),
					'postalCode'      => hw_dealer_get( $id, 'zip' ),
					'addressCountry'  => hw_dealer_get( $id, 'country' ),
				)
			),
		);
		if ( hw_dealer_get( $id, 'phone' ) ) {
			$data['telephone'] = hw_dealer_get( $id, 'phone' );
		}
		if ( '' !== hw_dealer_get( $id, 'lat' ) ) {
			$data['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) hw_dealer_get( $id, 'lat' ),
				'longitude' => (float) hw_dealer_get( $id, 'lng' ),
			);
		}
		$logo = (int) hw_dealer_get( $id, 'logo' );
		if ( $logo && wp_get_attachment_url( $logo ) ) {
			$data['logo'] = wp_get_attachment_url( $logo );
		}
		$photos = array_filter( array_map( 'wp_get_attachment_url', array_filter( explode( ',', (string) hw_dealer_get( $id, 'gallery' ) ) ) ) );
		if ( $photos ) {
			$data['image'] = array_values( $photos );
		}
		$hours = hw_dealer_get( $id, 'hours' );
		if ( ! hw_dealer_get( $id, 'hours_note' ) && hw_dealer_has_hours( $hours ) ) {
			$spec = array();
			foreach ( hw_dealer_days() as $key => $day ) {
				if ( ! empty( $hours[ $key ] ) ) {
					$spec[] = array(
						'@type'     => 'OpeningHoursSpecification',
						'dayOfWeek' => $day,
						'opens'     => $hours[ $key ][0],
						'closes'    => $hours[ $key ][1],
					);
				}
			}
			$data['openingHoursSpecification'] = $spec;
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
	}
);
