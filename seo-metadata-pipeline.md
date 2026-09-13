# SEO Metadata Pipeline — Functional Spec

Version 2.2 — September 2026

**v2.2 changes (2026-09-12):** H1 output (`H1 (Revised)`, on by default in
post mode, `--h1` on page runs, advisory); draft posts read through WordPress
REST by their preview / editor link, keyed by `?p=<id>` with `WP Post ID` /
`Status` / `Slug` columns and re-keyed after publish; slug proposals
(`Slug (Revised)`; drafts by default, `--slug` for all) with lint; a
featured-image brief + filename + alt for drafts with none; `wp-push --slug /
--headline` by post id; bridge plugin 0.2.0 (`?p=` resolution, `GET /seo`,
post facts). Status per feature: [`seo-improvements.md`](seo-improvements.md)
→ *Status at a glance* — several of these have not had a live run yet.

**v2.1 changes (2026-09-11):** the Keywords tab is read by header (its
template layout is topic-first; the positional reader had silently matched
no URLs); **post mode** (`seo-post` / `seo-meta --posts`) with Main Topic ›
Sub Topic inheritance for supporting posts; **`wp-push`** over both push
cores with a page scope; lint gained `meta_keyword_absent` and
`hub_breadth_lost` and a rewording-tolerant keyword match; the page skill
gained meta keyword coverage, homepage/hub breadth and keyword-preserving
rewrite rules; `--only <urls>` on the three flow CLIs; new scan sheets keep
the Keywords tab. Proposals now live in [`seo-improvements.md`](seo-improvements.md).

**v2.0 changes:** one spec for the whole rewrite pipeline (pages and images),
replacing `page-scan-seo-mode.md` (v1.2) and `image-update-plan.md` (v1.0),
both now in `archive/`. Reflects the September 2026 rebuild: Claude 5-series
model scopes with effort, batched calls with prompt caching, structured
output, a deterministic lint step, a judgment review that writes its
findings to a **Review Notes** column and its replacement copy straight
into the Revised columns, vision by default for images, WordPress
size-variant folding, and per-run metrics.
The design rationale and the acceptance criteria behind the v2.0 rebuild are
archived in [`archive/seo-pipeline-improvements.md`](archive/seo-pipeline-improvements.md);
the post-mode plan in [`archive/seo-post-optimize-plan.md`](archive/seo-post-optimize-plan.md).
This document describes what the pipeline *is*.

---

## Overview

The pipeline turns a scanned site into reviewed, push-ready metadata:

```
site-scan / page-scan / image-scan      →  Google Sheet (Pages, Images, Keywords tabs)
        │
        ▼
seo-meta  ─┬─  seo-page    write Page Title (Revised), Meta Description (Revised)
(seo-post) ├─  seo-image   write Filename (Revised), Alt (Revised)
           └─  seo-review  lint + judgment review → Review Notes; fixes written into Revised
        │
        ▼
wp-push  (= wp-seo-push + wp-alt-push)  push the Revised values to WordPress
```

Scripts stay dumb and skills add intelligence: every model call is a pure
JSON transformer (`skills/*/SKILL.md` + input JSON in, JSON out, no tools).
The scripts own sheet I/O, prefetching, batching, validation, lint,
composition, and every operator prompt. The operator's manual pass on the
finished sheet is a spot check, not a full read.

### Commands

| Command | Does | Writes |
|---|---|---|
| `seo-meta` | Interactive orchestrator: resolve sheet (existing or new site-scan), run `seo-page` and/or `seo-image`, then the unified review | everything below |
| `seo-page` | Page titles + meta descriptions for the Pages tab | `Page Title (Revised)`, `Meta Description (Revised)`; with review, `Review Notes` and in-place replacements |
| `seo-image` | Filenames + alt text for the Images tab | `Filename (Revised)`, `Alt (Revised)`; with review, `Review Notes` and in-place replacements |
| `seo-review` | Lint + judgment review of whatever Revised values a sheet already has (both tabs) | `Review Notes`, and replacements into the Revised columns (default; `--dry-run` prints only) |
| `seo-post` | Post mode (alias for `seo-meta --post-mode`): scan one post or a list, add them to an existing client sheet, assign a Main Topic › Sub Topic, rewrite + review just those posts, offer a scoped push | Pages / Images rows for the posts, `Main Topic`, `Sub Topic`, then the columns above |

Push is a separate, always-explicit step: `wp-push` (both halves, with a
`--only` / `--urls` page scope) wraps [`wp-seo-push`](4all-seo-bridge.md)
and [`wp-alt-push`](wp-alt-push.md), which remain available on their own.

---

## Sheet structure

All three rewrite commands work on a sheet built from the bundled site-scan
template (`1Jf2VpDEFwYe5BBzuwERAXw0JBjtMe4pBVSy2M3gCotM`). Columns are
matched by header alias, so hand-built sheets work as long as the required
headers exist. Missing pipeline columns are added on first use.

**Pages** — required: `URL`, `Page Title`, `Meta Description`, `Code`.

| Column | Written by | Meaning |
|---|---|---|
| `Page Views` | scan (GA4 sources only) | Drives scoping order |
| `Page Title (Revised)` | seo-page, then review | Composed title: `title_lead` + brand suffix. The review overwrites it when it has a better one. **Always the final value.** |
| `Meta Description (Revised)` | seo-page, then review | ≤ 155 chars; same rule |
| `WP Post ID`, `Status`, `Slug` | post mode, for posts read through WordPress REST (drafts) | Post id, `draft` / `pending` / `future` / `publish`, current slug. A draft row is keyed by `https://host/?p=<id>` and re-keyed to the live permalink on the first run after publish. |
| `Slug (Revised)` | seo-page (drafts by default in post mode; `--slug` elsewhere) | 2–6 keyword-led words, sanitised in code. `wp-push --slug` writes it (drafts; published posts need `--allow-slug-change`). |
| `Featured Image (Recommended)`, `Featured Filename`, `Featured Alt` | seo-page, for a draft with no featured image | A brief for the image to source, plus the filename and alt it should carry. Not pushed. |
| `H1 (Revised)` | seo-page with H1 output on (post mode by default, `--h1` on page runs), then review | ≤ 70 chars, no brand suffix. Blank = keep the current H1. **Advisory:** the push tools ignore it; the operator applies it in the CMS. Inserted after `H1` the first time a run asks for it. |
| `Review Notes` | review | Newline-joined `[lint]` / `[review]` findings for the row, plus a `[review] replaced …: was «…»` line for every value the review rewrote, so the writer's copy is recoverable |
| `Main Topic`, `Sub Topic` | post mode (appended on first use; hand-editable) | The cluster a supporting post belongs to. A page with a topic and no landing-page row in Keywords inherits the subtopic's keyword rows and is sent to the skills with a `topic` field. Blank on ordinary pages. |

**Images** — required: `Page URL`, `Image Name`, `Alt Tag`. `Image Name` is a
`=HYPERLINK(src, name)` formula; the src is recovered from it. One row per
(page, image) occurrence.

| Column | Written by | Meaning |
|---|---|---|
| `Filename (Revised)` | seo-image, then review | Advisory slug with the original extension restored |
| `Alt (Revised)` | seo-image, then review | ≤ 125 chars; `""` for decorative |
| `Review Notes` | review | As above |

Revised values are **fanned out** to every row that shares an image source,
including WordPress size variants of the same upload.

**Keywords** — `Main Topic / Grouping | Sub Topic | Landing Page | Keyword
Phrase | Volume | Position | Difficulty | Priority | Notes` (the template
layout; a client keyword sheet pasted in as-is). Optional. Columns are
matched by header alias (`lib/keyword-columns.js`), so the legacy
three-column `Landing page | Keyword phrase | Search volume` layout and
hand-built variants read the same way; the reader only falls back to
column positions when no landing-page/keyword header exists **and**
column A holds URLs, and errors otherwise rather than mis-mapping topic
names as landing pages. The highest-volume row per landing page
(trailing-slash tolerant) is the page's primary keyword. `Main Topic`
and `Sub Topic` are read on every row and drive keyword inheritance for
supporting posts (see *Post mode*).

Blank Revised cells mean "leave the live page alone": `left_strong` and
`skipped` entries write nothing, and the push tools only push rows with a
value. The operator edits Revised in place too; there is one final column
per field. A re-run of the review rewrites the notes cell for every
reviewed row, so stale notes never linger.

---

## Model scopes

Three independent scopes, each with its own env override and hardcoded
fallback. There is no fallthrough between them.

| Scope | Used by | Default | Effort | Env |
|---|---|---|---|---|
| writer | seo-page; seo-image batches without attachments | `claude-opus-5` | `medium` | `ANTHROPIC_MODEL`, `ANTHROPIC_EFFORT` |
| vision | seo-image batches with attachments (nearly all, with `--vision all`) | `claude-sonnet-5` | `medium` | `VISION_ANTHROPIC_MODEL` |
| review | page review and image review | `claude-fable-5-1` | `high` | `REVIEW_ANTHROPIC_MODEL`, `REVIEW_ANTHROPIC_EFFORT` |

Provider selection: `DEFAULT_LLM_PROVIDER`, `VISION_LLM_PROVIDER`,
`REVIEW_LLM_PROVIDER` (anthropic | openai | google), with
`<SCOPE>_<PROVIDER>_MODEL` per provider. Everything is Anthropic in practice;
the other providers exist for experiments, not production.

CLI flags map onto scopes exactly: `--provider` / `--model` are the writer
scope, `--vision-provider` / `--vision-model` the vision scope, and
`--review-provider` / `--review-model` the review scope. A writer `--model`
never reaches the review.

Claude 5-series handling in `lib/llm-client.js`:

- `effort` is sent; a `thinking` parameter never is (Fable rejects anything
  but adaptive, and lowering effort beats disabling thinking on Opus 5).
- Calls stream and collect the final text, so long turns never hit HTTP
  timeouts. `maxOutputTokens` is 32,000 for writer and review calls.
- A refusal (`finishReason: content-filter`, or no output with a non-stop
  finish) is retried once on `claude-opus-5` and logged.
- Fable 5.1 requires the organisation's data retention to be 30 days. A
  zero-retention org gets a 400; set `REVIEW_ANTHROPIC_MODEL=claude-opus-5`.

Every run log prints the resolved `provider/model · effort` per phase.

---

## seo-page flow

```
1. Resolve sheet     --sheet <url> | --new + URL source | interactive
2. Read Pages        prepareAndReadPages: reads rows, inserts the two Revised
                     columns in place if missing. noindex rows are included
                     by default (--skip-noindex excludes them). Pages with
                     0 GA4 views are removed with a warning ("Removing N
                     page(s) with 0 views count"); blank views never trigger
                     this, an all-zero sheet is kept intact with a warning,
                     and --include-zero-views overrides.
3. Keywords          use the sheet's Keywords tab, or copy from a source sheet
                     (loose header matching: keyword-source.js)
4. Scope             "Number of pages (max N)" — N = every remaining page, and
                     the default. No ceiling. Above 50 the operator confirms
                     "Confirm large batch rewrite" with a dollar estimate
                     (writer + review at the configured models).
                     Ranked by Page Views when present, else sheet order.
5. Brand suffix      detected from existing titles (most common trailing
                     segment after | - – — · on ≥ half the multi-segment
                     titles); operator accepts, edits, or clears.
                     title_lead_max_chars = 60 − suffix length (min 20).
6. Overwrite check   confirm if any in-scope row already has a Revised value
7. Prefetch          body text per URL, one fetch at REVIEW_BODY_CHARS (6000);
                     the writer sees the first 1500 chars, the review the lot.
                     Fetch failures are reported and dropped from the packet.
8. Skill             batches of SEO_PAGE_BATCH (12) pages → seo-page-metadata;
                     with H1 output on, want_h1: true and each entry carries
                     h1_revised (posts: proposed unless the headline is already
                     right; pages: only when the current H1 is weak)
9. Lint              lintPageRewrites, printed as [lint]
10. Write            Revised columns (H1 (Revised) too when on)
11. Report           counts by status, truncations, compose actions, skipped
12. Review           prompt (skip with --no-review): seo-page-metadata-review
                     with the lint flags → print → write Review Notes and
                     overwrite Revised where the review supplied copy
                     (unless --dry-run)
13. Metrics          one line to output/seo-runs.jsonl
```

Each batch call gets the full voice samples (first three pages' bodies), the
keyword rows for its own URLs, its pages with their global `index`, and a
`batch` descriptor. The model echoes `index` on every rewrite; the engine
reconciles all batches against the page list, marks any missing page
`skipped` (never overwriting a cell), drops stray or duplicate indexes, and
lists a failed batch's pages as `batch_failed: <reason>` while the run
continues.

The final title is composed in code: `title_lead` + suffix, with the suffix
omitted when the brand already appears in the lead or the pair would exceed
60 characters (both reported). Metas over 155 after the retry are
word-trimmed and reported as truncations; a lead over the cap alone marks
the row `skipped`.

---

## seo-image flow

```
1. Resolve sheet     --sheet <url> | interactive
2. Read Images       readImagesTab; ensureImageRevisedColumns
3. Keywords          optional; primary keyword per host page
4. Dedupe            dedupeBySrc: one representative per canonical src.
                     Query-string resize params and WordPress size-variant
                     suffixes (-300x200, -scaled, -rotated) are folded; the
                     largest variant is the representative. The fold count
                     is logged. Images on host pages with 0 GA4 views are
                     then removed (same rule and --include-zero-views
                     override as seo-page).
5. Group             representatives grouped by host page, flat index assigned.
                     Above 300 unique images the operator confirms a cost
                     estimate ("Confirm large batch rewrite").
6. Prefetch bodies   per host page at REVIEW_BODY_CHARS; writer gets 1500
7. Vision decision   --vision all (default) | auto | none. Images the
                     decorative heuristics condemn (icon-style prefix, a
                     dimension < 24px, aspect ratio > 20:1) are never
                     attached and carry decorative_hint: true instead.
8. Download          image bytes, downscaled to 768px longest edge, JPEG q85,
                     8 in flight. A failed download downgrades to text-only.
9. Skill             batches of ~SEO_IMAGE_BATCH (20) images, never splitting
                     a page unless it alone exceeds 40 → seo-image-metadata.
                     Each attachment is preceded by a text label:
                     "Image index 7 — IMG_1234.jpg (page: <url>)".
10. Lint             lintImageRewrites, printed as [lint]
11. Write            Revised columns, fanned out by image source
12. Report           decoratives, left_strong, skipped, collisions
13. Review           prompt (--no-review): seo-image-metadata-review, text
                     only, with the lint flags → print → write Review Notes
                     and overwrite Revised where the review supplied copy
14. Metrics          one line to output/seo-runs.jsonl
```

The image writer runs on the vision scope for any batch that carries
attachments and on the writer scope otherwise. Filenames are advisory:
the slug is sanitised in code (lowercase, hyphens, no extension) and
written with the original extension restored; nothing renames files on the
server.

---

## Post mode (seo-post / seo-meta --posts)

Operator guide with prompts, re-run behaviour and troubleshooting:
[`seo-post.md`](seo-post.md). This section is the pipeline-level summary.

Blog posts come back from writers one at a time. They are not landing
pages in the client's keyword sheet, but every post belongs to a
**Main Topic › Sub Topic** cluster that is. Post mode runs the same
pipeline on just those URLs:

```
1. Input          --posts <url,url | file> (repeatable), or pasted; --sheet <client sheet>
                  (blank / absent → the posts become a new "SEO Posts" sheet, with a
                  Keywords tab kept from the template)
2. Scan           live posts: page + image scan (post-mode.js → scanPosts). A URL
                  carrying a post id (preview / editor link) is a DRAFT: read through
                  WordPress REST with the saved Application Password
                  (wp-post-reader.js) — post record, featured image, inline images,
                  body text — keyed by https://host/?p=<id>. Unfetchable live URLs
                  are reported and dropped.
3. Upsert         Pages: existing URL → scan columns refreshed in place (Revised /
                  Review Notes / Topic kept); new URL → appended. Images: the post's
                  old rows are deleted and the fresh scan appended (sheet-upsert.js).
4. Topic          per post, pick Main Topic › Sub Topic from the Keywords tab
                  (topics.js → topicOptions); "use for the remaining posts" shortcut;
                  the current value is the default on a re-run. Written to the Pages
                  tab's Main Topic / Sub Topic columns. An empty Keywords tab offers
                  to load a keyword sheet first.
5. Rewrite        seo-page and seo-image with only-urls = the posts: no cap prompt,
                  no zero-view removal, keyword-source prompt skipped (the sheet is
                  the source), overwrite confirmation kept. H1 output is on
                  (H1 (Revised); --no-h1), slugs for drafts (Slug (Revised);
                  --slug for all, --no-slug), and a featured-image brief +
                  filename + alt for drafts with no featured image. Body text
                  for draft rows comes through REST in every flow
                  (seo-prefetch → loadDraftBodies), so seo-review --only <draft>
                  works standalone once credentials are saved.
6. Review         unified review scoped to the posts.
7. Push           offer: wp-push dry run for the posts (pages + images, plus slugs for
                  drafts via wp-post-core), then apply on confirmation. --skip-push
                  turns the offer off. Pages push on a ?p= URL needs bridge 0.2.0.
```

**Keyword inheritance** (`lib/topics.js`). `resolveKeywordsForPages` runs in
every flow: a page with a direct landing-page row keeps its rows; a page
with a topic and no direct rows inherits the subtopic's rows re-keyed to
its URL (`inherited: true`, volumes kept so "highest volume = primary"
still holds); a topic not in the Keywords tab is warned about and inherits
nothing. Such a page's packet carries
`topic: { main, sub, landing_page }` and the skills treat it as a
**supporting post**: the title carries the subtopic phrase plus the post's
own angle, never the landing page's commercial title; the image skill
treats the inherited keyword as a theme, not a term for every alt; both
review skills judge it as a post. `loadKeywordContext` in sheet-loader is
the shared entry point (Keywords tab + Pages-tab topics → keyword list,
primary per URL, topic per URL).

**Scoping flags.** `seo-page`, `seo-image`, `seo-review` accept
`--only <url,url>`; the orchestrator passes `only-urls` internally.
`wp-push` (and both push cores) accept `--only` / `--urls <file>`.

---

## seo-review flow

Sheet-driven: reads whatever Revised values exist on each tab, re-fetches
the pages at the review body budget, lints, runs the matching review skill
with the lint flags, prints, writes Review Notes, and overwrites Revised
where the review supplied copy. `--pages-only`
/ `--images-only` limit the sides; `--top-n` limits the rows; `--dry-run`
(or `--no-write`) prints only.

Page-side, the composed title stands in for the lead (the lead is not
stored), so lint applies the full 60-character cap; a replacement lead the
reviewer writes in composed form keeps its suffix. Image-side, the fan-out
map is built over the whole tab so a replacement reaches every row of a
reused image. The sheet does not record which images had pixels attached,
so this path assumes vision was on (the default) for every image the
decorative heuristics would not have skipped.

Large sheets are reviewed in slices of `SEO_REVIEW_BATCH` (50) entries per
model call (page-aligned on the image side); see *Batching* below. The
zero-view filter does not apply here: the review covers whatever Revised
values exist, and rows the rewrite skipped have none.

`seo-meta` runs this same flow as its final phase after the rewrite phases
it was asked to run.

---

## Batching and prompt caching

`lib/batching.js` splits pages into batches of `SEO_PAGE_BATCH` (12) and
images into page-aligned batches of about `SEO_IMAGE_BATCH` (20). The
review engines slice their input the same way at `SEO_REVIEW_BATCH` (50)
entries, remapping each slice's local indexes back to the run; lint's
whole-run collision checks cover what a slice cannot see. Batch one runs
alone so it writes the prompt cache entry for the skill block; the
remaining batches run at `SEO_BATCH_CONCURRENCY` (3) and read it. The run
log prints per-batch tokens including cached input, and the metrics line
records them. The cache TTL is the 5-minute default (`ANTHROPIC_CACHE_TTL=1h`
extends it at twice the write cost).

Per-batch debug artifacts are written to `temp/`:
`seo-page-<ts>-bNN-output.txt` (always), plus `-bNN-input.json` and a whole
`-input.json` with `--debug`. The review's raw output goes to
`temp/seo-review-<ts>-output.txt` / `temp/image-review-<ts>-output.txt`.

---

## Structured output and validation

Engines pass zod schemas (`pageRewriteSchema`, `imageRewriteSchema`,
`pageReviewSchema`, `imageReviewSchema`); `runJsonSkill` uses the AI SDK's
`Output.object` with Anthropic's `outputFormat` mode, so shape and enum
are guaranteed by the provider. The old fence-stripping JSON parser is the
fallback for providers that ignore the schema.

The validator therefore only checks semantics: status/field consistency and
the length caps. A length-only violation triggers one retry that carries
the skill's **Rules** section plus each offender's h1, a 600-character body
excerpt, and its keyword, so the shortened copy stays grounded. What is
still over after that is word-trimmed in code and reported.

### Caps (enforced in code)

| Value | Cap | Target given to the model |
|---|---|---|
| composed title | 60 | lead 40 to `title_lead_max_chars` |
| meta description | 155 | 120–150 |
| alt text | 125 | 50–110 |
| filename slug | 60 | 20–50 |

The skills are told the targets and that the code enforces the ceilings.
They are not asked to count characters; the earlier hard-cap phrasing
produced fragments and comma lists.

---

## Lint (deterministic, no model)

`lib/seo-lint.js` runs after every first pass and again inside the review.
Pure functions, unit-tested (`npm test`). Flags share the review flag shape
with `source: 'lint'`.

**Pages:** `length_cap`, `crude_truncation` (ends on a stop word or
non-terminal punctuation), `near_cap` (within 2 chars), `missing_meta`,
`opener_repetition` (≥ 3 or > 20% share the first word), `collision`
(first 30 chars match, or identical after stop-word stripping),
`weak_keyword_placement` (primary keyword absent from the lead, allowing a
natural rewording: same content words, singular/plural, any order),
`meta_keyword_absent` (the meta carries none of the page's keyword rows,
even reworded — fix requested from the review), `hub_breadth_lost`
(homepage or one-segment URL whose new title drops ≥ 2 and ≥ 40% of the
current title's content words — informational), and with H1 output on:
`h1_missing` (no H1 and none proposed), `h1_duplicates_title` (proposal
equals the lead or composed title), `h1_keyword_absent` (a post's proposal
carries neither the keyword nor the subtopic phrase), all informational,
plus `length_cap` at 70 — then `exclamation` (when no voice sample has one).

**Images:** `length_cap`, `decorative_missed` (heuristic fired, alt
non-empty), `accessibility_violation` (rewritten with empty alt),
`all_caps` (unless the filename says logo), `generic_opener` ("image of"…),
`duplicate_alt_same_page` (first 20 chars, or stop-word-only difference),
`keyword_stuffing` (> 1× in an alt or filename; in every alt on a page with
≥ 3 images), `filename_format` (uppercase, underscores, extension,
duplicate tokens).

`isDecorativeByHeuristic` is the same function the image flow uses to skip
vision, so the lint and the skip never disagree.

---

## Editorial review (model)

Two jobs. The review skills receive the first-pass input with the fuller
body text, the rewrites, and the lint flags, each lint flag marked
`fix_requested` when its category needs new copy.

1. **Judgment flags**, selectively: a clean batch should have few.
2. **Lint fixes**, exhaustively: for every `fix_requested` lint finding the
   reviewer writes the replacement that clears it. Lint keeps detecting;
   the reviewer supplies the copy. Fixable categories are
   `PAGE_LINT_FIXABLE` / `IMAGE_LINT_FIXABLE` in `lib/seo-lint.js`: pages
   — truncation, missing meta, string-level collision, opener repetition,
   cap overrun; images — duplicate alts, empty alt on a rewritten image,
   heuristic-decorative with an alt, generic opener, cap overrun.
   Informational lint (near-cap, keyword placement, exclamation marks,
   ALL CAPS, keyword stuffing, filename format) is left to the operator.

| Side | Categories |
|---|---|
| Pages | `factual_invention`, `voice_drift`, `generic_phrase`, `collision` (semantic, not string-level), `geographic_inconsistency`, `other` |
| Images | `factual_invention`, `voice_drift`, `generic_phrase`, `filename_unclear`, `decorative_missed` (only where page context contradicts the heuristic), `other` |

Each flag carries `indexes`, `severity` (major | minor), `issue`, an
optional `suggestion`, and `replacements[]` — one full final value per
flagged entry the reviewer chose to rewrite, each with a one-sentence
`reason`. `lint_fixes[]` carries the same shape per fixed lint index. All
of it is written into the Revised columns in place; Review Notes records
`[review] replaced meta: was «…» — reason` (or `fixed lint/<category>`)
so the writer's value is recoverable. A judgment replacement wins over a
lint fix on the same entry. A page replacement lead is composed with the
operator's brand suffix so it is push-ready.

The review is text-only for images: the writer already saw the pixels, and
the reviewer's job is to check the claims against the page.

---

## Push handoff

`wp-push` runs both halves against one sheet — titles + metas through the
bridge (`wp-seo-push` core), alt text through core REST (`wp-alt-push`
core) — with `--no-pages` / `--no-images` to run one, and `--only` /
`--urls` to scope both to a page list. Each half plans and confirms on its
own (their counts differ). All three commands read the Revised columns,
which hold the final value after any review; all are dry-run by default,
push only where the live value differs, and confirm site, account, and
count before `--apply`. Nothing in the rewrite pipeline pushes
automatically; post mode *offers* a scoped push and still confirms.

---

## Metrics

Every run of `seo-page`, `seo-image`, and `seo-review` appends one JSON line
to `output/seo-runs.jsonl`: timestamp, script, sheet id, counts, resolved
scopes with effort, per-batch tokens (input, output, cached, cache-write,
reasoning), durations, counts by status, lint and review flag counts,
review batch count, review replacements and lint fixes, truncations, omissions, batch
failures, and the scoping decisions (`zero_views_removed`,
`noindex_skipped`, `variant_rows_folded`, `decorative_skipped`). Model and
effort comparisons are made from this file, not from memory; it is also
where to check the large-batch cost estimate against what a run actually
spent.

---

## Components

| File | Role |
|---|---|
| `scripts/seo-meta.js` | Orchestrator CLI (site runs and post mode) |
| `scripts/seo-post.js` | Alias: `seo-meta --post-mode` |
| `scripts/wp-push.js` | Unified push CLI over the two push cores |
| `scripts/seo-page.js`, `seo-image.js`, `seo-review.js` | Thin CLIs over the flows |
| `scripts/lib/post-mode.js` | Post mode: scan posts, upsert onto the sheet, topic prompt, scoped push offer |
| `scripts/lib/topics.js` | Main Topic › Sub Topic index, keyword inheritance, packet `topic` field |
| `scripts/lib/sheet-upsert.js` | Refresh-or-append Pages rows; replace Images rows for a page set |
| `scripts/lib/url-filter.js` | URL allow-list matching (`--only` / `--urls` / `only-urls`) |
| `scripts/lib/seo-page-flow.js` | Page pipeline (prompts, prefetch, engine, lint, write, review, metrics) |
| `scripts/lib/seo-image-flow.js` | Image pipeline (dedupe, vision decision, download, engine, lint, write, review, metrics) |
| `scripts/lib/seo-review-flow.js` | Sheet-driven review for both tabs |
| `scripts/lib/seo-engine.js` | Page writer engine: batching, schema, retry context, reconcile, title composition |
| `scripts/lib/image-rewrite-engine.js` | Image writer engine: page-aligned batching, labelled attachments, vision/text scope per batch |
| `scripts/lib/seo-review-engine.js`, `image-review-engine.js` | Review engines (review scope, schemas, replacement validation) |
| `scripts/lib/llm-client.js` | Scopes, effort, streaming, structured output, refusal fallback, cache marker, usage summaries |
| `scripts/lib/skill-runner.js` | Run → validate → one contextual length retry |
| `scripts/lib/batching.js` | `chunk`, `chunkImagesByPage`, `runBatches` (first alone, rest pooled) |
| `scripts/lib/scope-filters.js` | Zero-view removal with the all-zero guard and `--include-zero-views` override |
| `scripts/lib/cost-estimate.js` | Rate table + per-item token assumptions behind the large-batch confirmation |
| `scripts/lib/seo-lint.js` | Deterministic lint + decorative heuristic |
| `scripts/lib/review-notes.js` | Flags + fixes → Review Notes text, in-place Revised overwrites, terminal printout |
| `scripts/lib/run-metrics.js` | `output/seo-runs.jsonl` writer |
| `scripts/lib/sheet-loader.js` | Tab resolution, header aliases, Revised and review column helpers, fan-out writes |
| `scripts/lib/seo-prefetch.js`, `http-fetch.js` | Body text extraction at a char budget |
| `scripts/lib/image-dedupe.js`, `image-prefetch.js`, `image-needs-vision.js` | Source canonicalisation and variant folding; download + downscale; the `auto` vision heuristic |
| `scripts/lib/keyword-columns.js` | Keywords tab column contract: header aliases, parser, positional guard, write layout |
| `scripts/lib/keyword-source.js` | Keyword tab discovery on a source sheet; copies rows under the target's matched headers |
| `skills/seo-page-metadata/`, `seo-image-metadata/` | First-pass skills |
| `skills/seo-page-metadata-review/`, `seo-image-metadata-review/` | Review skills |
| `agent-skills/seo-meta-editorial-review/` | Uploadable claude.ai package for the operator's manual spot check; two reference files are generated from the code above (`npm run build:skill`) |

---

## Configuration reference

```ini
DEFAULT_LLM_PROVIDER=anthropic
ANTHROPIC_MODEL=claude-opus-5            # writer
ANTHROPIC_EFFORT=medium
VISION_LLM_PROVIDER=anthropic
VISION_ANTHROPIC_MODEL=claude-sonnet-5   # image writer with attachments
REVIEW_LLM_PROVIDER=anthropic
REVIEW_ANTHROPIC_MODEL=claude-fable-5-1  # both reviews (needs 30-day retention)
REVIEW_ANTHROPIC_EFFORT=high
REVIEW_BODY_CHARS=6000                   # reviewer body budget; writer sees 1500
SEO_PAGE_BATCH=12
SEO_IMAGE_BATCH=20
SEO_REVIEW_BATCH=50
SEO_BATCH_CONCURRENCY=3
# ANTHROPIC_CACHE_TTL=1h
```

`.env.example` carries the same block with keys blanked. Per-command flags
are in each script's `--help`.

---

## Decisions carried forward

Condensed from the v1 specs; still in force unless noted.

- **Index-based contract.** Models never emit URLs, sources, or filenames as
  identifiers; every entry echoes its input `index` and the script pairs.
  Introduced after a slug-mutation incident; now also what makes batching
  and gap recovery safe.
- **Operator decisions are pre-flighted.** Scoping, brand suffix, keyword
  source, and overwrite confirmation happen in the script before any model
  call. No mid-skill prompts.
- **No page ceiling; a cost gate instead.** v1 capped a run at 50 pages
  with a default of 25. v2.0 defaults to the whole site and asks for
  confirmation with a dollar estimate above 50 pages (300 images).
- **Zero-view pages are skipped.** When GA4 views are present, pages
  with exactly 0 are removed before scoping, with a warning and an
  override. Blank views (no GA4 source) are left alone, and a sheet where
  every page is 0 is treated as a mismatch, not a dead site.
- **Confirm-then-overwrite.** A re-run on a sheet with Revised values asks
  once, then rewrites every in-scope row.
- **Bail on schema violation.** Now rare with structured output; still no
  partial sheet write when it happens.
- **Dedupe by source, fan out on write.** Extended to WordPress size
  variants in v2.0.
- **Filenames are advisory.** No server-side rename plumbing.
- **Decorative classification from heuristics**, now shared between the
  vision skip, the lint, and the skill.
- **Vision policy.** v1 chose `auto` (pixels only for opaque names and empty
  alts). v2.0 changed the default to `all` with the heuristic-decorative
  skip, because alt text written blind was the largest quality gap.
- **Keywords by header, never by position.** The tab's template layout is
  topic-first; a positional reader matched topic names as landing pages for
  months without an error. `lib/keyword-columns.js` is the only reader.
- **Posts inherit, landing pages own.** A page with a landing-page row keeps
  it; a page with a topic and no row inherits the subtopic's rows and is
  sent as a supporting article. Assignment lives on the Pages tab, not in
  duplicated Keywords rows, so one cell per post is the whole state.
- **Post mode fills structural gaps, never edits existing tabs.** Missing
  Pages / Images / Keywords tabs are copied from the template; a like-named
  tab with the wrong columns is an error, not a second tab.
- **Push scope by URL list.** `--only` / `--urls` on `wp-push` and the flow
  CLIs; a full-sheet push stays the default and the same dry-run rules apply.
- **One final column per field.** v1 review printed to the terminal only.
  v2.0 briefly used separate Editorial columns for the reviewer's copy and
  had the push tools prefer them; that was dropped the same day as three
  columns per field and precedence rules in the push tools. The review now
  overwrites Revised in place and Review Notes keeps the prior value, so
  Revised is what ships and what the operator edits.

---

## Improvements

Proposals and their status — H1 output, draft posts (slug and image
recommendations), the bridge 0.2.0 list, post-mode follow-ups — live in
[`seo-improvements.md`](seo-improvements.md). This document describes what
is built.

---

## Non-goals

- Automatic pushing. Push is always a separate, confirmed command.
- Server-side file renames or redirects for revised filenames.
- Sending images to the review model.
- OpenAI / Google as production providers; the defaults exist for
  experiments only.
- Reworking the scan tools or the sheet template beyond the columns above.
