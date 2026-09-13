# Testing the 4All SEO Bridge

Three layers, from safest to most real. Only move to the next layer when
the previous one is clean.

| Layer | What it proves | Needs | Cost |
|---|---|---|---|
| 1. Stub tests | every branch of the plugin's own logic | PHP on the dev machine (CI runs it too) | seconds |
| 2. Local WordPress | real `url_to_postid`, REST validation, Yoast, core's updater | Docker + Node (`wp-env`) | minutes |
| 3. Staging, then one production site | the real hosts, caches, credentials and the automations CLI | a WP Engine staging env; an Application Password | one careful afternoon |

## Layer 1 — stub tests (no WordPress)

```
php -l 4all-seo-bridge.php
php tests/run.php                 # SEO=yoast (default)
SEO=rankmath php tests/run.php
SEO=none php tests/run.php
```

`tests/run.php` stubs the WordPress functions the plugin calls and drives
every handler and filter directly. CI (`lint.yml`) runs it on PHP 7.4 and
8.3 for every push. It cannot see what real WordPress, Yoast or a host
does — that is Layers 2 and 3.

## Layer 2 — disposable local WordPress (`wp-env`)

`.wp-env.json` in this repo maps the checkout in as the `4all-seo-bridge`
plugin and installs Yoast. Docker Desktop must be running.

```
npx @wordpress/env start          # first run downloads WordPress + images
npx @wordpress/env run cli wp rewrite structure '/%postname%/'
npx @wordpress/env run cli wp user application-password create admin bridge --porcelain
```

Site: http://localhost:8888 (admin / password). The last command prints an
Application Password; use it below.

### 2a. Live matrix (`tests/live.mjs`)

```
BASE=http://localhost:8888 WP_USER=admin WP_PASS='<app password>' node tests/live.mjs
```

Creates its own fixtures through core REST (published post, parent +
child page, draft post, 1×1 PNG), then checks:

- `/ping`: version, `seo_plugin`, capabilities; anonymous refused.
- Resolution: permalink, `id`, nested page, fragment, draft by `?p=` and by
  preview link, `?page_id=`, `?attachment_id=`, foreign host + same path
  (`path_match`), foreign host + `?p=`.
- Errors with codes: `?p=abc` / `?p=` / `?p=0` → 400 `bad_query_var`
  (also on a real permalink), unknown `?p=` / id / path → 404, no target →
  400, `id=abc` → 400 from the REST schema, front page → page or 422.
- `POST /seo`: dry run writes nothing; apply with quotes, apostrophe,
  ampersand, en dash, accents, `<` + space; GET reads them back; same value
  → unchanged; empty string clears; draft push by `?p=`; `?p=abc` → 400.
- Yoast: after the apply, `yoast_head_json.title` on the core REST post and
  the rendered `<title>` both contain the pushed title (the indexables
  check).
- `POST /alt`: dry run, `-300x200` and `-scaled` suffixes, apply, core REST
  reads the alt back, unknown image → 404, missing `alt` → 400.
- Optional Subscriber checks (`SUB_USER` / `SUB_PASS`): every route → 403
  and nothing written.
- Restores the SEO fields it changed and deletes its fixtures (`KEEP=1` to
  keep them).

Subscriber credentials for the permission checks:

```
npx @wordpress/env run cli wp user create sub sub@example.com --role=subscriber --user_pass=subpass
npx @wordpress/env run cli wp user application-password create sub bridge --porcelain
```

### 2b. Posts-index front page

```
npx @wordpress/env run cli wp option update show_on_front posts
curl -u "admin:<app password>" "http://localhost:8888/wp-json/4all/v1/seo?url=http://localhost:8888/"
# → 422 blog_index_not_supported
npx @wordpress/env run cli wp option update show_on_front page
npx @wordpress/env run cli wp option update page_on_front <a page id>
# → 200 with that page
```

### 2c. Updater, without publishing a release

Detection, details panel, auto-update filter and Check again, against
core's real updater, in one command (installs nothing, leaves no
transients behind):

```
npx @wordpress/env run cli wp eval-file wp-content/plugins/4all-seo-bridge/tests/wp-updater-probe.php
```

It fetches the real manifest from GitHub, confirms core files the
installed version under `no_update` or `response` correctly, seeds a fake
9.9.9 manifest and confirms core lists it (package, slug, id), builds the
details panel, checks the `auto_update_plugin` answer, and simulates
**Check again** as a logged-out user (cache kept) and as an admin (cache
dropped, core refetches the real manifest).

To also exercise the **install** step (core downloading and unpacking the
zip in place), seed the cache with a locally served zip as below. Do not
run `wp plugin update` while `.wp-env.json` maps this checkout in as the
plugin: the upgrader deletes the plugin folder first, and that folder is
your working copy. Remove `"."` from `plugins` and restart, or use a
plain Local/Docker WordPress, for that step.

1. Install the **previous** release on the local site so there is
   something to update from:
   ```
   npx @wordpress/env run cli wp plugin install https://github.com/proximodev/4all-seo-bridge/releases/download/v0.2.0/4all-seo-bridge.zip --force --activate
   ```
   (`wp-env` maps this checkout over the plugin folder; to test the
   upgrade for real, temporarily remove `"."` from `plugins` in
   `.wp-env.json` and restart, or use a plain Local/Docker WordPress.)
2. Build the candidate zip the way the release workflow does and serve it
   from the site's uploads folder:
   ```
   mkdir -p build/4all-seo-bridge && cp 4all-seo-bridge.php LICENSE README.md CHANGELOG.md build/4all-seo-bridge/
   (cd build && zip -qr ../4all-seo-bridge.zip 4all-seo-bridge)
   npx @wordpress/env run cli mkdir -p wp-content/uploads/bridge
   docker cp 4all-seo-bridge.zip $(docker ps -qf name=wordpress):/var/www/html/wp-content/uploads/bridge/
   ```
3. Seed the manifest cache:
   ```
   npx @wordpress/env run cli wp eval 'set_site_transient("fourall_seo_bridge_manifest", array("name"=>"4All SEO Bridge","slug"=>"4all-seo-bridge","version"=>"0.2.1","download_url"=>"http://localhost:8888/wp-content/uploads/bridge/4all-seo-bridge.zip","requires"=>"6.0","requires_php"=>"7.4","tested"=>"6.6"), 43200);'
   npx @wordpress/env run cli wp plugin list --fields=name,version,update,update_version
   ```
   Expect `update=available`, `update_version=0.2.1`. **Dashboard →
   Updates** lists it; **View details** opens the panel.
4. Apply it: `npx @wordpress/env run cli wp plugin update 4all-seo-bridge` (or the
   screen, or `wp cron event run wp_version_check` for the auto-update
   path). `/ping` → `0.2.1`; the folder is still `4all-seo-bridge/`.
5. Check again clears the cache: seed the transient as in step 3, then
   load `http://localhost:8888/wp-admin/update-core.php?force-check=1`
   logged in as admin. `wp transient get fourall_seo_bridge_manifest
   --network` → empty (core's own check then refetches the real manifest).

Alternatively, on any site, `define( 'FOURALL_SEO_BRIDGE_MANIFEST_URL',
'https://…/manifest.json' );` in `wp-config.php` points the plugin at a
test manifest (0.2.1+).

Tear down: `npx @wordpress/env stop` (keeps the site) or
`npx @wordpress/env destroy`.

### Layer 2 results — 2026-09-12, WordPress 7.1 / PHP 8.x / Yoast 28.4, plugin 0.2.1

| Check | Result |
|---|---|
| `tests/live.mjs` with Subscriber checks | 79 checks, 0 failed |
| Yoast renders the pushed title (`yoast_head_json.title` and the page `<title>`) | passes — no indexable-builder call needed |
| Posts-index front page → 422 `blog_index_not_supported`; static front page → that page | as designed |
| `tests/wp-updater-probe.php` | all ok — real manifest fetched from GitHub inside the container |
| Real install/upgrade of the zip by core | **not run** (checkout is bind-mounted; see 2c) |

Found by Layer 2 and fixed in 0.2.1: Yoast's own sanitizer stores `<` and
`&` as entities on its meta keys, so `after` now reports what was
actually stored and a stored value that decodes to the requested one
counts as unchanged (otherwise every run re-pushed such rows).

## Layer 3 — staging, then production

1. Build the zip (step 2c.2) and upload it to a WP Engine **staging**
   environment via Plugins → Add New → Upload → Replace.
2. Read-only pass against it:
   ```
   READONLY=1 URLS=https://staging.example/a/,https://staging.example/b/ BASE=https://staging.example WP_USER=<user> WP_PASS='<app password>' node tests/live.mjs
   ```
3. Full pass (fixtures are created and removed; existing content untouched):
   ```
   BASE=https://staging.example WP_USER=<user> WP_PASS='<app password>' node tests/live.mjs
   ```
4. From the automations repo: `wp-seo-push` / `wp-push` in dry-run mode
   against staging; the drift warning should be gone and a `?p=` draft
   push should resolve.
5. Tag the release (`git tag vX.Y.Z && git push origin --tags`).
6. **One** production site first (ioimprov.com is the one already exercised
   live): upload the release zip, `READONLY=1` pass, one real push on one
   post with the CLI, view source after a cache purge. Then the rest.

## Safety rails

- Every `POST` response carries `before`; keep the JSON and any value can
  be restored by pushing it back.
- The plugin stores no settings; rolling back is re-uploading the previous
  zip from GitHub Releases.
- Every applied write logs one `[4all-seo-bridge]` line on the host.
- Use `dry_run` / the CLI's dry-run mode until GET results look right.
- `define( 'FOURALL_SEO_BRIDGE_AUTO_UPDATE', false );` in a production
  `wp-config.php` holds a site on its current version while you watch the
  update path on staging.
