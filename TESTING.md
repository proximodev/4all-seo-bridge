> Copy of `documentation/seo-test-plan.md` in the private 4all-automations repo (kept in step by hand). Layers 0, 1 and the plugin parts of 4 concern this plugin directly; layers 2–5 drive it through the automations tools.

# Test plan — SEO scripts and the bridge plugin (post-mode, drafts, H1, slugs, 0.2.x)

How to exercise everything built 2026-09-11/12 without touching a client's
live data until the last step. Work top to bottom; each layer assumes the
one above passed. Tick the boxes in a copy of this file or in the run log.

## Safety rules that make this exhaustive *and* safe

1. **Never test on a client's sheet.** In Drive, *File → Make a copy* of a
   real SEO sheet (iO Theater is a good one: Pages, Images, Keywords with
   topics) and use the copy. Delete it at the end. Nothing in the scripts
   writes to a sheet it was not given.
2. **Never `--apply` against a production site during testing.** Every push
   path is a dry run by default and prints the exact before/after; the dry
   run is the test. Apply only on the sandbox WordPress (layer 1) or, at the
   very end, on one production post you choose.
3. **Sandbox WordPress.** Either a WP Engine staging environment for a client
   that has Yoast, or **Local** (localwp.com) on your machine with Yoast
   installed. It needs: an Editor/Admin Application Password, one published
   post with a featured image, one published page, and (created during
   layer 4) one draft with two inline images and no featured image.
   Save its credentials in `wp-sites.json` by answering the prompt once.
4. **Keep `--debug`** on script runs during testing: it writes every packet
   the model saw to `temp/`, which is how you check the flows sent the
   right inputs (`want_h1`, `topic`, `post`, inherited keywords).
5. **Cost.** A post-mode run on 1–3 posts is a few cents of model time; a
   site run on the copied sheet is the usual site cost. `output/seo-runs.jsonl`
   records tokens per run.

## What "pass" looks like, per layer

### Layer 0 — automated, no network (5 min)

- [ ] Automations repo: `npm test` → 78 passing.
- [ ] Plugin repo: `php -l 4all-seo-bridge.php`; `php tests/run.php` with
      `SEO=yoast`, `SEO=rankmath`, `SEO=none`; `git log` shows the lint
      workflow green on the last push.

### Layer 1 — the plugin on the sandbox WordPress (30 min)

Install the 0.2.x zip (Plugins → Add New → Upload → Replace). Use a REST
client or `curl -u user:app-password`.

| Check | Call | Pass when |
|---|---|---|
| Version | `GET /wp-json/4all/v1/ping` | `version` is the release, `capabilities` lists `seo_read`, `resolve_query_vars` |
| Read by URL | `GET /4all/v1/seo?url=<published post permalink>` | `current.title/metadesc` match Yoast's fields; `post_type: post`, `post_status: publish`, `slug`, `url` |
| Read by id | `GET /4all/v1/seo?id=<same id>` | identical response |
| Draft target | `GET /4all/v1/seo?url=https://<site>/?p=<draft id>` | resolves the draft (`post_status: draft`), **not** the homepage |
| Bad query var | `…/?p=abc` | 400 `bad_query_var` (0.2.1) |
| Unknown id | `…/?p=99999999` | 404 `not_found` |
| Front page | `GET /4all/v1/seo?url=https://<site>/` | the static front page, or 422 `blog_index_not_supported` on a posts-index home |
| Staging path match | with the site's *production* hostname in the URL but the staging host in the request: `GET …/4all/v1/seo?url=https://www.<prod>/<known-path>/` | resolves by path (`path_match` internally) |
| Dry-run write | `POST /4all/v1/seo` `{ url, title: "Test <b>bold</b> & < 5%", dry_run: true }` | `before` unchanged on the site; `after.title` is `Test bold & < 5%` (tags stripped, `<` and `%` kept); `changed.title: true`; nothing written |
| Real write + log | same without `dry_run` on a **test post** | Yoast field updated; `wp-content/debug.log` / server log has one `[4all-seo-bridge]` line |
| Yoast renders it | purge cache, view page source | `<title>` shows the new value (this is the open "indexables" question — if the old title persists, note it) |
| Alt by URL | `POST /4all/v1/alt` with a `-scaled` / `-300x200` variant URL, `dry_run: true` | resolves to the attachment |
| Empty value | `POST /4all/v1/seo` `{ url, title: "" }` | treated as a value change to empty? **Expected:** the CLI never sends empties; the bridge writes what it is sent — confirm the CLI side skips blanks (layer 3) |

Self-update (needs two releases):

- [ ] With 0.2.0 installed, tag and release 0.2.1 (the other session has it
      ready). Dashboard → Updates → *Check again* → the bridge lists 0.2.1
      with a working *View details*.
- [ ] Update from the screen → `/ping` shows 0.2.1.
- [ ] Auto-update: install 0.2.0 again, release 0.2.2 (or re-tag), wait for
      the next cron pass (or trigger with `wp cron event run wp_update_plugins`
      then `wp_maybe_auto_update`) → updates without a click.
- [ ] Opt-out: add `define( 'FOURALL_SEO_BRIDGE_AUTO_UPDATE', false );`,
      repeat → offered but not auto-applied.

### Layer 2 — site run on the copied sheet (20 min + model time)

`seo-meta --sheet "<copy>" --h1 --slug --debug`, page phase only is enough
(answer no to images), review yes.

- [ ] Log shows `Loaded N pages, 90 keyword rows` (header-based read) and
      `Writer: … · H1 on · slug all`.
- [ ] `H1 (Revised)` inserted after `H1`; `Slug (Revised)` appended; both
      filled only where the writer proposed something.
- [ ] `temp/seo-page-*-b01-input.json`: `want_h1: true`, `want_slug: "all"`,
      keywords keyed by URL (not by topic name).
- [ ] Lint block prints, and Review Notes carry `[lint]` lines. Look for at
      least one of `meta_keyword_absent`, `hub_breadth_lost`, `h1_*`, `slug_*`
      on a site of this size; none is also fine, but check the categories
      spelled in the notes match `seo-lint.js`.
- [ ] Review replaced values show `[review] replaced …: was «…»`; an H1
      replacement (if any) lands in `H1 (Revised)`.
- [ ] `output/seo-runs.jsonl` last line has `h1: true`, `h1_proposed`,
      `slug_policy: "all"`, `slugs_proposed`.
- [ ] Re-run `seo-review --sheet "<copy>" --only <one landing page>` →
      only that page is reviewed; H1 and slug columns are linted.

### Layer 3 — post mode with live posts, on the copied sheet (20 min + model)

Pick two published blog posts from the site, one already on the sheet and
one not. `seo-post --sheet "<copy>" --posts <url1>,<url2> --debug`.

- [ ] Scan: both fetched; Images tab rows for them replaced/added.
- [ ] Upsert log: `Pages: 1 added, 1 refreshed`; the refreshed row kept its
      Revised / Review Notes / Topic cells; only scan columns changed.
- [ ] Topic prompt lists `Main › Sub (N keywords)`; the "use for remaining"
      shortcut works; `Main Topic` / `Sub Topic` written (columns appended).
- [ ] Packet (`temp/…-b01-input.json`): each post has `topic { main, sub,
      landing_page }` and keyword rows with `inherited: true` keyed to the
      post URL; `want_h1: true`, `want_slug: "drafts"` (so **no slug** for
      these published posts), no `post` field (not read via REST).
- [ ] Titles carry the subtopic phrase plus the post's angle, not the
      landing page's title; H1 proposed; Slug (Revised) **blank**.
- [ ] Review scoped to the two posts only.
- [ ] Push offer: accept → dry runs print for pages and images (no slug
      half: no drafts). **Decline apply.** The printed `wp-push … --only …
      --apply` command is correct.
- [ ] Run the post again → topic prompt defaults to the saved topic;
      overwrite confirmation appears; nothing duplicated.
- [ ] Missing-tab path: copy the sheet again, delete its Images tab, run
      post mode → "Added missing tab(s) from the template: Images".

### Layer 4 — drafts, sandbox WordPress + copied sheet (30 min + model)

On the sandbox: create a draft post with a headline, ~600 words, two
inline images, **no** featured image, in the same topic area as one of the
sheet's subtopics. Copy its editor link.

- [ ] `seo-post --sheet "<copy>" --posts "<editor link>" --debug` → log:
      `1 post(s) by id on <host> — reading through WordPress REST`,
      `Drafts in this run: 1`, `Added post columns: WP Post ID, Status, …`.
- [ ] Pages row keyed `https://<host>/?p=<id>`, `Status: draft`, `WP Post ID`,
      current `Slug`; Images rows for the two inline images (from
      `content.rendered`, `?p=` page URL).
- [ ] Packet: page has `post { id, status: "draft", slug, featured_image:
      false }`, `want_slug: "drafts"`, `want_featured_image: true`; body
      text present (REST, not HTTP).
- [ ] Output: title, meta, `H1 (Revised)`, `Slug (Revised)` (2–6 words,
      keyword-led), `Featured Image (Recommended)` / `Featured Filename` /
      `Featured Alt` filled; lint shows any `slug_*` findings.
- [ ] Image phase: alts for the two inline images; body context came
      through (`Pre-fetched 1 page(s)`, no failure).
- [ ] Review: works on the draft (`seo-review --sheet "<copy>" --only
      "https://<host>/?p=<id>"` standalone too).
- [ ] Push offer: pages dry run **succeeds on the `?p=` URL** (bridge 0.2.x)
      and shows before/after; images dry run; **slug half** dry run shows
      the slug change. Apply on the sandbox → Yoast fields, alts, and slug
      updated; the plan warned about nothing unexpected.
- [ ] Drift warning: point `wp-push` at a site still on 0.1.1 with a `?p=`
      URL in scope → the warning names 0.1.1 vs 0.2.0 and says drafts will
      come back "not found".
- [ ] Publish the draft in WordPress, run post mode on it again (editor
      link or permalink) → `1 refreshed (1 re-keyed to the live URL)`; row
      URL is now the permalink; `Status: publish`; **Slug (Revised)** no
      longer proposed (published) unless `--slug`.
- [ ] `wp-push "<copy>" --slug --only <permalink>` → the slug change is
      **held** ("published — needs a redirect"); with
      `--allow-slug-change` it is planned; apply on the sandbox → check the
      old URL (redirect only if Yoast Premium/RankMath).
- [ ] `wp-push "<copy>" --headline --only <permalink>` → `H1 (Revised)`
      planned for the post; try the same on a **page** row with an H1 →
      held ("visible H1 is rarely post_title").
- [ ] Not-allowed path: an Application Password for a *Contributor* on a
      draft by another author → `Not fetchable (not allowed)`.
- [ ] No-credentials path: decline the prompt → `Not fetchable (no
      credentials)`; run continues with the other posts.

### Layer 5 — one real post on one client site (15 min)

Only after layers 1–4. Choose one published post the client will not miss
and one site that has 0.2.x installed.

- [ ] `seo-post --sheet "<the client's real sheet>" --posts <permalink>`
      end to end; at the push offer accept **apply** for pages + images.
- [ ] Verify in WP admin (Yoast fields, media alt), purge FlyingPress + WP
      Engine cache, view source.
- [ ] Note the run in `output/seo-runs.jsonl` and, if anything looked off,
      the Review Notes `was «…»` values are the rollback reference.

## Rollback, per kind of change

| Change | Undo |
|---|---|
| Sheet edits | you were on a copy; delete it. On a real sheet, Review Notes hold the previous Revised values (`was «…»`), and the scan columns are re-scanned every run |
| Yoast title / meta | no WordPress revision; re-push the previous value (`before` in the dry-run plan, or the Review Notes) or edit in Yoast |
| Media alt | same: re-push or edit in the media library |
| Slug | WordPress keeps old slugs on posts (`_wp_old_slug`) and redirects them itself for posts; for pages add a redirect. Or set the slug back |
| Headline (`post_title`) | a post revision exists; restore from Revisions |
| Plugin | Plugins → Add New → Upload the previous release zip → Replace |

## Order and time

Layer 0 (5 min) → 1 (30) → 2 (20 + model) → 3 (20 + model) → 4 (30 + model)
→ 5 (15). About three hours end to end, spread over as many sessions as
you like; layers 2–4 can each run in one sitting.

## Recording results

Append a dated section to this file, or a line per layer to the run log,
with anything that did not match the "pass when" column. Two things are
expected to be learned here rather than known: whether Yoast serves a
pushed title without an indexable rebuild (layer 1), and how the writer's
slugs and featured-image briefs read on a real draft (layer 4).
