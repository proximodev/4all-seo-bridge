# Changelog

## 0.2.0 — 2026-09-12

- Moved to its own repository; self-updating through WordPress's plugin
  updater (`Update URI` + release manifest). Auto-update on by default,
  `FOURALL_SEO_BRIDGE_AUTO_UPDATE` to opt out.
- `GET /seo`: read the current SEO title / meta and post facts without writing.
- Targets: `id` accepted alongside `url`; `?p=` / `?page_id=` /
  `?attachment_id=` resolve directly (drafts); a production URL pushed to a
  staging host is re-matched by path on `home_url()`.
- Responses carry `post_title`, `post_type`, `post_status`, `slug`, `url`;
  a posts-index front page returns `blog_index_not_supported`.
- Values are cleaned without silently dropping text (invalid UTF-8, tags,
  control characters, whitespace) instead of `sanitize_text_field`.
- Every applied write logs one line (no longer gated on `WP_DEBUG`).
- `/alt` strips `-scaled` / `-rotated` as well as `-WxH` before retrying.

## 0.1.1 — 2026-09-03

- Simpler client-facing description.

## 0.1.0

- Initial release: `ping`, `POST /seo`, `POST /alt`.
