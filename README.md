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

## Images: AVIF with an optional JPEG fallback

WordPress often keeps an original, a scaled copy, many thumbnail sizes, and optimizer sidecars for one upload. **Tools → WP Cleanup → Images** (or the WP-CLI images command) replaces a JPEG, PNG, or AVIF attachment with:

- **An optional optimized JPEG fallback**, enabled by default, at most **1920 px** on its longest side, with adjustable quality (default 82). Exactly one JPEG is kept; no JPEG thumbnail copies are generated.
- **One full AVIF**, at most **1920 px**, when the server can encode and verify it.
- **One small AVIF**, at most **768 px**, when the full AVIF exceeds that size. For selected images, the Images tab also accepts a manual pixel gap; if the full AVIF's longest side is within that many pixels of the small AVIF's longest side, it keeps only the full AVIF (plus the JPEG fallback when enabled).

When AVIF encoding succeeds, AVIF is the attachment file and the target of rewritten links. With the optional JPEG enabled, WordPress images rendered through wp_get_attachment_image() (featured images, most theme markup) and images in post content that carry WordPress's `wp-image-{id}` class use a picture element with an AVIF source and JPEG fallback for older browsers. Images already inside a `<picture>` are left alone. Direct URLs (for example from `wp_get_attachment_image_src()`), CSS backgrounds and other stored HTML use AVIF only. Changes to the image policy must be saved before conversion. If the first AVIF editor fails and GD supports the source and AVIF, the plugin retries with GD. If both attempts fail, the plugin keeps the single JPEG and reports the observed image dimensions and decoder error. Attachments converted by v0.6.1 with JPEG as the main file remain untouched until you restore that image from its backup and convert it again; a policy change does not silently replace an existing attachment. Turn the fallback off under *Image policy* to keep AVIF only; if AVIF encoding then fails, the original image is left untouched.

Use **Apply to selected** with the gap field to process only checked rows. Already converted images with a recorded small AVIF also appear in that table; when their sizes fall within the chosen gap, WP Cleanup rewrites known WordPress references to the full AVIF and moves only the small file into a restorable backup. It leaves the full AVIF and any JPEG fallback unchanged. **Convert all** keeps the normal image policy and ignores the gap. A gap of 0 disables this selected-image rule. ALPS remembers the chosen gap for those attachments so regeneration does not recreate their small size; new uploads remain unchanged.

The plugin encodes and verifies new files before changing the database. It rewrites references in posts, meta, options and comments to the selected main file, including serialized and JSON-escaped data, then moves all old sizes, originals and sidecars into a restorable backup set. Rewritten references are shown as separate **Location**, **Original**, and **New** rows in each conversion result and under **Backups → Contents**. The rewrite count includes saved post revisions and database fields; it is not the number of current pages using the image. Revisions are labeled with their parent post. A failure while rewriting or moving files rolls back that image.

The JPEG switch, size limits, JPEG quality and ALPS marker can be changed under *Image policy*.

**The ALPS marker** (`_alps_two_size_upload`) tells the Adventistai ALPS theme (3.28.2+) that an image follows its size policy: one full image of at most 1920 px plus an optional `alps-small` of at most 768 px. ALPS marks small uploads that only have one file too, and the plugin does the same. With the marker, ALPS maps old template sizes (`thumbnail`, `large`, `horiz__16x9--s`, …) to these files, and regenerating thumbnails does not bring back the old sizes. Without it, regeneration recreates `thumbnail`, `medium` and so on. The plugin sets the marker only while the policy matches those ALPS sizes, and removes it from converted images when the policy doesn't match or the option is off. Changing only this option never re-encodes images; the data check (below) brings existing markers in line. The Image policy box says whether the active theme uses the ALPS image policy. GIF and WebP (which may be animated), site icons, custom headers and backgrounds, and offloaded files are not converted.

**New uploads with the ALPS theme (3.29+).** The theme converts uploads itself into the same three files (full AVIF, `alps-small` AVIF, one JPEG fallback) and records them in the same `_wpcu_image_outputs` meta, marked `by: alps-theme`. When this plugin's policy fits ALPS, the theme uses its sizes and JPEG quality. The plugin therefore:
- lists those uploads as already converted, including during the week the theme keeps the original before deleting it;
- leaves the theme's marker alone in the data check;
- lets the theme serve them, since the theme writes its own AVIF + JPEG picture markup and this plugin leaves existing `<picture>` markup alone.

This page is then for older images. Deleting any attachment also deletes its recorded JPEG fallback, unless another attachment uses that file.

**Space is freed when the backup set is deleted** on the Backups tab, after checking the site. Until then, **Restore** puts the old files and rewritten database values back. It refuses to overwrite edits made after conversion.

JPEG encoding is required when the fallback is enabled. AVIF requires WordPress 6.5+ and GD or Imagick with AVIF support; without it, conversion only produces JPEG when the fallback is enabled. AVIF-only mode requires AVIF encoding support. New files get a numeric suffix only when another attachment's file already has that name. When the clean name belongs to an old file of the same image (re-converting after a policy change), the new file is written under a temporary name and takes the clean name once the old file is in the backup, so names don't drift to `-1`, `-1-1`. Each conversion result and backup entry lists every backed-up and new file with its dimensions, format and size.

    wp cleanup images status
    wp cleanup images convert --limit=20 --dry-run
    wp cleanup images convert --yes

Limits:
- URLs stored in custom plugin tables are not rewritten. Yoast indexables are one example and normally refresh themselves.
- A text mention of the exact same uploads path on another site could also be rewritten.
- New uploads keep generating their usual sizes unless the theme limits them (the ALPS theme does).

### Server statistics and the image data check

The top of **Images** always shows, from the last library check:
- every image file on the server and the space it uses
- how many are Media Library images (with all their files) and how many have no attachment
- a breakdown by format (AVIF, JPEG, PNG, WebP, GIF…)
- the space still held in backup sets

These stay meaningful after every image is converted.

Every library check also compares each image's saved WordPress data with its files, and lists anything that doesn't match:
- file paths stored as absolute or Windows paths (made by 0.2.0 on Windows web servers)
- a wrong MIME type
- wrong saved dimensions or file sizes
- sizes listed for files that don't exist
- a small AVIF that exists but isn't listed, or is listed but not recorded
- recorded fallback/AVIF files that are missing or have the wrong width
- an ALPS marker that disagrees with the policy

**Repair** fixes these by changing only WordPress data, never image files. Each image is re-checked first, and each repair can be undone from **Backups** unless the image changed since. Problems that need a person, such as a main file missing from disk, are listed separately and never "repaired". `wp cleanup images repair [--dry-run]` does the same from WP-CLI.

### Timeouts (HTTP 502/504) while converting

On many hosts a proxy (nginx, Cloudflare, a load balancer) gives up on a request after about 60–100 s and answers 502 or 504, while PHP carries on converting the image. In the browser, each image is converted in its own request, and the plugin checks the image first:

- **Already converted, or skipped:** it is not converted again.
- **Still being converted by an earlier request:** each image has a lock, and the browser waits for it to clear.
- **Stopped part way:** the lock is stale, or the request was killed, and the image data no longer matches its files. The image is reported so you can use Repair or restore it.
- **Likely to take too long:** the estimate is based on this server's recent conversion times and the shortest wait that ended in a timeout. It also covers images too large for PHP memory when GD is used. These images are left for `wp cleanup images convert --ids=…`, unless you tick **Also try images that may time out**.

If a request still ends without an answer, the browser asks the server whether that image finished, then reports it as converted or not converted and continues with the next one. A finished image's file details are then only shown on the Backups tab. All images in one run go into the same backup set, even across a timeout. The budget can be changed with the `wpcu_media_request_budget` filter.

### Current files on the server

In **Look-alike images**, choose which image to keep and merge another used copy into it. The plugin redirects known WordPress URLs and image IDs, backs up the redundant attachment and its files, and refuses the merge if a use remains. Crops and resolutions may display differently after merging; check the preview and the site. Theme files, CSS, plugin tables and external sites are outside the reference scan. A merge can be restored as one item from **Backups**.

At the bottom of **Images**, **Current image files on the server** shows each Media Library attachment once. Expand one image to see its main file, original source, generated dimensions, AVIF alternatives, JPEG fallback, and any extra files the scanner associates with it.

A separate list shows image files physically present in uploads that have **no Media Library attachment**, including JPEGs left beside AVIF attachments. These are not look-alike groups: a JPEG and AVIF can be intentional alternatives, and filenames alone do not prove two images are redundant. The list is a snapshot from the last full library check; check again after changing files. Each backup item also offers a one-image restore and download links for individual original files.

Select any number of unregistered files to move them one at a time into one restorable backup set. Progress and a stop button remain visible while the batch runs. The plugin rebuilds the file catalog and refuses any file name mentioned in WordPress posts, fields, options or comments. Theme code, CSS, custom plugin tables and external sites can still refer to a file, so review the list before moving it. The protected WP Cleanup backup folder is excluded from the server-file list.

### Where images are used, look-alikes and unused images

The same check also shows, for **every** image in the library, with a small preview:

- **Where it is used:** as the featured image of a post or page; in content, through its URL at any size (absolute, relative or JSON-escaped), a `wp-image-{id}` class, image/cover/gallery block ids or `[gallery ids=""]`; in custom fields holding its id or URL (Carbon Fields, ACF and similar image fields, and page-builder data); in term fields such as a category image; and in site settings such as the site icon, logo, Customizer header or background, and image widgets. Each place links to its edit screen. "Uploaded to" is shown separately, because uploading an image to a post doesn't mean the post uses it. Revisions, drafts saved automatically and trashed posts don't count.
- **Look-alike images:** the same picture uploaded more than once, also when resized, re-compressed or saved in another format. Images are compared by a 64-bit perceptual hash of their structure, plus their average colour and shape, so flat graphics or crops aren't mistaken for copies. Each group forms around its oldest image, so a chain of small differences can't pull in unrelated pictures. Groups are marked "identical files" when every file is byte-for-byte the same. The Images tab can select unused copies while keeping a used copy (or the oldest copy when all are unused).
- **Not used anywhere:** images none of the above refer to.

The Images tab lets you select any number of unused images per action, including unused look-alike copies. The browser moves them one at a time into one backup set, shows progress, and can stop after the current image. Each eligible image in a look-alike group also has its own **Move this image to backup** action. The **Rescan look-alike images** and **Rescan not used anywhere** buttons refresh only their respective analysis; **Check the library again** refreshes conversion totals as well. Click a table column heading to sort the conversion or unused-image list. The selection count reflects the checked images. If a server response is lost, the batch stops without retrying that item; check Backups and rescan before continuing. Confirm a full site backup before moving them to a restorable WP Cleanup backup set. The plugin checks current usage again before each removal and refuses protected images, shared files and images that became used. Restore the backup set if needed; permanently delete it only after checking the site. Only what is stored in the database is visible: an image can still be used from theme files, CSS, another plugin's own tables or another website, so review every selection carefully.

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
WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/integrity.php   # data check, ALPS marker, file details; set WPCU_ALPS_DIR to test against the real ALPS image code
WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/guard.php   # per-image lock, check before converting, timeout recovery
WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/alps-theme.php   # uploads the ALPS theme converted itself; JPEG fallback removed on delete
```

The usage suite (48 assertions) covers:
- every kind of image use, and revisions, trashed posts and dimension settings not counting
- look-alike detection: resized and identical copies found; different pictures, flat graphics and crops kept apart; no chaining
- the updater: release parsing, refusing drafts, pre-releases and foreign packages, digest checks on a real download (a local one, plus the latest GitHub release when online), and "View details" not claiming wordpress.org's unrelated `wp-cleanup`

Run it while the site is served on its own URL (for example with `php -S`) so the local download check can run.

The image suite (50 assertions) covers:
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
