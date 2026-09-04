# Icon Library 1.0.0 Release Checklist

## Automated Gate

- Run `composer install` from the committed lock file.
- Run `composer check` on PHP 7.4 and the current supported PHP version.
- Run `ICON_LIBRARY_WP_LOAD=/path/to/wp-load.php composer smoke` on WordPress 7.1.
- Run `composer package` twice and confirm identical SHA-256 hashes.
- Run Plugin Check against the extracted production ZIP.
- Confirm the package contains manifest-referenced SVG files plus the explicitly
  documented Heroicons legacy size aliases.

## WordPress.org Submission

- Run the official WordPress.org Readme Validator against `readme.txt`.
- Confirm the ZIP is below 10 MB, extracts to a single `icon-library/` directory,
  and contains no tests, development dependencies, build output, or repository
  metadata.
- Confirm all bundled third-party license files and source links are present.
- Submit the production ZIP for review; do not automate an SVN upload from this
  repository. After approval, publish the matching code and readme through the
  WordPress.org-assigned SVN repository.

## Editor and Frontend

- Open Appearance > Icons at desktop and mobile widths.
- Activate and deactivate Heroicons with keyboard controls and verify status messages.
- Enable the experimental Heroicons Outline variant and verify Core's sanitized output retains the root marker and renders through the scoped stylesheet.
- Search and filter the icon browser.
- Open Font Awesome Free and verify the Solid, Regular, and Brands variants,
  the official category labels/counts, and category-plus-search filtering.
- Confirm collection-scoped Core icon requests remain responsive with all
  bundled libraries enabled; repeat a cold and warm request for a large style.
- Insert a bundled icon and a custom icon in the native Icon block.
- Change supported block styles, save, reload, and inspect the frontend.
- Disable Heroicons and verify existing content still renders while new discovery is hidden.
- Delete a disposable custom icon only after confirming the dependency warning.

## Owner-Gated Release

- Review and approve the final ZIP hash and Plugin Check output.
- Merge the release branch only after all required checks pass.
- Create the `1.0.0` tag and GitHub/WordPress.org release only with explicit owner approval.
