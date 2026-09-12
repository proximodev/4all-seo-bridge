# Handoff — 4All SEO Bridge

State as of 2026-09-12, for whoever works in this project next (person or
Claude Code session).

## Where things stand

| Item | Status |
|---|---|
| Plugin code (0.2.0: `GET /seo`, `id` targets, `?p=` and staging-path resolution, post facts, softer cleaning, write log, alt suffix parity) | **done**, in `main` |
| Self-update (`Update URI` + `update_plugins_github.com` reading the latest release's `manifest.json`; details panel; auto-update on by default) | **done**, in 0.2.0 |
| CI: lint on push/PR (PHP 7.4 + 8.3, header/constant version check) | **done**, green |
| CI: release on `v*` tag (lint, tag = header check, zip + manifest attached) | **done**; `v0.2.0` released, assets fetch anonymously |
| Client sites | **all still on 0.1.1** — each needs one manual upload of 0.2.0; afterwards updates arrive through WordPress |
| Live verification of 0.2.0 on a WordPress site (`GET /seo`, a `?p=` push, the Plugins screen offering an update) | **not done** |
| Yoast indexables check (does a pushed title render after cache purge?) | **not done**; needs a live Yoast site |
| Local PHP for linting before pushing | **optional** — CI lints every push; see below |

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

1. Make a trivial change (or none), bump the version to `0.2.1` in **both**
   the `Version:` header and `FOURALL_SEO_BRIDGE_VERSION`, add a CHANGELOG
   line, commit, `git tag v0.2.1 && git push origin main --tags`.
2. Wait for the Release workflow (about half a minute); confirm
   https://github.com/proximodev/4all-seo-bridge/releases/latest/download/manifest.json
   says `0.2.1`.
3. On the site: **Dashboard → Updates → Check again** → the bridge should
   list 0.2.1 with "View details". Update (or let auto-update run) and
   re-check `/ping`.

If the update does not appear: the site caches the manifest for 12 hours
(`fourall_seo_bridge_manifest` site transient); deactivate/reactivate the
plugin to clear it, or check that the site can reach `github.com` and
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

CI is the gate; local PHP just shortens the loop. Any one of:

- **winget:** `winget search php` and install the current 8.x package, e.g.
  `winget install --id PHP.PHP.8.3` (confirm the exact id from the search
  output).
- **Scoop:** `scoop install php`.
- **Manual:** download the Thread Safe x64 zip from
  https://windows.php.net/download/, unzip to `C:\php`, add `C:\php` to
  PATH (System Properties → Environment Variables), open a new terminal.

Then, in this project:

```
php -v                       # 8.x
php -l 4all-seo-bridge.php   # "No syntax errors detected"
```

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
.github/workflows/    lint.yml (push/PR), release.yml (v* tags)
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
- Yoast indexables: after a real `POST /seo --apply` and a cache purge,
  confirm the new `<title>` renders. If Yoast still serves the old one,
  add a guarded call to Yoast's indexable builder after `update_post_meta`.
- Decide whether auto-update should stay on by default for client sites,
  or be opt-in per site (currently on; one constant flips it).
- Nice to have: `phpcs` in the lint workflow once the file passes it.
