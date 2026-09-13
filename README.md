# 4All SEO Bridge

A small WordPress plugin that lets 4All Digital's automation tools read and
update SEO titles, meta descriptions, and image alt text on a client site
through an authenticated REST namespace (`4all/v1`). It exists because
Yoast and RankMath keep their fields in protected post meta that core REST
will not write, and because URL → post resolution is more reliable done
server-side with WordPress's own `url_to_postid()`.

Single file, no settings screen, no front-end output. GPL-2.0-or-later.

## Install

1. Download `4all-seo-bridge.zip` from the latest
   [release](https://github.com/proximodev/4all-seo-bridge/releases/latest).
2. WordPress → Plugins → Add New → Upload Plugin → Install → Activate.
3. Verify from a machine with an Application Password:
   `GET https://<site>/wp-json/4all/v1/ping` → `{ "ok": true, "version": "0.2.0", … }`

Requirements: WordPress 6.0+, PHP 7.4+, an active Yoast SEO or RankMath, and
an Editor/Administrator account with an Application Password for the tools.

## Updates

From 0.2.0 the plugin updates itself through WordPress's built-in plugin
updater. Auto-update is enabled by default for this plugin; a site can opt
out with `define( 'FOURALL_SEO_BRIDGE_AUTO_UPDATE', false );` in
`wp-config.php`. The very first 0.2.0 install is still a manual upload.

### How the plugin talks to this repository

The plugin has no scheduler and no update code of its own beyond one
fetch. It hooks into the update check WordPress core already runs.

**What points at the repo.** Three things in `4all-seo-bridge.php`:

| Where | Value | Used by |
|---|---|---|
| `Update URI` plugin header | `https://github.com/proximodev/4all-seo-bridge` | WordPress core: any host other than wordpress.org makes core ask the `update_plugins_<host>` filter for this plugin |
| `FOURALL_SEO_BRIDGE_MANIFEST_URL` | `…/releases/latest/download/manifest.json` | the plugin: the only URL it ever fetches |
| `download_url` inside the manifest | `…/releases/download/vX.Y.Z/4all-seo-bridge.zip` | WordPress core: downloads and installs the zip |

The repository (or at least its release assets) must stay publicly
readable; nothing sends a token.

**When a check happens.** WordPress core runs `wp_update_plugins()`:

- on the `wp_update_plugins` WP-Cron event, twice a day;
- when an administrator opens **Plugins** or **Dashboard → Updates**
  (**Check again** forces it);
- right after any plugin upgrade completes.

WP-Cron only fires on page loads unless the host runs a system cron, so
on a very quiet site "twice a day" can stretch. Managed hosts (WP Engine
included) run a real cron.

**What happens during a check.**

1. Core fires the `update_plugins_github.com` filter for this plugin.
2. The plugin returns the cached manifest if it has one, otherwise
   fetches `manifest.json` (10 s timeout) and caches it in the
   `fourall_seo_bridge_manifest` site transient for **12 hours**
   (1 hour after a failed or malformed fetch, so a GitHub outage does not
   hammer the site).
3. Core compares the manifest `version` with the installed `Version:`
   header. Newer → the Plugins screen shows the update with **View
   details** (the `plugins_api` panel, also built from the manifest).
4. Auto-update: core's twice-daily `wp_maybe_auto_update` task applies
   any plugin whose `auto_update_plugin` filter returns true. This plugin
   answers `FOURALL_SEO_BRIDGE_AUTO_UPDATE` (default `true`).
5. Activation, and the completion of any plugin upgrade, delete the
   cached manifest so the next check starts fresh.

**Consequence of the 12-hour cache.** Cron checks and the Plugins screen
may be answered from the cache, so a release published in the last 12
hours can take up to that long to appear on its own. **Dashboard →
Updates → Check again** clears the plugin's cache before core runs its
check (from 0.2.1), so that click always shows the current release.

## Endpoints

All routes require authentication and `edit_posts`; each handler checks
`edit_post` on the target.

| Method | Route | Params | Returns |
|---|---|---|---|
| GET | `/4all/v1/ping` | — | `version`, `seo_plugin`, `can_edit`, `user`, `capabilities` |
| GET | `/4all/v1/seo` | `url` or `id` | current SEO `title` / `metadesc` + `post_title`, `post_type`, `post_status`, `slug`, `url` |
| POST | `/4all/v1/seo` | `url` or `id`, `title?`, `metadesc?`, `dry_run?` | `before`, `after`, `changed`, post facts |
| POST | `/4all/v1/alt` | `url`, `alt`, `dry_run?` | `before`, `after`, `changed` |

URL resolution order: `?p=` / `?page_id=` / `?attachment_id=` query vars
(any status — how drafts are addressed; a present query var is
authoritative: a non-numeric value is `bad_query_var` (400) and an unknown
id is `not_found` (404), never the front page), the static front page for an
empty path, `url_to_postid()`, and finally the same path rebuilt on this
site's `home_url()` when the request host differs (a production sheet
pushed to staging). Only fields that are sent are touched, and only when
they differ; `dry_run` computes the diff without writing. Values are
cleaned (invalid UTF-8 rejected, tags stripped, control characters
removed, whitespace collapsed) but not truncated. Every applied write logs
one `error_log` line. Empty values cannot be pushed.

## Releasing

1. Update the `Version:` header and the `FOURALL_SEO_BRIDGE_VERSION`
   constant, add a `CHANGELOG.md` entry, commit.
2. Tag and push: `git tag v0.2.1 && git push origin main --tags`.
3. The release workflow lints, checks the tag matches the header, builds
   `4all-seo-bridge.zip` (unpacks to the `4all-seo-bridge/` folder) and
   `manifest.json`, and attaches both to the GitHub release.
4. Installed sites pick the update up on their next check.

`manifest.json` is fetched from
`https://github.com/proximodev/4all-seo-bridge/releases/latest/download/manifest.json`;
the zip URL inside it points at the tagged release asset. See *How the
plugin talks to this repository* above for when sites check.

## Consumers

- [`4all-automations`](https://github.com/proximodev/4all-automations)
  (private): `wp-seo-push`, `wp-push`, `seo-post` — the tools this bridge
  serves. Their docs describe the operator side.
