# WordPress.org Deployment

This plugin is prepared for a WordPress.org-style release flow.

## What changed for the public release

- The private auto-update flow was removed from the release code.
- A WordPress.org-compatible `readme.txt` was added.
- A clean packaging script was added to assemble a submission-ready `trunk` directory.

## Build the release package

Run:

```bash
chmod +x build-wordpress-org-package.sh
./build-wordpress-org-package.sh
```

This creates:

```text
wordpress-org-build/
  assets/
    banner-772x250.png
    icon-128x128.png
    icon-256x256.png
  trunk/
    assets/
    includes/
    languages/
    readme.txt
    activity-logger-site-owners.php
```

## Before the first submission

1. Validate `readme.txt` with the WordPress readme validator.
2. Confirm the WordPress.org account in the `Contributors` field is correct.
3. Add optional plugin directory assets if desired:
   - `assets/icon-128x128.png`
   - `assets/icon-256x256.png`
   - `assets/banner-772x250.png`
4. If you receive a review note about naming convention, align the plugin slug, folder name, and main file name before the first SVN import.

## Suggested SVN structure

```text
your-plugin-slug/
  assets/
  trunk/
  tags/
    2.6.1/
```

Copy the contents of `wordpress-org-build/trunk` into your SVN `trunk/`.
Copy the contents of `wordpress-org-build/assets` into the SVN root `assets/`.
Then tag the same release as `tags/2.6.1/`.
