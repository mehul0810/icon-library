# Icon Library

Icon Library enables curated SVG icon libraries for the native WordPress
`core/icon` block.

## 1.0 Development Scope

- WordPress 7.1+ only.
- Bundled Heroicons, Bootstrap Icons, and Font Awesome Free libraries with
  upstream variants represented separately where Core-compatible.
- No curated library is enabled until a site owner installs it.
- Static manifests and optimized SVG files.
- Appearance -> Icons activation screen.
- Plugin REST endpoints for library state management.
- Registration through the public WordPress SVG Icon API.
- Strict local custom SVG management without Media Library SVG support.
- No remote marketplace, icon fonts, or competing icon block.

## Core Icon API Integration

WordPress 7.1 exposes public functions for registering icon collections and
icons. `IconLibrary\CoreIconRegistrar` maps enabled plugin manifests to
`wp_register_icon_collection()` and `wp_register_icon()`. Icon SVG files are
passed by absolute `file_path`, allowing Core to load and sanitize their
contents lazily when the REST API or renderer requests them.

## Extension Hooks

Collection providers can register a validated external catalog with the
`icon_library_collection_providers` filter. A provider supplies a slug and a
manifest callback/value, and may supply an SVG path or content callback. Slugs
must be lowercase hyphenated identifiers; SVG paths must resolve to readable
`.svg` files and all content is still passed through Core's sanitizer.

The following filters are available for integrations:

- `icon_library_collections`: collection summaries shown in the admin and REST catalog.
- `icon_library_enabled_collections`: enabled collection slugs.
- `icon_library_enabled_variants`: enabled variants for one collection.
- `icon_library_icon_manifest`: a loaded manifest, its slug, and source path.
- `icon_library_svg_markup`: final SVG markup, re-escaped through the plugin allowlist.

## Manifest Shape

Bundled libraries live under `assets/icons/{collection}/manifest.json`.
Each icon has an internal library ID, a Core-compatible ID, and a checksum:

```json
{
  "id": "heroicons/solid/academic-cap",
  "coreIconName": "heroicons/academic-cap-solid",
  "label": "Academic Cap",
  "variant": "solid",
  "categories": ["general"],
  "keywords": ["academic", "cap"],
  "path": "solid/academic-cap.svg",
  "sha256": "..."
}
```

Libraries may also publish a labeled category index and per-variant counts.
Font Awesome Free imports the official category taxonomy from its upstream
metadata, so the admin browser can show the same labels instead of guessing
from slugs. The current source taxonomy contains 68 categories; the browser
also exposes a `Brands` grouping so every brand style entry remains discoverable:

```json
{
  "variants": [
    { "slug": "solid", "label": "Solid", "iconCount": 2001 },
    { "slug": "regular", "label": "Regular", "iconCount": 273 },
    { "slug": "brands", "label": "Brands", "iconCount": 609 }
  ],
  "categories": [
    { "slug": "accessibility", "label": "Accessibility", "iconCount": 24 }
  ]
}
```

The core ID intentionally uses one namespace separator because the current
`wp/v2/icons/{name}` route only accepts `namespace/icon-name`.

## REST Endpoints

- `GET /wp-json/icon-library/v1/collections`
- `POST /wp-json/icon-library/v1/collections/{slug}/activate`
- `POST /wp-json/icon-library/v1/collections/{slug}/deactivate`
- `POST /wp-json/icon-library/v1/collections/{slug}/variants/{variant}/activate`
- `POST /wp-json/icon-library/v1/collections/{slug}/variants/{variant}/deactivate`
- `GET /wp-json/icon-library/v1/icons` (paginated catalog with variant facets)

Library mutations require `manage_options`. Read endpoints require the same
editor-style access as the Core icon endpoint. Icon discovery and rendering use
the native WordPress `wp/v2/icons` endpoints.

## Icon Lifecycle

Disabling a library hides it from Core collection and icon-list discovery,
so it cannot be selected for new blocks. The plugin continues registering its
icons, allowing existing saved blocks and individual icon requests to render.
The same rule applies when an individual variant is disabled: its style
collection is hidden from new selections, while existing registered names
continue to resolve.

WordPress stores an Icon block's registered name rather than a copy of its SVG.
Deactivating or uninstalling the provider plugin therefore makes those icons
unavailable. This Core limitation is tracked upstream in
https://github.com/WordPress/gutenberg/issues/80668.

## Custom Icons

Administrators can add SVG files through **Appearance > Icons > Custom Icons**.
The plugin validates the file against the WordPress 7.1 icon geometry contract
before storing the sanitized SVG locally under the uploads directory. This does
not enable SVG uploads in the Media Library and makes no remote requests.

Custom icon names are stable after creation so existing blocks keep their
registered name; their display labels can be changed. Removing a custom icon
hides it from new selections while preserving existing blocks. Plugin uninstall
removes custom icon metadata and the plugin-owned SVG files, which prevents
retained post content from resolving those icons.

Archived custom icons can be restored or permanently purged from the Custom
Icons screen. Purging removes the stored SVG and intentionally stops existing
blocks from resolving that icon.

## Importing bundled libraries

```bash
git clone --depth=1 https://github.com/tailwindlabs/heroicons.git /tmp/heroicons
php scripts/import-heroicons.php /tmp/heroicons
php scripts/import-path-library.php bootstrap-icons /path/to/bootstrap-icons
php scripts/import-path-library.php font-awesome /path/to/font-awesome
php scripts/validate-manifests.php
```

## Development

Install the development tools with `composer install`, then run:

```bash
composer check
composer package
```

`composer package` creates `build/icon-library.1.0.0.zip` from an explicit
production allowlist. SVG files referenced by validated library manifests
enter the archive, along with the documented Heroicons legacy size aliases.
The root `.distignore` mirrors the development paths excluded by compatible
WordPress distribution tooling; the built-in packager keeps its stricter
allowlist so an unexpected repository file cannot enter a release.

GitHub Actions runs the same checks and production packaging for semver release
and pre-release tags, then uploads the ZIP as a workflow artifact and GitHub
release asset. The workflows do not upload to WordPress.org SVN; submit the
reviewed production ZIP through the WordPress.org-assigned repository after the
plugin has been approved.

Library authors should follow `schemas/collection-manifest.schema.json`, use
stable namespaced IDs, include source revision and license metadata, and run
the manifest validator before distributing a library.

Font Awesome Free is imported from the upstream `metadata/icons.json` and
`metadata/categories.yml` files. The package exposes the three Free styles
documented by Font Awesome: `Solid`, `Regular`, and `Brands`. Legacy alias SVG
files remain available under their existing Core names, but share the
canonical icon's category and search metadata. Pro-only styles are not bundled.

Heroicons uses `Outline` and `Solid` as its style taxonomy. The Core Icon block
controls the rendered width, so the upstream 20px Mini and 16px Micro files are
not exposed as selectable variants. Outline is bundled as an experimental
variant and is disabled by default. WordPress 7.1 currently strips the stroke
attributes required by Heroicons Outline. When an incompatible variant is
rendered, Icon Library adds a fixed root marker before Core sanitizes the markup
and restores the known stroke presentation with a scoped stylesheet in the
editor and on frontend requests containing the icon. This keeps Core's
sanitizer intact while the workaround is validated.

Available styles are registered as separate Core collections, such as
`heroicons-solid` and `heroicons-outline`, so the native picker collection
filter can separate them.
The original library namespaces are registered for enabled variants and lazily
restored when an existing saved block references a disabled or legacy name.
They remain hidden from discovery to avoid duplicate results. Legacy Heroicons
20px, 16px, and 24px Solid names continue to resolve when their source files are
present. Installing or uninstalling a library controls discovery of all its
styles; individual styles can also be enabled or disabled from the library
detail screen. Core prepares its own REST responses, including fields added by
other plugins. Icon Library only filters discovery results through
`rest_request_after_callbacks`; it does not replace the picker UI or widen
WordPress's global SVG sanitizer.
