# SEO Pipeline — Improvements

Status page for what comes next in the SEO metadata pipeline. Each section
is a proposal with a shape, a size, and open decisions; nothing here is
built unless marked so. The live description of the pipeline is
[`seo-metadata-pipeline.md`](seo-metadata-pipeline.md); the operator guide
for posts is [`seo-post.md`](seo-post.md).

History: the September 2026 rebuild's design record and the post-mode plan
are in `archive/` ([`seo-pipeline-improvements.md`](archive/seo-pipeline-improvements.md),
[`seo-post-optimize-plan.md`](archive/seo-post-optimize-plan.md)).

## Status at a glance (2026-09-12)

Three levels: **built** (code, tests, docs in `master`), **verified**
(exercised against a real sheet or site), **pending** (not yet done).

| Feature | Built | Verified | Pending |
|---|---|---|---|
| Keywords tab read by header (fix) | yes, `4064125` | live: iO Theater re-run 2026-09-11 (10 landing pages mapped, keyword-led titles) | — |
| Lint: meta keyword coverage, hub breadth, rewording-tolerant match | yes | unit tests; iO re-run confirmed the two regressions it targets | first run where the reviewer fixes a `meta_keyword_absent` |
| Post mode (`seo-post`): scan, upsert, topic prompt, scoped run, push offer | yes | tab-copy path on a throwaway sheet; upsert/topic prompt in tests only | **first live run on a client sheet** |
| Topic inheritance (Main › Sub Topic → keywords, `topic` packet) | yes | unit tests; keyword context loader against the iO sheet | first post written with an inherited theme |
| `wp-push` (pages + images, `--only`) | yes | image half dry run on the iO sheet with a URL filter | apply on a live site via `wp-push` (the underlying cores are the ones already used) |
| Missing Pages / Images / Keywords tabs added from the template | yes | live on a throwaway sheet, idempotent | — |
| H1 output (`H1 (Revised)`) | yes, `8ba2eb5` | column insert/write/idempotence on a throwaway sheet; lint unit tests | **first model run with `want_h1`**; H1 push (`--headline`) on a live post |
| Drafts: REST reader, `?p=` row key, post columns, re-key after publish | yes, `1024c71` | reader, bridge fallback and draft-aware prefetch live against ioimprov.com (published post via editor link) | **first real draft end to end** |
| Slugs (`Slug (Revised)`, policy none/drafts/all, lint) | yes | unit tests | first model run; `wp-push --slug` on a draft |
| Featured-image brief + filename + alt | yes | unit coverage of the engine's sanitising only | first model run on a draft without a featured image |
| `wp-push --slug / --headline` (`wp-post-core`) | yes | not run against a site | dry run + apply on a draft |
| Bridge plugin 0.2.0 | yes — own repo, [v0.2.0 released](https://github.com/proximodev/4all-seo-bridge/releases) | CI lint on PHP 7.4 / 8.3; release assets fetch anonymously | **upload once per site** (all still on 0.1.1); verify `GET /seo` and a `?p=` push live; Yoast indexables check |
| Bridge self-update (`Update URI` + release manifest, auto-update on) | yes, in 0.2.0 | manifest URL resolves; not yet observed on a WordPress Plugins screen | first site on 0.2.0 sees v0.2.1 offered |
| CLI drift warning (`BRIDGE_MIN_VERSION`) | yes | — | see it fire on a 0.1.1 site |
| Review skill writing slug replacements | not started | — | slugs are informational to the reviewer |

| # | Improvement | Status |
|---|---|---|
| 1 | H1 output (`H1 (Revised)`) — on by default for posts, `--h1` for page runs | built 2026-09-12; push (`--headline`) built, unverified |
| 2 | Draft posts — read and push by post ID; slug and featured-image recommendation | built 2026-09-12; needs bridge 0.2.0 on the site; no end-to-end run yet |
| 3 | Bridge plugin 0.2.0 | released 2026-09-12 from its own repo, self-updating; sites need one manual upload |
| 4 | Follow-ups from post mode | small, unscheduled |

---

## 1. H1 output

> **Built 2026-09-12** as specified below, with the column named
> `H1 (Revised)`. Not pushed. The *Push* paragraph is the remaining piece.

**Why.** The H1 is the strongest on-page relevance signal after the title,
and scans routinely surface "Home", "Welcome", a bare service name, or an
H1 at odds with the new title. The writer already reads the H1 to ground
the title and meta; proposing a better one costs a few dozen tokens per
page, and writing title, H1 and meta in one pass is the only way to keep
the three aligned. For a **post** the H1 is the article headline — the
thing the writer most often wants changed — so post mode should propose it
every time. For a **page** the H1 is usually a designed heading (hero,
Elementor, block) that the operator applies by hand, so it stays opt-in.

**Defaults.**

| Run | H1 output | Flag |
|---|---|---|
| Post mode (`seo-post`, `seo-meta --posts`) | **on** | `--no-h1` to turn off |
| Site / page run (`seo-meta`, `seo-page`) | off | `--h1` to turn on |

When off, the packet says `want_h1: false`, the writer emits `h1_revised:
null` for every page, and the column is not written or created.

**Shape.**

- **Sheet.** Pages column `H1 (Revised)`, inserted after `H1` the first
  time a run has H1 output on (same idempotent insert as the other Revised
  columns). Blank means "keep the current H1", so on a page run the column
  doubles as an attention list. The push tools ignore it (see *Push*).
- **Skill (`seo-page-metadata`).** Input gains `want_h1: true|false`.
  Output gains `h1_revised: string | null` per rewrite. Rules: share the
  title's subject without duplicating the composed title word for word;
  carry the primary keyword (or the subtopic phrase on a supporting post)
  naturally; one line, no brand suffix, no location padding, no trailing
  punctuation; on a page, propose only when the current H1 is missing,
  generic, off-keyword or at odds with the body or the new title; on a
  post, always propose unless the current headline is already the best
  version (then `null`, with the reason in `report.left_strong`). Cap 70
  characters, enforced in code like the others.
- **Engine.** Schema field, validator (cap, non-empty when not null),
  reconcile unchanged, `h1_revised` written by `writeRevisedColumns` when
  the column exists.
- **Lint (informational).** `h1_missing` (page has no H1 and none
  proposed), `h1_duplicates_title` (proposed H1 equals the composed title
  or the title lead), `h1_keyword_absent` (proposed H1 carries neither the
  primary keyword nor a rewording — posts only, since page H1s are often
  deliberately short).
- **Review.** `h1_revised` travels with title and meta in the review
  input; the reviewer may flag or replace it like the other fields, and the
  replacement is written in place with the prior value noted.
- **Metrics.** `h1_proposed` count per run; a page run proposing an H1 for
  most pages is prompt drift and shows up there.
- **Push.** Not pushed in this step. A WordPress *page's* visible H1 is
  rarely `post_title`; a *post's* usually is, so a later option is
  "`wp-push --h1`" that sets `post_title` for posts only, through the
  bridge, with the same dry run and diff. That needs the bridge to return
  `post_type` so pages are refused (bridge to-do item 6).

**Size.** Skill input/output fields and two rule paragraphs, engine schema
and cap, one sheet column, three lint checks with tests, review input and
notes, the `--h1` / `--no-h1` flags in three CLIs and the orchestrator, a
metrics field, the agent-skills rebuild, docs. Roughly the size of the
zero-view change.

**Open decisions.**

- Column name: `H1 (Revised)` for consistency with the other Revised
  columns, even though it is not pushed; the earlier proposal said
  `H1 (Suggested)` to signal that. Preference: `H1 (Revised)`, with the
  push tools' "reads only title and meta" rule stated in the guide.
- Whether the sheet-driven `seo-review` should lint an `H1 (Revised)`
  column an operator filled by hand (yes, if the column exists).

---

## 2. Draft posts

> **Built 2026-09-12** along the build order below: bridge 0.2.0,
> `wp-post-reader.js`, the REST branch in post mode with `WP Post ID` /
> `Status` / `Slug` columns and re-keying after publish, `slug_revised` +
> the featured-image trio in the page skill and lint, and `wp-push --slug /
> --headline` (`wp-post-core.js`). Remaining: upload 0.2.0 to each site,
> run one real draft end to end, and the review skill still treats slugs as
> informational (no slug replacements).

**Today (before this work).** Post mode needs a live URL: the scanner fetches the page over
HTTP and Playwright, the bridge resolves pushes with `url_to_postid`, and
every sheet row is keyed by URL. A draft returns 404 to the scanner and is
skipped. The workflow gap is real — the moment to set title, meta, slug and
headline is before publish, not after.

**What a draft run should produce.** The base outputs (title, meta, H1,
image filenames and alts), plus:

- **Slug** — `Slug (Revised)`: the keyword-led URL slug. **Decided
  2026-09-12:** proposed **by default for drafts**, and **opt-in with
  `--slug`** for published posts and for pages (mirroring `--h1`), because
  a live slug change is a redirect decision. Written to the sheet; the
  push writes it only for drafts unless `--allow-slug-change`.
- **Image outputs** (decided 2026-09-12):
  - *images already in the draft*, including a set featured image:
    filename and alt, exactly what the image skill does today, read from
    the draft's content instead of a rendered page;
  - *no featured image set*: a **featured-image recommendation** — a short
    descriptive brief written from the post's text (subject, framing,
    orientation), **paired with a ready filename and alt** — so the operator
    can source or shoot the image and drop it in with its metadata done.
    Inline "add an image here" suggestions are out of scope for now.

### Options for reading and writing drafts

| | A. Core WordPress REST | B. Bridge endpoint | C. Paste the text |
|---|---|---|---|
| Read a draft | `GET /wp/v2/posts/<id>?context=edit` with the Application Password: title, slug, status, `content.raw/rendered`, excerpt, `featured_media`; images by parsing the rendered content; featured image via `/wp/v2/media/<id>` | New `GET /4all/v1/post?id=` returning the same plus the SEO title/meta and rendered text, in one call | Operator pastes the draft body; no WordPress access |
| SEO title / meta | Read: Yoast/RankMath expose them in REST only for some setups. Write: bridge `POST /seo` with a `?p=<id>` URL once the resolver reads the query var (bridge bug below; `url_to_postid` itself handles `?p=` for any status) | Bridge, by `id` | Recommendations only |
| Slug, H1 (post title) | `POST /wp/v2/posts/<id>` with `slug`, `title` — core REST, no plugin change | Bridge `POST /post` (thin wrapper over the same) | Manual |
| Image alts | Core REST media, as `wp-alt-push` does today | Same | Manual |
| Plugin change | none | new routes, version bump, re-upload per site | none |
| Weakness | SEO *reads* for drafts are plugin-dependent; two round-trips per post | Another deploy step per client site | No automation |

**Decision (2026-09-12): A — core REST.** It already gives everything
needed to read a draft and to write slug, headline and alts, using the
credentials `wp-push` already stores. SEO title and meta go through the
bridge by addressing the post as `https://host/?p=<id>`.

**Bridge bug this exposes.** `fourall_seo_bridge_resolve` treats an empty
path as the static front page, so `https://host/?p=123` resolves to the
homepage today, not post 123. Fix: read the `p` / `page_id` query var
before the path check. Required for drafts; ships with the 0.2.0 items
(`post_status` + `post_type` in the `/seo` response, so the plan can say
"draft" and refuse pages for H1/slug writes). C stays a non-goal.

**What the REST route needs, per draft.**

1. *Input:* the draft URL as WordPress shows it — preview
   (`/?p=123&preview=true`), editor (`/wp-admin/post.php?post=123&action=edit`),
   or `?page_id=123`. Post mode extracts the ID and the host; no new flag.
2. *Auth:* the site's Application Password (Editor/Admin; `context=edit`
   is what exposes drafts). HTTPS required; REST blocked for anonymous
   users is fine (Basic auth is authenticated).
3. *Reads:* `GET /wp/v2/posts/{id}?context=edit` (`id, type, status, link,
   slug, title.raw, content.rendered, excerpt.raw, featured_media, modified`);
   404 → retry `/wp/v2/pages/{id}`; custom types via `/wp/v2/types`.
   `GET /wp/v2/media/{featured_media}` for the featured image. Inline
   images parsed from `content.rendered` (`src`, `alt`, size attrs,
   `wp-image-{id}` → attachment id). Body text = rendered content through
   the scanner's extractor. Image bytes from the uploads folder (public
   once uploaded). Current SEO title/meta via the bridge's dry-run
   `before` values until the 0.2.0 `GET` exists.
4. *Writes:* slug and headline `POST /wp/v2/posts/{id}`; SEO title/meta
   via bridge `/seo` with the `?p=` URL; alts via media REST as today.

### How a draft moves through the sheet

- **Input.** `--posts` accepts, in addition to live URLs: a WordPress edit
  URL (`…/wp-admin/post.php?post=123&action=edit`), a preview URL
  (`…/?p=123&preview=true`), or `id:123` with `--site`. Anything with a
  post ID is read through REST instead of scanned.
- **Row key.** REST cannot predict a draft's final permalink (the
  permalink structure is admin-only), so the Pages row URL is the
  `https://host/?p=<id>` form, which WordPress honours forever and
  redirects after publish, plus a `WP Post ID` column. The first run after
  publish re-keys the row (and its Images rows) to the live permalink REST
  reports in `link`.
- **Status.** A `Status` cell (`draft`, `pending`, `future`, `publish`)
  from REST, refreshed each run, so the sheet shows what is live.
- **Body for the writer.** `content.rendered` stripped to text at the
  usual budgets. Images: `<img>` tags in the rendered content plus the
  featured image, with bytes fetched from the media library (public even
  for drafts once uploaded) for the vision pass.
- **Push.** `wp-push` learns the same inputs: SEO title/meta through the
  bridge by `?p=<id>`, slug/title through core REST, alts as today. A slug
  write on a **published** post is refused unless `--allow-slug-change`,
  because it needs a redirect.

### Slug rules (for the skill and the lint)

Lowercase, hyphenated, ASCII; 3–6 words; carries the primary keyword or
the subtopic phrase; no stop words, dates, or brand; unique on the site
(checked with `GET /wp/v2/posts?slug=` before the write); never proposed
for a published post unless asked. Lint: `slug_format`, `slug_length`,
`slug_keyword_absent`, `slug_taken`.

### Featured-image recommendation

Emitted only when the draft has no featured image. One structured entry:
`{ brief: "…", filename: "…", alt: "…" }` — the brief is two or three
sentences describing the image to source (subject, framing, orientation,
what must and must not appear), grounded in the post text with no invented
specifics; filename and alt follow the image skill's rules. Written to three
Pages columns on the post's row so each is copy-pasteable:
`Featured Image (Recommended)`, `Featured Filename`, `Featured Alt`. Nothing
goes to the Images tab, since there is no image yet.

**Build order.**

1. Bridge 0.2.0: `?p=` / `page_id` resolution; `post_status`, `post_type`
   in `/seo`. Re-upload per site.
2. `lib/wp-post-reader.js`: ID extraction from the three URL forms, the
   REST reads, inline-image parsing, body text.
3. Post mode: draft branch in the scan step; `WP Post ID` and `Status`
   columns; re-key after publish.
4. Page skill + lint: `slug_revised` with the slug rules; the
   featured-image trio for drafts; reuses `want_h1` from the H1 work.
5. `wp-push`: slug and headline writes by ID; slug change on a published
   post refused unless `--allow-slug-change`.

**Size.** Roughly twice the H1 change. H1 goes first: drafts reuse its
`post_title` path.

**Decisions closed.** Slug proposals: default for drafts, `--slug` for
published posts and pages. Featured-image output: brief + filename + alt.
Route: core REST. Row key: `?p=<id>` + `WP Post ID`.

---

## 3. Bridge plugin 0.2.0

The plugin review list lives in [`4all-seo-bridge.md`](4all-seo-bridge.md)
→ *To do (plugin review, 2026-09-03)*: staging-host path matching, alt
size-variant parity, Yoast indexable verification, write logging, softer
sanitisation, richer `/seo` responses (`post_title`, `post_type`,
`post_status`), and the documented "empty values cannot be pushed" rule.
Items 1, 2, 4, 5, 6 are one edit; the H1 push and draft support above both
lean on item 6, and drafts need the `?p=` resolver fix. **Distribution:** the
plugin is uploaded by hand per site today; the plan for self-updating it is a
separate brief, [`4all-seo-bridge-updates-brief.md`](4all-seo-bridge-updates-brief.md).

---

## 4. Follow-ups from post mode

- First live post-mode run on a client sheet (a GA4 sheet with Page Views):
  confirm the upsert, the topic prompt and the scoped push behave. The
  tab-copy path was exercised on a blank sheet; the rest only in tests.
- `npm link` once so `seo-post` and `wp-push` resolve on PATH.
- The topic prompt lists every subtopic; a client sheet with 40+ subtopics
  wants a two-step pick (main, then sub). Trivial change in
  `post-mode.assignTopics` when it becomes annoying.
- `seo-review --only` re-reviews a post; there is no `seo-post --review-only`.
  Add if the two-command form proves clumsy.
