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

## How it decides

Each item gets a status, based on evidence in this order:

1. **WordPress core**: in the core lists, referenced by WordPress core code, or belonging to another WordPress install that shares the database (e.g. `wp_staging_*`). **Never deleted.**
2. **In use**: an active plugin, theme, mu-plugin or drop-in references the name (or its prefix, or a class name it ends with), or a runtime check shows it's live (a registered post type, or a hooked cron event). **Never deleted.**
3. **Inactive plugin/theme**: only installed-but-inactive code references it. Deleted only with an explicit opt-in.
4. **Orphaned**: nothing installed references it, and the name matches the known prefix of software that isn't installed (see `data/signatures.php`). Cleanable.
5. **Unknown owner**: nothing references it, and the prefix isn't recognised. Deleted only after you confirm you've reviewed it.
6. **Safe to clean**: structural junk (rows pointing at deleted objects, expired transients).

"References" comes from an index of every identifier-like string literal and class name in installed PHP code, including WordPress core. The index is cached and rebuilt whenever plugins or themes change. If any code can't be fully read (for example a file over 8 MB), nothing is marked orphaned until it can.

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
```

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
- Names built entirely at runtime (with no literal prefix or class name in the code) can't be traced back to their plugin. They show as "unknown owner", never as "orphaned".
- Very large tables are copied into the backup inside one request. Use WP-CLI for those.
- Uninstalling the plugin deletes its backup sets.
