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
updater: the header declares an `Update URI` on github.com and the plugin
answers WordPress's `update_plugins_github.com` request by reading
`manifest.json` from this repository's latest release. Sites see the new
version on the Plugins screen within 12 hours and can update with one
click. Auto-update is enabled by default for this plugin; a site can opt
out with `define( 'FOURALL_SEO_BRIDGE_AUTO_UPDATE', false );` in
`wp-config.php`. The very first 0.2.0 install is still a manual upload.

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
(any status — how drafts are addressed), the static front page for an
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
the zip URL inside it points at the tagged release asset.

## Consumers

- [`4all-automations`](https://github.com/proximodev/4all-automations)
  (private): `wp-seo-push`, `wp-push`, `seo-post` — the tools this bridge
  serves. Their docs describe the operator side.
