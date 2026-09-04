=== Icon Library ===
Contributors: mehul0810
Tags: icons, blocks, svg, editor
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enable curated SVG icon libraries for the native WordPress Icon block.

== Description ==

Icon Library lets site owners activate curated SVG icon libraries and makes
enabled icons available through the native WordPress Icon block.

No curated library is installed by default. Site owners choose libraries from
Appearance > Icons > Install Library.

Version 1.0 bundles Heroicons Outline and Solid, Bootstrap Icons in Default and
Filled variants, and Font Awesome Free in its three Free styles: Solid, Regular,
and Brands. Font Awesome's official category labels are included in the browser
for all categories represented by the bundled Free icons.
The Heroicons picker uses style variants rather than size variants because the
Core Icon block controls rendered width. Outline is experimental and disabled
by default because WordPress 7.1 strips its stroke geometry. Icon Library marks
these SVGs before Core sanitizes them and restores the known stroke presentation
with a scoped editor/frontend stylesheet. The plugin also supports strictly
validated custom SVG icons stored locally by the plugin. No curated library is
installed by default, and libraries can be uninstalled without changing icon
markup already saved in posts. The plugin does not enable SVG uploads in the
Media Library, make remote requests, use icon fonts, or add a competing block.

== Installation ==

1. Upload the plugin to the `wp-content/plugins/icon-library` directory.
2. Activate Icon Library in WordPress.
3. Open Appearance > Icons > Install Library to choose a library.

== Frequently Asked Questions ==

= Does this add a custom icon block? =

No. Icon Library integrates with the native WordPress `core/icon` block.

= Can administrators add custom SVG icons? =

Yes. Administrators can add SVG files through Appearance > Icons > Custom
Icons. Files are limited to Core-compatible path and polygon geometry, are
validated before storage, and never enter the Media Library.

= What happens when a custom icon is removed or the plugin is uninstalled? =

The Icon block stores the registered icon name rather than an SVG copy. Existing
blocks that reference a removed custom icon continue to render, while the icon
is hidden from new selections. Uninstall removes plugin-owned custom icon files
and metadata, so icons still require the plugin to remain active.

== Third-party licenses ==

Bundled libraries remain under their upstream terms:

* Heroicons: https://github.com/tailwindlabs/heroicons (MIT license).
* Bootstrap Icons: https://github.com/twbs/icons (MIT license).
* Font Awesome Free: https://fontawesome.com/license/free (Font Awesome Free license).

== Changelog ==

= 1.0.0 =

* Integrate collections with the WordPress 7.1 public Icon API.
* Bundle 648 Heroicons Outline and Solid icons, 2,073 Bootstrap Icons, and 2,883 Font Awesome Free style entries with the upstream 68-category taxonomy plus a Brands grouping, separately categorized variants, and deterministic manifests. Outline is opt-in while its WordPress 7.1 Core sanitization workaround is validated.
* Expose available styles separately in the native Icon picker collection filter.
* Add per-variant enable and disable controls with capability-checked REST routes.
* Add Appearance > Icons library management and searchable previews.
* Add strict local custom SVG icon management.
* Preserve saved icons when bundled libraries are disabled.
* Add reproducible validation and release packaging.
