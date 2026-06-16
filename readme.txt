=== Icon Library ===
Contributors: mehul0810
Tags: icons, blocks, svg, editor
Requires at least: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enable curated SVG icon collections for the native WordPress Icon block.

== Description ==

Icon Library lets site owners activate curated SVG icon collections and makes
enabled icons available through the native WordPress Icon block.

The first build bundles Heroicons and does not support arbitrary SVG uploads,
remote marketplaces, icon fonts, or a competing custom icon block.

== Installation ==

1. Upload the plugin to the `wp-content/plugins/icon-library` directory.
2. Activate Icon Library in WordPress.
3. Open Appearance > Icons to manage collections.

== Frequently Asked Questions ==

= Does this add a custom icon block? =

No. Icon Library integrates with the native WordPress `core/icon` block.

= Can users upload SVG files? =

No. Version 0.1 only supports curated bundled collections.

== Changelog ==

= 0.1.0 =

* Initial plugin foundation.
* Add bundled Heroicons collection.
* Add collection activation UI.
* Add REST endpoints for collections and icon discovery.
* Add guarded native Icon block registration adapter.
