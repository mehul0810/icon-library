=== Icon Library ===
Contributors: mehul0810
Tags: icons, blocks, svg, editor
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enable curated SVG icon collections for the native WordPress Icon block.

== Description ==

Icon Library lets site owners activate curated SVG icon collections and makes
enabled icons available through the native WordPress Icon block.

Version 1.0 bundles Heroicons 24 Solid and supports strictly validated custom
SVG icons stored locally by the plugin. It does not enable SVG uploads in the
Media Library, make remote requests, use icon fonts, or add a competing block.

== Installation ==

1. Upload the plugin to the `wp-content/plugins/icon-library` directory.
2. Activate Icon Library in WordPress.
3. Open Appearance > Icons to manage collections.

== Frequently Asked Questions ==

= Does this add a custom icon block? =

No. Icon Library integrates with the native WordPress `core/icon` block.

= Can administrators add custom SVG icons? =

Yes. Administrators can add SVG files through Appearance > Icons > Custom
Icons. Files are limited to Core-compatible path and polygon geometry, are
validated before storage, and never enter the Media Library.

= What happens when an icon is deleted or the plugin is uninstalled? =

The Icon block stores the registered icon name rather than an SVG copy. Existing
blocks that reference a deleted custom icon, or any icon after plugin uninstall,
will no longer render that icon. Uninstall also removes plugin-owned custom icon
files and metadata.

== Changelog ==

= 1.0.0 =

* Integrate collections with the WordPress 7.1 public Icon API.
* Bundle 324 Heroicons 24 Solid icons with deterministic manifests.
* Add Appearance > Icons collection management and searchable previews.
* Add strict local custom SVG icon management.
* Preserve saved icons when bundled collections are disabled.
* Add reproducible validation and release packaging.
