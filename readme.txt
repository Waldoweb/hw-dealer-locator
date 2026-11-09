=== HW Dealer Locator ===
Contributors: hostetlerweb
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPL-2.0-or-later

Dealer finder with a map, a list, and a distance filter.

== Description ==

Dealers are a custom post type (hw_dealer) with their own address, contact and hours fields, geocoded on save. The finder shows all dealers on a Google map with a list beside it, searches by street address, city, state or ZIP or the visitor's location, and filters by distance. Place it with a shortcode. Updates install automatically from GitHub releases.

Settings, the importer (WP Store Locator or CSV) and the dealer list are under Dealers in WP admin. Dealer pages use a Bricks template; the dealer fields are Bricks dynamic data tags in the "HW Dealer" group, or [hw_dealer_field name="phone"] elsewhere.

Deleting is guarded: trashing asks first, only administrators can delete permanently, and WordPress never empties the dealer trash on its own.

Google needs: a browser key (Maps JavaScript API, Places API (New)), a server key (Geocoding API) and a Map ID.

== Changelog ==

= 0.2.0 =
* Dealer finder: map with clustered pins, list sorted by distance, search by place or street address, 50/100/200/any-mile distance choices, and dealer details in a card over the map.
* Dealer editor, dealer pages, and an importer from WP Store Locator or CSV that updates on re-run.
* Settings for Google keys, map ID, brand color and height (CSS variables accepted).
* Dealer names in the list use the heading font at weight 500; the theme button reset no longer overrides them.

= 0.1.0 =
* Plugin skeleton with the GitHub updater. No dealer features yet.
