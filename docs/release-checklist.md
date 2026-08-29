# Icon Library 1.0.0 Release Checklist

## Automated Gate

- Run `composer install` from the committed lock file.
- Run `composer check` on PHP 7.4 and the current supported PHP version.
- Run `ICON_LIBRARY_WP_LOAD=/path/to/wp-load.php composer smoke` on WordPress 7.1.
- Run `composer package` twice and confirm identical SHA-256 hashes.
- Run Plugin Check against the extracted production ZIP.
- Confirm the package contains only manifest-referenced Heroicons SVG files.

## Editor and Frontend

- Open Appearance > Icons at desktop and mobile widths.
- Activate and deactivate Heroicons with keyboard controls and verify status messages.
- Search and filter the icon browser.
- Insert a bundled icon and a custom icon in the native Icon block.
- Change supported block styles, save, reload, and inspect the frontend.
- Disable Heroicons and verify existing content still renders while new discovery is hidden.
- Delete a disposable custom icon only after confirming the dependency warning.

## Owner-Gated Release

- Review and approve the final ZIP hash and Plugin Check output.
- Merge the release branch only after all required checks pass.
- Create the `1.0.0` tag and GitHub/WordPress.org release only with explicit owner approval.
