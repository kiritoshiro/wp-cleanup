# WP Cleanup

A WordPress plugin that finds data left behind by removed plugins and themes. For each item it shows **who it belongs to** and **whether anything still uses it**, then removes what you select after writing a **restorable backup**.

Scanning only reads. Nothing changes until you choose items and confirm.

## What it finds

| Type | Examples |
|---|---|
| Options | `wpseo_titles`, `wordfence_*`, large autoloaded leftovers |
| Transients | Transients left by removed plugins, including ones that never expire |
| Tables | `wp_wfconfig`, `wp_yoast_indexable`, `wp_actionscheduler_*` |
| Cron events | Scheduled hooks nobody listens to any more |
| Meta keys | Post, user, term and comment meta, grouped by key |
| Post types | Posts of unregistered types (e.g. `wpcf7_contact_form`), with their meta, comments and term links |
| Orphaned rows | Meta or term links whose object no longer exists, and expired transients |
| Folders | Top-level folders in `uploads/` and `wp-content/` (e.g. `wflogs`, `woocommerce_uploads`) |

## Images: AVIF, two sizes at most

WordPress keeps several copies of every uploaded image: the original, a `-scaled` copy, `thumbnail`, `medium`, `medium_large`, `large`, `1536x1536`, `2048x2048`, and any sizes themes and plugins add. Folders often also hold thumbnails from old themes, and `.jpg.webp` sidecars from optimizer plugins.

**Tools → WP Cleanup → Images** (or `wp cleanup images`) turns each JPEG, PNG or AVIF attachment into:

- **full**: one AVIF, longest side at most **1920 px**
- **`alps-small`**: one AVIF, longest side at most **768 px**, only when the image is bigger than that

These defaults match the Adventistai ALPS theme's upload policy. Converted images get that theme's `_alps_two_size_upload` flag, so its templates map old size names (`thumbnail`, `large`, `horiz__16x9--m`, …) to these two files. Old images end up in exactly the same shape as new uploads. The sizes, size name and flag can all be changed under *Image policy*.

How each image is converted:

1. **Encode and verify.** The AVIFs are made from the best available source (the pre-`-scaled` original, or the edited version if the image was edited in WordPress). EXIF rotation is applied and transparency kept. Each new file is checked to decode with the right size and type before anything else happens.
2. **Rewrite references.** Every place that links to one of the old files is rewritten to the kept file: post content, excerpts, revisions, post/term/user meta, options and comments. Links to a small size point at `alps-small`, everything else at full. This covers absolute, relative and CDN URLs, and JSON-escaped block or page-builder data. Serialized values are unserialized and rewritten properly, never string-replaced. Stored `srcset` attributes are rebuilt with real widths. If a URL sits inside a serialized PHP object, that image is **left untouched** and reported.
3. **Back up.** Column-level before-images of everything that changes are written to a backup set.
4. **Move the old files** (original, `-scaled`, every size, strays, sidecars) into the backup set. Any failure along the way rolls that image back completely.

**Never touched:** GIF and WebP (they can be animated), site icons, custom headers and backgrounds, and files that aren't on this server (for example, offloaded to S3).

**Space is only freed once you delete the backup set** on the Backups tab, after checking the site. Until then, **Restore** puts every original file and every rewritten value back. It refuses, per image, if the image or a rewritten row has been edited since.

Requirements: WordPress 6.5+ with GD or Imagick built with AVIF support. The Images tab says whether the server has it.

```bash
wp cleanup images status
wp cleanup images convert --limit=20 --dry-run
wp cleanup images convert --yes
```

Limits:
- URLs stored in custom plugin tables aren't rewritten. Yoast's indexables are one example; they refresh themselves.
- A text mention of the exact same `uploads/…/file.jpg` path on another site would also be rewritten.
- New uploads keep generating every size unless the theme limits them; the ALPS theme does.
- A small AVIF can get a `-1` suffix (`photo-768x512-1.avif`) when the old JPEG of that name still exists at conversion time.

### Where images are used, look-alikes and unused images

The same check also shows, for **every** image in the library, with a small preview:

- **Where it is used:** as the featured image of a post or page; in content, through its URL at any size (absolute, relative or JSON-escaped), a `wp-image-{id}` class, image/cover/gallery block ids or `[gallery ids=""]`; in custom fields holding its id or URL (Carbon Fields, ACF and similar image fields, and page-builder data); in term fields such as a category image; and in site settings such as the site icon, logo, Customizer header or background, and image widgets. Each place links to its edit screen. "Uploaded to" is shown separately, because uploading an image to a post doesn't mean the post uses it. Revisions, drafts saved automatically and trashed posts don't count.
- **Look-alike images:** the same picture uploaded more than once, also when resized, re-compressed or saved in another format. Images are compared by a 64-bit perceptual hash of their structure, plus their average colour and shape, so flat graphics or crops aren't mistaken for copies. Each group forms around its oldest image, so a chain of small differences can't pull in unrelated pictures. Groups are marked "identical files" when every file is byte-for-byte the same. The Images tab can select unused copies while keeping a used copy (or the oldest copy when all are unused).
- **Not used anywhere:** images none of the above refer to.

The Images tab lets you select up to 20 unused images per action, including unused look-alike copies. Each eligible image in a look-alike group also has its own **Move this image to backup** action. The **Rescan look-alike images** and **Rescan not used anywhere** buttons refresh only their respective analysis; **Check the library again** refreshes conversion totals as well. Click a table column heading to sort the conversion or unused-image list. The selection count reflects the images that will be submitted with the form. Confirm a full site backup before moving them to a restorable WP Cleanup backup set. The plugin checks current usage again before each removal and refuses protected images, shared files and images that became used. Restore the backup set if needed; permanently delete it only after checking the site. Only what is stored in the database is visible: an image can still be used from theme files, CSS, another plugin's own tables or another website, so review every selection carefully.

Hashes are cached in the plugin's data folder, so only new or changed images are read again. On a very large library, a check that runs out of time says so; checking again continues where it stopped. The `wp_cleanup_similarity_budget` filter changes the time budget. `wp cleanup images status` prints the same summary and groups.

## Updates from GitHub

WP Cleanup updates itself from the [GitHub releases](https://github.com/kiritoshiro/wp-cleanup/releases), like a plugin from wordpress.org. New versions appear under **Dashboard → Updates** and on the Plugins screen, "View details" shows the release notes, and WordPress's auto-update toggle works. Before installing, the downloaded ZIP is checked against the SHA-256 checksum GitHub publishes for it; a mismatch stops the update. Only published releases count, never drafts or pre-releases. The check is cached for six hours, and **Check again** on the Updates screen refreshes it.

**No token is needed**, because the repository is public. Only add one if the repository becomes private, or if the server shares its IP address with many sites and hits GitHub's limit of 60 anonymous requests an hour. Then:

1. On GitHub: **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**. Set **Repository access** to *Only select repositories* → `kiritoshiro/wp-cleanup`, **Permissions → Repository permissions → Contents** to *Read-only*, and choose an expiry date.
2. In `wp-config.php`, above "That's all, stop editing!":

   ```php
   define( 'WPCU_GITHUB_TOKEN', 'github_pat_…' );
   ```

The token is only sent to `api.github.com`, never along the download redirect, and it is never stored in the database. Renew it before it expires.

## How it decides

Each item gets a status, based on evidence in this order:

1. **WordPress core**: in the core lists, referenced by WordPress core code, or belonging to another WordPress install that shares the database (e.g. `wp_staging_*`). **Never deleted.**
2. **In use**: an active plugin, theme, mu-plugin or drop-in references the name (or its prefix, or a class name it ends with), or a runtime check shows it's live (a registered post type, or a hooked cron event). **Never deleted.**
3. **Inactive plugin/theme**: only installed-but-inactive code references it. Deleted only with an explicit opt-in.
4. **Orphaned**: nothing installed references it, and the name matches the known prefix of software that isn't installed (see `data/signatures.php`). Cleanable.
5. **Unknown owner**: nothing references it, and the prefix isn't recognised. Deleted only after you confirm you've reviewed it.
6. **Safe to clean**: structural junk (rows pointing at deleted objects, expired transients).

"References" comes from an index of every identifier-like string literal and class name in installed PHP code, including WordPress core. The index is cached and rebuilt whenever plugins or themes change. If any code can't be fully read (for example a file over 8 MB), nothing is marked orphaned until it can.

Many plugins and themes build names at runtime, so the name in the database differs from the literal in their code. Each name is therefore also checked in these forms:

- **Carbon Fields keys**, such as `_hero_carousel|slide_image|1|0|value` or `_footer_address||0|_empty`. These are checked by their field name (`hero_carousel`, `footer_address`), so theme options and complex fields count as in use while the theme defining them is active.
- **Numeric suffixes**, such as `post_by_email_address4` (a per-user id) or `…_backup_14_2_1` (a version). These are also checked without the suffix.
- **Installed folder names inside a name**, such as `puc_external_updates_theme-{theme}` or `external_updates-{plugin}` (update caches). These are credited to that plugin or theme with medium confidence, but only when nothing else claims the name.

An exact reference in any of these forms beats a match on a prefix alone. Core names that WordPress builds dynamically are protected too: `_oembed_*` caches, post-format fields, custom-header timestamps, and pending-invite and bulk-delete options. Signature entries ending in `$` label one exact name (for example Elementor's `e_events` table) instead of a whole prefix.

## Safety model

- A selection is re-checked against a **fresh scan** at deletion time, so a stale screen or a crafted request can't delete anything that is core or in use now.
- Every cleanup first writes a **backup set** to a private folder in uploads. The folder has a random name and deny rules for Apache and IIS. It contains:
  - SQL for rows and tables, with values stored as hex literals so any bytes round-trip exactly
  - JSON for cron events
  - folders, which are *moved* into the set rather than deleted
- **Restore** replays a set. It never overwrites a table, option or folder that has been recreated since.
- Admin actions require `manage_options` and a nonce. The plugin is inactive on multisite in this version.
- The `Update URI` header stops WordPress offering the unrelated wordpress.org plugin called `wp-cleanup` as an "update".

**Still take a full site backup before cleaning a live site.** The built-in backup covers only what this plugin deletes.

## Using it

**Admin:** go to *Tools → WP Cleanup*, click **Scan now**, review each tab, select items, and click **Back up & delete selected**. The **Backups** tab lists every backup set, with **Restore** and **Delete backup** buttons.

**WP-CLI:**

```bash
wp cleanup scan                                   # candidates (orphaned, safe, unknown, inactive)
wp cleanup scan --status=all --type=option,table --format=csv
wp cleanup clean --status=orphaned --dry-run      # show the plan
wp cleanup clean --owner=wordpress-seo --yes      # everything attributed to a removed plugin
wp cleanup clean "option|oldplugin_settings" --allow-unknown
wp cleanup backups
wp cleanup restore <backup-id> --yes
wp cleanup delete-backup <backup-id> --yes
```

**Filters:**
- `wp_cleanup_signatures` adds your own plugin prefixes.
- `wp_cleanup_index_max_files` changes the per-source file limit (default 40,000).

## Development

Requirements: PHP 7.4+ and WordPress 6.0+. Tested on PHP 8.3 with WordPress 7.1.2 and MySQL 8.4.

The integration suite is **destructive** and refuses to run unless `WPCU_TESTS=1` is set and the site URL is local (`localhost`, `127.0.0.1` or `*.test`):

```bash
bin/test-setup.sh /path/to/throwaway-wordpress "wp"
cd /path/to/throwaway-wordpress
WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/run.php
WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/images.php   # needs AVIF support
WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/usage.php
```

The usage suite (37 assertions) covers:
- every kind of image use, and revisions, trashed posts and dimension settings not counting
- look-alike detection: resized and identical copies found; different pictures, flat graphics and crops kept apart; no chaining
- the updater: release parsing, refusing drafts, pre-releases and foreign packages, digest checks on a real download (a local one, plus the latest GitHub release when online), and "View details" not claiming wordpress.org's unrelated `wp-cleanup`

Run it while the site is served on its own URL (for example with `php -S`) so the local download check can run.

The image suite (45 assertions) covers:
- inventory, including strays, sidecars and same-name neighbours
- conversion sizes, the ALPS flag and transparency
- reference rewriting in HTML, relative URLs, JSON-escaped and serialized data, and `srcset`
- refusing URLs inside PHP objects, and full rollback after an injected failure
- a byte-identical restore
- restore refusing to overwrite later edits
- idempotence

The suite covers:
- classification of seeded leftovers from fake active, inactive and removed plugins
- refusal of core, in-use and other-install data, and path traversal
- a clean → restore round-trip that must be byte-identical, including binary, multibyte and serialized values, custom cron schedules, and term counts
- opt-in policies
- refusing to overwrite recreated data during restore
- the fail-safe for an incomplete index
- uninstall

## Known limits (v0.1)

- Single-site only.
- On nginx, the backup folder is protected only by its random name. If directory listing is on for `uploads/`, block `wp-cleanup-*` in the server config.
- Names built entirely at runtime (with no literal prefix, field name, class name or folder name in the code) can't be traced back to their plugin. They show as "unknown owner", never as "orphaned".
- Very large tables are copied into the backup inside one request. Use WP-CLI for those.
- Uninstalling the plugin deletes its backup sets.
