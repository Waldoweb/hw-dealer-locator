=== HW Dealer Locator ===
Contributors: hostetlerweb
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.2.2
License: GPL-2.0-or-later

Dealer finder with a map, a list, and a distance filter.

== Description ==

Dealers are a custom post type (hw_dealer) with their own address, contact and hours fields, geocoded on save. The finder shows all dealers on a Google map with a list beside it, searches by street address, city, state or ZIP or the visitor's location, and filters by distance. Place it with a shortcode. Updates install automatically from GitHub releases.

Settings, the importer (WP Store Locator or CSV) and the dealer list are under Dealers in WP admin. Dealer pages use a Bricks template; the dealer fields are Bricks dynamic data tags in the "HW Dealer" group, or [hw_dealer_field name="phone"] elsewhere.

Deleting is guarded: trashing asks first, only administrators can delete permanently, and WordPress never empties the dealer trash on its own.

Google needs: a browser key (Maps JavaScript API, Places API (New)), a server key (Geocoding API) and a Map ID.

== Changelog ==

= 0.2.2 =
* Admin: the Dealers menu item sits directly under Posts.

= 0.2.1 =
* Finder: "Use my current location" in the search suggestions and a "Find the closest to you" link in the list header. While the visitor's location is used, the locate button is filled and the field shows "Your location" with the town; the header reads "within 100 mi of <place>".
* Finder fixes: a slow location or place lookup can't overwrite a newer search; the clear button stays while results are filtered; keyboard focus is kept when cards open and close; Escape in the search box or photo viewer leaves the card open; phones get photo arrows.
* Styles: buttons and links that theme resets overrode (locate, Search 200 mi, Copy address, lightbox, More about) look as designed; buttons ignore theme minimum heights; lighter selected row; tighter distance column.
* Editor: "Use the address instead" moves the pin on save; backslashes are kept; the dealer list flags address lookup problems; gallery limit is 10 everywhere.
* Importer: re-imports keep hand-placed pins, added gallery photos and fields with no meta key set, and leave trashed dealers in the trash; CSV country follows the province when there's no country column; the first import can geocode with the keys it just copied.
* Hours closing at or after midnight show as open until closing; Yukon timezone fixed; more province names recognized.
* Data changed outside the editor refreshes the finder; Settings link on the Plugins screen; deactivating removes the dealer URLs.

= 0.2.0 =
* Dealer finder: map with clustered pins, list sorted by distance, search by place or street address, 50/100/200/any-mile distance choices, and dealer details in a card over the map.
* Dealer editor, dealer pages, and an importer from WP Store Locator or CSV that updates on re-run.
* Settings for Google keys, map ID, brand color and height (CSS variables accepted).
* Dealer names in the list use the heading font at weight 500; the theme button reset no longer overrides them.

= 0.1.0 =
* Plugin skeleton with the GitHub updater. No dealer features yet.
