#!/usr/bin/env node
/**
 * Live test matrix for the 4All SEO Bridge against a real WordPress site.
 *
 *   BASE=http://localhost:8888 WP_USER=admin WP_PASS='xxxx xxxx xxxx xxxx xxxx xxxx' node tests/live.mjs
 *
 * Optional:
 *   SUB_USER / SUB_PASS   an Application Password for a Subscriber → permission checks
 *   KEEP=1                leave the fixtures behind (default: delete them)
 *   READONLY=1            no fixtures, no writes: only /ping and GET/dry-run against
 *                         URLS (comma-separated) — safe on a production site
 *   URLS=https://site/a/,https://site/b/   published URLs to read in READONLY mode
 *
 * With an Administrator Application Password it creates its own fixtures
 * (a published post, a parent + child page, a draft post, a 1×1 PNG),
 * exercises every endpoint and error path, restores every value it
 * changed, and deletes the fixtures. Exit 1 on any failure. Node 18+.
 */

const BASE = (process.env.BASE || '').replace(/\/+$/, '');
const USER = process.env.WP_USER || '';
const PASS = process.env.WP_PASS || '';
if (!BASE || !USER || !PASS) {
  console.error('Need BASE, WP_USER, WP_PASS (Application Password).');
  process.exit(2);
}
const READONLY = !!process.env.READONLY;
const KEEP = !!process.env.KEEP;
const auth = (u, p) => 'Basic ' + Buffer.from(`${u}:${p}`).toString('base64');
const ADMIN = auth(USER, PASS);

let fails = 0, count = 0;
const ok = (cond, msg, extra) => { count++; console.log(`  ${cond ? 'ok  ' : 'FAIL'} ${msg}${cond || extra === undefined ? '' : '\n         got ' + JSON.stringify(extra)}`); if (!cond) fails++; };
const same = (exp, got, msg) => ok(JSON.stringify(exp) === JSON.stringify(got), msg + (JSON.stringify(exp) === JSON.stringify(got) ? '' : `\n         expected ${JSON.stringify(exp)}`), got);
const section = (t) => console.log(`\n${t}`);

async function api(method, path, { body, headers = {}, token = ADMIN, raw } = {}) {
  const res = await fetch(BASE + path, {
    method,
    headers: { Authorization: token, ...(raw ? {} : body ? { 'Content-Type': 'application/json' } : {}), ...headers },
    body: raw ? body : body ? JSON.stringify(body) : undefined,
  });
  let json = null;
  const text = await res.text();
  try { json = JSON.parse(text); } catch { json = { _raw: text.slice(0, 200) }; }
  return { status: res.status, json };
}
const bridge = (method, path, opts) => api(method, '/wp-json/4all/v1' + path, opts);
const q = (o) => '?' + new URLSearchParams(o).toString();

// 1×1 transparent PNG
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');

const fixtures = { post: null, parent: null, child: null, draft: null, media: null };
let cleaned = false;
const stamp = Date.now().toString(36);

async function createFixtures() {
  section('fixtures');
  const mk = async (type, data) => {
    const r = await api('POST', `/wp-json/wp/v2/${type}`, { body: data });
    ok(r.status === 201, `create ${type} "${data.title || data.slug}" → ${r.status}`, r.json);
    return r.json;
  };
  fixtures.post = await mk('posts', { title: `Bridge test post ${stamp}`, slug: `bridge-test-post-${stamp}`, status: 'publish', content: 'Bridge live test. Safe to delete.' });
  fixtures.parent = await mk('pages', { title: `Bridge parent ${stamp}`, slug: `bridge-parent-${stamp}`, status: 'publish', content: 'x' });
  fixtures.child = await mk('pages', { title: `Bridge child ${stamp}`, slug: `bridge-child-${stamp}`, status: 'publish', parent: fixtures.parent.id, content: 'x' });
  fixtures.draft = await mk('posts', { title: `Bridge draft ${stamp}`, status: 'draft', content: 'draft body' });
  const m = await api('POST', '/wp-json/wp/v2/media', { raw: true, body: PNG, headers: { 'Content-Type': 'image/png', 'Content-Disposition': `attachment; filename="bridge-test-${stamp}.png"` } });
  ok(m.status === 201, `upload media → ${m.status}`, m.json);
  fixtures.media = m.json;
}

async function deleteFixtures() {
  if (cleaned) return;
  cleaned = true;
  section(KEEP ? 'fixtures kept' : 'cleanup');
  if (KEEP) { console.log('  ids:', Object.fromEntries(Object.entries(fixtures).map(([k, v]) => [k, v && v.id]))); return; }
  for (const [k, type] of [['child', 'pages'], ['parent', 'pages'], ['post', 'posts'], ['draft', 'posts'], ['media', 'media']]) {
    const f = fixtures[k];
    if (!f || !f.id) continue;
    const r = await api('DELETE', `/wp-json/wp/v2/${type}/${f.id}?force=true`);
    ok(r.status === 200, `delete ${k} #${f.id} → ${r.status}`);
  }
}

async function main() {
  console.log(`Bridge live tests against ${BASE} as ${USER}${READONLY ? ' (READONLY)' : ''}`);

  section('ping');
  const ping = await bridge('GET', '/ping');
  same(200, ping.status, 'GET /ping 200');
  console.log('  version', ping.json.version, '· seo_plugin', ping.json.seo_plugin, '· user', ping.json.user, '· can_edit', ping.json.can_edit);
  ok(/^\d+\.\d+\.\d+$/.test(ping.json.version || ''), 'version present');
  ok(Array.isArray(ping.json.capabilities) && ping.json.capabilities.includes('seo_read'), 'capabilities include seo_read (0.2.0+)');
  const seo = ping.json.seo_plugin;
  ok(!!seo, 'an SEO plugin is active (Yoast/RankMath)');
  const home = ping.json.home || BASE;

  const anon = await api('GET', '/wp-json/4all/v1/ping', { token: '' });
  ok(anon.status === 401 || anon.status === 403, `anonymous /ping refused → ${anon.status}`);

  if (READONLY) {
    section('read-only checks');
    const urls = (process.env.URLS || '').split(',').map((s) => s.trim()).filter(Boolean);
    for (const u of urls) {
      const g = await bridge('GET', '/seo' + q({ url: u }));
      ok(g.status === 200, `GET /seo ${u} → ${g.status} id=${g.json.id} status=${g.json.post_status}`, g.json);
      if (g.status === 200) {
        const d = await bridge('POST', '/seo', { body: { url: u, title: g.json.current.title, dry_run: true } });
        ok(d.status === 200 && d.json.dry_run === true && d.json.changed.title === false, `dry-run same title → unchanged, no write`, d.json);
      }
    }
    const bad = await bridge('GET', '/seo' + q({ url: home + '/?p=abc' }));
    same(400, bad.status, '?p=abc → 400');
    same('bad_query_var', bad.json.code, '… code bad_query_var');
    return;
  }

  await createFixtures();
  const P = fixtures.post, C = fixtures.child, D = fixtures.draft, M = fixtures.media;
  const postUrl = P.link, childUrl = C.link;
  const siteHost = new URL(home).host;

  section('resolve via GET /seo');
  let r = await bridge('GET', '/seo' + q({ url: postUrl }));
  same(200, r.status, 'published post by permalink');
  same(P.id, r.json.id, '… id matches');
  same('publish', r.json.post_status, '… post_status');
  same('post', r.json.post_type, '… post_type');
  same(P.slug, r.json.slug, '… slug');
  r = await bridge('GET', '/seo' + q({ id: P.id }));
  same(P.id, r.json.id, 'by id');
  r = await bridge('GET', '/seo' + q({ url: childUrl }));
  same(C.id, r.json.id, 'nested page by permalink');
  same('page', r.json.post_type, '… post_type page');
  r = await bridge('GET', '/seo' + q({ url: postUrl + '#frag' }));
  same(P.id, r.json.id, 'fragment ignored');
  r = await bridge('GET', '/seo' + q({ url: `${home}/?p=${D.id}` }));
  same(200, r.status, 'draft by ?p=');
  same(D.id, r.json.id, '… id');
  same('draft', r.json.post_status, '… post_status draft');
  r = await bridge('GET', '/seo' + q({ url: `${home}/?p=${D.id}&preview=true` }));
  same(D.id, r.json.id, 'draft by preview link');
  r = await bridge('GET', '/seo' + q({ url: `${home}/?page_id=${C.id}` }));
  same(C.id, r.json.id, 'page by ?page_id=');
  r = await bridge('GET', '/seo' + q({ url: `${home}/?attachment_id=${M.id}` }));
  same(M.id, r.json.id, 'attachment by ?attachment_id=');
  const foreign = postUrl.replace(siteHost, 'www.production.example');
  r = await bridge('GET', '/seo' + q({ url: foreign }));
  same(P.id, r.json.id, 'foreign host, same path → path_match');
  r = await bridge('GET', '/seo' + q({ url: `https://staging.example/?p=${D.id}` }));
  same(D.id, r.json.id, 'foreign host ?p= → query var wins');

  section('resolve errors');
  const expectErr = async (params, status, code, msg) => {
    const e = await bridge('GET', '/seo' + q(params));
    ok(e.status === status && e.json.code === code, `${msg} → ${status} ${code}`, { status: e.status, code: e.json.code, message: e.json.message });
  };
  await expectErr({ url: `${home}/?p=abc` }, 400, 'bad_query_var', '?p=abc');
  await expectErr({ url: `${home}/?p=` }, 400, 'bad_query_var', '?p= (empty)');
  await expectErr({ url: `${home}/?p=0` }, 400, 'bad_query_var', '?p=0');
  await expectErr({ url: `${postUrl}?p=abc` }, 400, 'bad_query_var', 'bad ?p= on a real permalink');
  await expectErr({ url: `${home}/?p=99999999` }, 404, 'not_found', '?p= unknown id');
  await expectErr({ id: 99999999 }, 404, 'not_found', 'unknown id');
  await expectErr({ url: `${home}/no-such-page-${stamp}/` }, 404, 'not_found', 'unknown path');
  await expectErr({ url: `https://www.production.example/no-such-page-${stamp}/` }, 404, 'not_found', 'foreign host, unknown path');
  await expectErr({}, 400, 'missing_target', 'neither url nor id');
  const front = await bridge('GET', '/seo' + q({ url: home + '/' }));
  ok((front.status === 200 && front.json.post_type === 'page') || (front.status === 422 && front.json.code === 'blog_index_not_supported'), `front page → ${front.status} (${front.json.code || 'page #' + front.json.id})`, front.json);
  const badId = await bridge('GET', '/seo' + q({ id: 'abc' }));
  same(400, badId.status, 'id=abc rejected by REST schema (400)');

  section('POST /seo');
  const before = (await bridge('GET', '/seo' + q({ id: P.id }))).json.current;
  same({ title: '', metadesc: '' }, before, 'fresh post has empty SEO fields');
  r = await bridge('POST', '/seo', { body: { id: P.id, title: '  Dry <b>run</b> title ', dry_run: true } });
  same(200, r.status, 'dry run 200');
  same({ title: 'Dry run title', metadesc: '' }, r.json.after, 'dry: cleaned after');
  same({ title: true, metadesc: false }, r.json.changed, 'dry: changed flags');
  same(true, r.json.dry_run, 'dry: flag');
  same(before, (await bridge('GET', '/seo' + q({ id: P.id }))).json.current, 'dry: nothing written');

  const T = `Live title ${stamp} & "quotes" O'Neil – café`;
  const Dsc = `Meta ${stamp}: 50% off, a < b, ünïcode`;
  r = await bridge('POST', '/seo', { body: { id: P.id, title: T, metadesc: Dsc } });
  same(200, r.status, 'apply 200');
  same({ title: true, metadesc: true }, r.json.changed, 'apply: both changed');
  const stored = (await bridge('GET', '/seo' + q({ id: P.id }))).json.current;
  same(stored, r.json.after, 'apply: after == what GET reads back (the SEO plugin may escape; Yoast turns "<" into "&lt;")');
  ok(stored.title.includes(`Live title ${stamp}`) && stored.title.includes(`O'Neil – café`), 'apply: quotes, apostrophe, dash, accents survive (slash round trip)', stored.title);
  console.log('  stored metadesc:', JSON.stringify(stored.metadesc));
  r = await bridge('POST', '/seo', { body: { id: P.id, title: T, metadesc: Dsc } });
  same({ title: false, metadesc: false }, r.json.changed, 'same values again → unchanged (even where the SEO plugin escaped)');
  r = await bridge('POST', '/seo', { body: { id: P.id, metadesc: '' } });
  same({ title: false, metadesc: true }, r.json.changed, 'empty string clears metadesc, title untouched');
  same('', (await bridge('GET', '/seo' + q({ id: P.id }))).json.current.metadesc, '… cleared');
  r = await bridge('POST', '/seo', { body: { url: `${home}/?p=${D.id}`, title: `Draft title ${stamp}` } });
  same(200, r.status, 'draft push by ?p= 200');
  same(D.id, r.json.id, '… targets the draft');
  same({ title: true, metadesc: false }, r.json.changed, '… written');
  r = await bridge('POST', '/seo', { body: { url: `${home}/?p=abc`, title: 'x' } });
  same(400, r.status, 'POST with ?p=abc → 400');
  same('bad_query_var', r.json.code, '… bad_query_var');

  if (seo === 'yoast') {
    section('Yoast renders the pushed title');
    const wp = await api('GET', `/wp-json/wp/v2/posts/${P.id}`);
    const yt = wp.json.yoast_head_json && wp.json.yoast_head_json.title;
    ok(typeof yt === 'string', 'yoast_head_json present on the post', wp.json.yoast_head_json ? 'present' : Object.keys(wp.json));
    ok(typeof yt === 'string' && yt.includes(`Live title ${stamp}`), `Yoast indexable title includes the pushed title (got: ${JSON.stringify(yt)})`);
    const html = await (await fetch(postUrl, { cache: 'no-store' })).text();
    const m = html.match(/<title>([^<]*)<\/title>/i);
    ok(!!m && m[1].includes(`Live title ${stamp}`), `rendered <title> includes the pushed title (got: ${JSON.stringify(m && m[1])})`);
  }

  section('POST /alt');
  const img = M.source_url;
  r = await bridge('POST', '/alt', { body: { url: img, alt: '  Bridge  alt ', dry_run: true } });
  same(200, r.status, 'alt dry run 200');
  same(M.id, r.json.id, '… resolves the attachment');
  same({ alt: 'Bridge alt' }, r.json.after, '… cleaned');
  const sized = img.replace(/(\.[a-z0-9]+)$/i, '-300x200$1');
  r = await bridge('POST', '/alt', { body: { url: sized, alt: 'x', dry_run: true } });
  same(M.id, r.json.id, 'size suffix -300x200 stripped');
  r = await bridge('POST', '/alt', { body: { url: img.replace(/(\.[a-z0-9]+)$/i, '-scaled$1'), alt: 'x', dry_run: true } });
  same(M.id, r.json.id, 'suffix -scaled stripped');
  r = await bridge('POST', '/alt', { body: { url: img, alt: `Bridge alt ${stamp}` } });
  same({ alt: true }, r.json.changed, 'alt applied');
  const media = await api('GET', `/wp-json/wp/v2/media/${M.id}`);
  same(`Bridge alt ${stamp}`, media.json.alt_text, 'core REST reads the new alt');
  r = await bridge('POST', '/alt', { body: { url: `${home}/wp-content/uploads/none-${stamp}.png`, alt: 'x' } });
  same(404, r.status, 'unknown image → 404');
  r = await bridge('POST', '/alt', { body: { url: img } });
  same(400, r.status, 'missing alt → 400 (schema)');

  if (process.env.SUB_USER && process.env.SUB_PASS) {
    section('permissions (Subscriber)');
    const SUB = auth(process.env.SUB_USER, process.env.SUB_PASS);
    for (const [m, p, body] of [['GET', '/ping'], ['GET', '/seo' + q({ id: P.id })], ['POST', '/seo', { id: P.id, title: 'x' }], ['POST', '/alt', { url: img, alt: 'x' }]]) {
      const e = await bridge(m, p, { token: SUB, body });
      ok(e.status === 403, `${m} ${p.split('?')[0]} as subscriber → ${e.status}`, e.json);
    }
    same({ title: T, metadesc: '' }, (await bridge('GET', '/seo' + q({ id: P.id }))).json.current, 'subscriber attempt wrote nothing');
  } else {
    console.log('\n  (set SUB_USER / SUB_PASS to run the Subscriber permission checks)');
  }

  section('restore');
  r = await bridge('POST', '/seo', { body: { id: P.id, title: before.title, metadesc: before.metadesc } });
  same({ title: before.title, metadesc: before.metadesc }, r.json.after, 'SEO fields restored on the test post');

  await deleteFixtures();
}

main()
  .catch((e) => { console.error('\nERROR', e); fails++; })
  .then(async () => {
    if (!READONLY && fails && !KEEP) { try { await deleteFixtures(); } catch {} }
    console.log(`\n${count} checks, ${fails} failed`);
    process.exit(fails ? 1 : 0);
  });
