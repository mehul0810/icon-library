# Icon Library

Icon Library enables curated SVG icon collections for the native WordPress
`core/icon` block.

## v0.1 Scope

- WordPress 7.0+ only.
- Bundled Heroicons collection.
- Static manifests and optimized SVG files.
- Appearance -> Icon Library activation screen.
- REST endpoints under `icon-library/v1`.
- Guarded registration with the current core `WP_Icons_Registry` API.
- No uploads, remote marketplace, icon fonts, or competing icon block.

## Core Icon API Notes

The current Gutenberg trunk exposes the Icon block as `core/icon`, uses the
`root/icon` core-data entity, and serves icons from `wp/v2/icons`. The
underlying `WP_Icons_Registry::register()` method is still protected, so this
plugin keeps the reflection bridge isolated in `IconLibrary\CoreIconRegistrar`.

If WordPress 7.0 lands a public third-party icon registration function, that
adapter should be the only class that needs to change.

## Manifest Shape

Bundled collections live under `assets/icons/{collection}/manifest.json`.
Each icon has an internal collection ID and a core-compatible ID:

```json
{
  "id": "heroicons/24-outline/academic-cap",
  "coreIconName": "heroicons/academic-cap-24-outline",
  "label": "Academic Cap",
  "variant": "24-outline",
  "categories": ["general"],
  "keywords": ["academic", "cap"],
  "path": "24-outline/academic-cap.svg"
}
```

The core ID intentionally uses one namespace separator because the current
`wp/v2/icons/{name}` route only accepts `namespace/icon-name`.

## REST Endpoints

- `GET /wp-json/icon-library/v1/collections`
- `POST /wp-json/icon-library/v1/collections/{slug}/activate`
- `POST /wp-json/icon-library/v1/collections/{slug}/deactivate`
- `GET /wp-json/icon-library/v1/icons`

Collection mutations require `manage_options`. Read endpoints require the same
editor-style access as the core icon endpoint.

## Importing Heroicons

```bash
git clone --depth=1 https://github.com/tailwindlabs/heroicons.git /tmp/heroicons
php scripts/import-heroicons.php /tmp/heroicons
```
