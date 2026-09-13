# Handoff — 4All SEO Bridge

State as of 2026-09-12, for whoever works in this project next (person or
Claude Code session).

## Where things stand

| Item | Status |
|---|---|
| Plugin code (0.2.0: `GET /seo`, `id` targets, `?p=` and staging-path resolution, post facts, softer cleaning, write log, alt suffix parity) | **done**, in `main` |
| 0.2.1: a present `?p=` / `?page_id=` / `?attachment_id=` is authoritative (`bad_query_var` 400 / `not_found` 404, never the front page); stub tests in CI | **committed** (`4c5043a`, `6f6714f`), **untagged** (2026-09-12) — `git tag v0.2.1 && git push origin main --tags` when ready |
| Self-update (`Update URI` + `update_plugins_github.com` reading the latest release's `manifest.json`; details panel; auto-update on by default) | **done**, in 0.2.0 |
| CI: lint on push/PR (PHP 7.4 + 8.3, header/constant version check) | **done**, green |
| CI: release on `v*` tag (lint, tag = header check, zip + manifest attached) | **done**; `v0.2.0` released, assets fetch anonymously |
| Client sites | **all still on 0.1.1** — each needs one manual upload of 0.2.0; afterwards updates arrive through WordPress |
| Live verification on a WordPress site (`GET /seo`, a `?p=` push, the Plugins screen offering an update) | **done locally** 2026-09-12 (wp-env + Yoast 28.4; results in `tests/README.md`); the real zip install by core and a client host remain (TESTING.md Layers 1 on staging, 5) |
| Yoast indexables check (does a pushed title render after cache purge?) | **done locally**: `yoast_head_json.title` and the rendered `<title>` carry the pushed title; no indexable-builder call needed |
| Local PHP for linting before pushing | **done on Doug's machine** (PHP 8.4.24 via winget, 2026-09-12); CI still lints every push; see below |

**No further plugin changes are required.** Do not re-implement anything
from the automations repo's brief; it is all here.

## Rolling 0.2.0 out to a site (the one remaining manual step per site)

1. Download `4all-seo-bridge.zip` from
   https://github.com/proximodev/4all-seo-bridge/releases/latest
2. WordPress → **Plugins → Add New → Upload Plugin** → choose the zip →
   **Install Now**. On a site with 0.1.1, WordPress shows "This plugin is
   already installed" with **Replace current with uploaded** — choose that.
   Settings and credentials are untouched (the plugin stores nothing).
3. Verify: `GET https://<site>/wp-json/4all/v1/ping` with an Application
   Password → `"version": "0.2.0"` and a `capabilities` array.
4. From then on: the Plugins screen shows new versions within 12 hours
   (or immediately after **Dashboard → Updates → Check again**), and
   auto-update applies them. Opt a site out with
   `define( 'FOURALL_SEO_BRIDGE_AUTO_UPDATE', false );` in `wp-config.php`.

WP Engine alternative, if SSH is set up for the environment:
`wp plugin install https://github.com/proximodev/4all-seo-bridge/releases/download/v0.2.0/4all-seo-bridge.zip --force --activate`

Which sites have it: the automations repo's gitignored `wp-sites.json`
lists hosts with saved credentials; `wp-seo-push` / `wp-push` print the
site's bridge version on every run and warn when it is below
`BRIDGE_MIN_VERSION` (0.2.0).

## Verifying the update path once one site has 0.2.0

1. 0.2.1 is committed with the version bumped in **both** places and a
   CHANGELOG entry. `git tag v0.2.1 && git push origin main --tags`.
2. Wait for the Release workflow (about half a minute); confirm
   https://github.com/proximodev/4all-seo-bridge/releases/latest/download/manifest.json
   says `0.2.1`.
3. On the site: **Dashboard → Updates → Check again** → the bridge should
   list 0.2.1 with "View details". Update (or let auto-update run) and
   re-check `/ping`.

If the update does not appear: a site still on 0.2.0 caches the manifest
for 12 hours (`fourall_seo_bridge_manifest` site transient); deactivate/
reactivate the plugin to clear it. From 0.2.1, **Check again** clears the
cache itself. Otherwise check that the site can reach `github.com` and
`release-assets.githubusercontent.com` (some hosts block outbound HTTP).

## Releasing (routine)

1. Edit `4all-seo-bridge.php`: `Version:` header **and**
   `FOURALL_SEO_BRIDGE_VERSION` (CI fails if they differ). Bump
   `Tested up to` when a new WordPress major is out.
2. Add a `CHANGELOG.md` entry. Commit to `main` (lint runs).
3. `git tag vX.Y.Z && git push origin main --tags` (tag must equal the
   header version; CI fails otherwise).
4. The release appears with `4all-seo-bridge.zip` and `manifest.json`.
   Sites pick it up on their next check.
5. If the automations tools now depend on the new version, bump
   `BRIDGE_MIN_VERSION` in `4all-automations/scripts/lib/wp-seo-core.js`.

## Getting PHP on the dev machine (optional but handy)

CI is the gate; local PHP just shortens the loop. Installed on Doug's
machine on 2026-09-12: `winget install --id PHP.PHP.8.4` (the `PHP.PHP.8.3`
winget manifest pointed at a php.net URL that 404s; 8.4 installed fine).
It lands in `%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.4_*\`
and winget adds a `php` alias to PATH (open a new terminal). On a fresh
machine, any one of:

- **winget:** `winget search php` and install the current 8.x package, e.g.
  `winget install --id PHP.PHP.8.4` (confirm the exact id from the search
  output; if a download 404s, try the next minor).
- **Scoop:** `scoop install php`.
- **Manual:** download the Thread Safe x64 zip from
  https://windows.php.net/download/, unzip to `C:\php`, add `C:\php` to
  PATH (System Properties → Environment Variables), open a new terminal.

Then, in this project:

```
php -v                       # 8.x
php -l 4all-seo-bridge.php   # "No syntax errors detected"
php tests/run.php            # stub tests, SEO=yoast (also SEO=rankmath / SEO=none)
```

`tests/run.php` stubs the WordPress functions the plugin calls and drives
every handler and filter directly (resolve, clean, target, ping, GET/POST
`/seo`, `/alt`, manifest caching and back-off, update check, details panel,
auto-update, cache invalidation). It runs in CI on PHP 7.4 and 8.3 and is
not shipped in the release zip. It cannot see what real `esc_url_raw`,
`url_to_postid` or Yoast do — that is what the live checks are for.

Optional WordPress coding-standards check (needs Composer — `winget install
Composer.Composer` or https://getcomposer.org/):

```
composer global require --dev wp-coding-standards/wpcs dealerdirect/phpcodesniffer-composer-installer
phpcs --standard=WordPress 4all-seo-bridge.php
```

Optional local WordPress for real testing: **Local** (localwp.com) is the
quickest on Windows; install the plugin from the zip there, add Yoast, and
exercise `/ping`, `GET /seo`, a `POST /seo` dry run, and a `?p=` draft
target. WebStorm's PHP plugin picks up the interpreter from PATH once PHP
is installed (Settings → PHP → CLI Interpreter).

## Layout

```
4all-seo-bridge.php   the plugin (single file; also the source of the version)
README.md             install, updates, endpoints, releasing
CHANGELOG.md
LICENSE               GPL-2.0
.github/workflows/    lint.yml (push/PR: php -l + tests/run.php), release.yml (v* tags)
tests/run.php         stub-based tests, no WordPress needed (not in the zip)
tests/live.mjs        live matrix against a real site (creates + removes its own fixtures)
tests/wp-updater-probe.php  core's real updater, via wp eval-file
tests/README.md       plugin test tooling + Layer 2 results
.wp-env.json          disposable local WordPress + Yoast (npx @wordpress/env start)
TESTING.md            the six-layer plan for plugin + SEO tools (copy of the automations repo's seo-test-plan.md)
HANDOFF.md            this file
```

The zip built by the release workflow unpacks to `4all-seo-bridge/` with
the four files above (minus workflows and this handoff), which is the
folder name WordPress expects for in-place updates.

## Relationship to the automations repo

- Consumers: `wp-seo-push`, `wp-push`, `seo-post` in
  https://github.com/proximodev/4all-automations (private). Their docs:
  `documentation/4all-seo-bridge.md` (behaviour, troubleshooting),
  `documentation/4all-seo-bridge-updates-brief.md` (the design record for
  the self-update work, marked done).
- The automations repo no longer contains the plugin. Edit it **here only**.
- `BRIDGE_MIN_VERSION` over there gates the drift warning; keep it in step
  with whatever the tools rely on.

## Open items

- First live 0.2.0 install and the update-path check above.
- Yoast indexables: verified locally (see `tests/README.md`); re-confirm
  once on a client host with its page cache purged.
- `TESTING.md` Layers 1 (on staging, with `tests/live.mjs`) through 5. The
  real zip install by core has only been reasoned about, not observed (the
  local checkout is bind-mounted; see `tests/README.md` 2c).
- Decide whether auto-update should stay on by default for client sites,
  or be opt-in per site (currently on; one constant flips it).
- Nice to have: `phpcs` in the lint workflow once the file passes it.
- Which sites have the plugin: nothing reports today (GitHub only shows
  aggregate asset download counts). Short term: sweep `/ping` over the
  hosts in the automations repo's `wp-sites.json`; that file is per
  machine and gitignored, so sync one master copy between computers.
  Long term (a later version, not 0.2.1): serve the manifest URL from
  something 4All controls that redirects to the GitHub asset. WordPress
  sends `WordPress/<ver>; <home_url>` as User-Agent, so its access log is
  a complete inventory with no plugin phone-home code.
