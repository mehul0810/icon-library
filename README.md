# Icon Library

Icon Library enables curated SVG icon collections for the native WordPress
`core/icon` block.

## 1.0 Development Scope

- WordPress 7.1+ only.
- Bundled Heroicons collection.
- Static manifests and optimized SVG files.
- Appearance -> Icons activation screen.
- Plugin REST endpoints for collection state management.
- Registration through the public WordPress SVG Icon API.
- No uploads, remote marketplace, icon fonts, or competing icon block.

## Core Icon API Integration

WordPress 7.1 exposes public functions for registering icon collections and
icons. `IconLibrary\CoreIconRegistrar` maps enabled plugin manifests to
`wp_register_icon_collection()` and `wp_register_icon()`. Icon SVG files are
passed by absolute `file_path`, allowing Core to load and sanitize their
contents lazily when the REST API or renderer requests them.

## Manifest Shape

Bundled collections live under `assets/icons/{collection}/manifest.json`.
Each icon has an internal collection ID, a Core-compatible ID, and a checksum:

```json
{
  "id": "heroicons/24-outline/academic-cap",
  "coreIconName": "heroicons/academic-cap-24-outline",
  "label": "Academic Cap",
  "variant": "24-outline",
  "categories": ["general"],
  "keywords": ["academic", "cap"],
  "path": "24-solid/academic-cap.svg",
  "sha256": "..."
}
```

The core ID intentionally uses one namespace separator because the current
`wp/v2/icons/{name}` route only accepts `namespace/icon-name`.

## REST Endpoints

- `GET /wp-json/icon-library/v1/collections`
- `POST /wp-json/icon-library/v1/collections/{slug}/activate`
- `POST /wp-json/icon-library/v1/collections/{slug}/deactivate`

Collection mutations require `manage_options`. Read endpoints require the same
editor-style access as the Core icon endpoint. Icon discovery and rendering use
the native WordPress `wp/v2/icons` endpoints.

## Importing Heroicons

```bash
git clone --depth=1 https://github.com/tailwindlabs/heroicons.git /tmp/heroicons
php scripts/import-heroicons.php /tmp/heroicons
php scripts/validate-manifests.php
```
