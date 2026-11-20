// Client feedback before launch: the free postal route as one short line (address only on /free-postal-entry), the
// skill question in the basket and in Admin, the Instagram handle, the sitemap, and the decluttered competition page
// (long information in closed accordions). Run: npm test (after a build).
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const base0 = {
  strapline: 'Estoril Blue', category: 'cars', badge: null, images: [{ src: '/images/e46-clubsport-34.jpg', alt: 'BMW' }], description: ['A Clubsport.'],
  specs: [{ label: 'Gearbox', value: 'ZF manual' }], ticketsSold: 0, opensAt: '2026-11-01T09:00:00+00:00', notOpenYet: null,
  closesAt: '2026-12-24T19:00:00+00:00', postalClosesAtCustom: null, drawAt: '2026-12-24T19:30:00+00:00', eventSalesEnabled: false,
  buildVideoUrl: null, verityRef: 'VTY-1', drawMethod: 'physical', postponement: null, status: 'live',
};
const PAID = { ...base0, id: 1, slug: 'bmw', title: 'BMW E46 330Ci Clubsport', ticketPrice: 14.95, totalTickets: 750, maxPerPerson: null,
  bundles: [{ qty: 3, price: 40 }], postalClosesAt: '2026-12-22T19:00:00+00:00', postalEntryEnabled: true, cashAlternative: 5000,
  skillQuestion: { question: 'What does BMW stand for?', options: ['British Motor Works', 'Bayerische Motoren Werke', 'Berlin Motor Wagons'] } };
const FREE = { ...base0, id: 2, slug: 'engine-table', title: 'Bespoke Built Bedside Engine Table', category: 'merch', ticketPrice: 0, totalTickets: 500,
  maxPerPerson: 1, bundles: [], postalClosesAt: null, postalEntryEnabled: false, cashAlternative: null, skillQuestion: null };
const ADMIN = { id: 1, firstName: 'Jo', lastName: 'Admin', email: 'jo@example.com', isAdmin: true, isStaff: true };
const DRAFT = { ...PAID, id: 3, slug: 'coilovers', title: 'BC Racing BR Series Coilovers', status: 'draft', verityRef: null, ticketPrice: 5,
  skillQuestion: { question: 'What part of a car do coilovers replace?', options: ['The brakes', 'The exhaust', 'The suspension (springs and dampers)', 'The gearbox'], correct: 2 } };
const ADDRESS = 'WDS Competitions – Free Entry'; // the first line of the free entry address (sample settings)

// null = the API has no settings (the site uses its sample settings); set it to test the scrolling banner.
let SETTINGS = null;
const SITE = { tradingName: 'WDS Competitions', legalName: 'WDS Originals Limited', companyNumber: '13330886', registeredOffice: 'Knoll House, Knoll Road, Camberley, Surrey, GU15 3SY',
  supportEmail: 'sales@example.com', postalEntryAddress: [ADDRESS, 'Knoll House'], drawsInstagram: 'wds_competitions', socials: [], youtubeUrl: 'https://youtube.com/@wdsoriginals',
  drawsYoutubeUrl: null, bannerMessages: [] };

const send = (res, status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };
let api, site, base;
before(async () => {
  api = createServer((req, res) => {
    const path = req.url.split('?')[0];
    if (path === '/api/me') return (req.headers.authorization ?? '') === 'Bearer admin' ? send(res, 200, { data: ADMIN }) : send(res, 401, { message: 'Unauthenticated.' });
    if (path === '/api/competitions') return send(res, 200, { data: [PAID, FREE] });
    if (path === `/api/competitions/${PAID.slug}`) return send(res, 200, { data: PAID });
    if (path === `/api/competitions/${FREE.slug}`) return send(res, 200, { data: FREE });
    if (path === `/api/admin/competitions/${DRAFT.id}`) return send(res, 200, { data: DRAFT });
    if (path.startsWith(`/api/admin/competitions/${DRAFT.id}/`)) return send(res, 200, { data: path.endsWith('history') ? [] : null });
    if (path === '/api/admin/settings') return send(res, 200, { data: { ...SITE, bannerMessages: ['Ticket bundles available', 'Low ticket numbers, better odds'] } });
    if (path === '/api/settings') return SETTINGS ? send(res, 200, { data: SETTINGS }) : send(res, 404, { message: 'Not found.' });
    if (path === '/api/upcoming') return send(res, 200, { data: null });
    if (path === '/api/winners') return send(res, 200, { data: [] });
    if (path === '/api/draws') return send(res, 200, { data: [] });
    return send(res, 404, { message: 'Not found.' });
  });
  await new Promise((r) => api.listen(0, '127.0.0.1', r));
  const port = 5500 + Math.floor(Math.random() * 300);
  base = `http://127.0.0.1:${port}`;
  site = spawn(process.execPath, [fileURLToPath(new URL('../dist/server/entry.mjs', import.meta.url))], {
    env: { ...process.env, TZ: 'America/New_York', API_URL: `http://127.0.0.1:${api.address().port}`, HOST: '127.0.0.1', PORT: String(port) },
    stdio: 'ignore',
  });
  for (let i = 0; i < 100; i++) {
    try { await fetch(`${base}/robots.txt`); return; } catch { await new Promise((r) => setTimeout(r, 100)); }
  }
  throw new Error('site did not start');
});
after(() => { site?.kill(); api?.close(); });

const page = async (path, cookie) => {
  const res = await fetch(base + path, { redirect: 'manual', headers: cookie ? { cookie } : {} });
  assert.equal(res.status, 200, path);
  return (await res.text()).replace(/<!--.*?-->/gs, '').replace(/ data-astro-cid-[a-z0-9]+(="")?/g, '');
};
const footer = (html) => html.slice(html.indexOf('<footer'));

test('a paid competition page: one short free-route line, no postal address, key terms kept', async () => {
  const html = await page(`/competitions/${PAID.slug}`);
  assert.ok(html.includes('Free entry route available – see <a href="/free-postal-entry">free postal entry</a>'), 'the short line');
  assert.ok(!html.includes(ADDRESS), 'no postal address on the competition page (footer included)');
  assert.ok(!html.includes('How to enter by post'), 'no postal box');
  // Legally required information stays, just secondary: closing date, max entries, the free route and the T&Cs link.
  const terms = html.slice(html.indexOf('data-key-terms'), html.indexOf('</p>', html.indexOf('data-key-terms')));
  assert.ok(terms.includes('Closes 24 December at 19:00') && terms.includes('No limit per person') && terms.includes('href="/terms"'), terms);
  assert.ok(html.includes('Tuesday 22 December 2026, 7:00pm'), 'the postal deadline is still on the page (Draw details)');
});

test('the competition page puts long information in accordions, closed by default', async () => {
  const html = await page(`/competitions/${PAID.slug}`);
  for (const k of ['draw', 'cash', 'verify', 'terms']) assert.match(html, new RegExp(`<details data-acc="${k}"><summary>`), k);
  assert.ok(!/<details[^>]*\sopen/.test(html), 'every accordion and FAQ starts closed');
  // Price, odds, countdown and ticket selector are in the entry panel, which phones show straight after the photo
  // (grid areas 'gallery' 'panel' 'main'); the accordions are in the main column below it.
  const panel = html.slice(html.indexOf('id="top-panel"'), html.indexOf('</aside>'));
  for (const marker of ['£14.95', '1 in 750', 'class="countdown"', 'data-pack="3"', 'data-enter', 'data-free-route']) assert.ok(panel.includes(marker), marker);
  assert.ok(!panel.includes('<details'), 'no accordions in the panel');
  assert.ok(html.indexOf('data-main') < html.indexOf('data-more-info'), 'photo first');
  assert.ok(html.includes('drawn live by hand from numbered tokens'), 'draw method');
  assert.ok(html.includes('The winner can choose £5,000 cash instead'), 'cash alternative');
  assert.ok(html.includes('answer a multiple-choice question correctly before paying'), 'the skill question in the terms summary');
  assert.ok(html.includes('href="https://www.instagram.com/wds_competitions/"'), 'Instagram link');
  assert.ok(!mainOf(html).includes('wds_originals'), 'the draw copy names @wds_competitions only (wds_originals is just a footer social link)');
});

test('the free competition gets no free-route line and no skill question', async () => {
  const html = await page(`/competitions/${FREE.slug}`);
  assert.ok(!html.includes('Free entry route available'));
  assert.ok(!html.includes('multiple-choice question'));
});

test('/free-postal-entry has the address and instructions; the footer links to it without the address', async () => {
  const html = await page('/free-postal-entry');
  assert.ok(html.includes(ADDRESS), 'the address');
  assert.ok(html.includes('One entry per envelope, sent by post with the correct stamp.'), 'one entry per envelope');
  assert.ok(html.includes('Tuesday 22 December 2026, 7:00pm'), 'the deadline');
  const foot = footer(await page('/faqs'));
  assert.ok(foot.includes('<a href="/free-postal-entry">Free postal entry</a>'), 'footer link');
  assert.ok(!foot.includes(ADDRESS), 'no postal address in the footer');
});

test('the T&Cs keep the free-entry clause, point to /free-postal-entry and mention the skill question; the FAQs explain it', async () => {
  const terms = await page('/terms');
  assert.ok(terms.includes('Skill question: to enter a paid competition online you must answer a multiple-choice question correctly'));
  assert.ok(terms.includes('Free entry route: every paid competition can also be entered free by post') && terms.includes('href="/free-postal-entry"'));
  assert.ok((await page('/faqs')).includes('Why is there a question before I pay?'));
});

test('the sitemap lists /free-postal-entry and the competitions; robots.txt points to it', async () => {
  const res = await fetch(`${base}/sitemap.xml`);
  assert.equal(res.headers.get('content-type'), 'application/xml; charset=utf-8');
  const xml = await res.text();
  assert.ok(xml.includes('<loc>https://wdscompetitions.co.uk/free-postal-entry</loc>'));
  assert.ok(xml.includes('<loc>https://wdscompetitions.co.uk/competitions/bmw</loc>'));
  assert.ok(!xml.includes('/admin') && !xml.includes('/basket') && !xml.includes('/account'));
  const robots = await (await fetch(`${base}/robots.txt`)).text();
  assert.ok(robots.includes('Sitemap: https://wdscompetitions.co.uk/sitemap.xml') && robots.includes('Disallow: /admin'));
});

test('the basket has the skill question area and sends the answers to checkout', async () => {
  const html = await page('/basket');
  assert.ok(html.includes('data-skill') && html.includes('Answer the question'));
});

test('admin: the skill question is editable on a draft and shown in the publish confirm', async () => {
  const html = await page(`/admin/competitions/${DRAFT.id}`, 'wds_admin=admin');
  assert.ok(html.includes('name="sqQuestion"') && html.includes('value="What part of a car do coilovers replace?"'));
  assert.ok(html.includes('value="The suspension (springs and dampers)"'));
  assert.match(html, /name="sqCorrect" value="2" checked/);
  assert.ok(html.includes('maximum per person and skill question are fixed'));
  assert.ok(html.includes('Skill question: &quot;What part of a car do coilovers replace?&quot; (correct answer: &quot;The suspension (springs and dampers)&quot;)'), 'in the confirm');
});

test('legal minimum: a paid competition page links to /free-postal-entry under Enter, and the footer keeps the link', async () => {
  const html = await page(`/competitions/${PAID.slug}`);
  assert.match(html, /<p class="free-route small" data-free-route>Free entry route available – see <a href="\/free-postal-entry">free postal entry<\/a><\/p>/, 'the small free-route line');
  assert.ok(footer(html).includes('href="/free-postal-entry"'), 'footer Help link');
  assert.ok(html.includes('href="/terms"'), 'T&Cs link');
  assert.ok(footer(await page('/')).includes('href="/free-postal-entry"'), 'footer link on every page');
  assert.match(await page('/terms'), /href="\/free-postal-entry"/, 'the T&Cs clause links to it');
});

test('buying is the focus: no postal promotion in the header, menu, home or competition page', async () => {
  for (const path of ['/', `/competitions/${PAID.slug}`, '/how-it-works', '/competitions', '/winners', '/basket']) {
    const html = await page(path);
    const head = html.slice(0, html.indexOf('<main'));
    assert.ok(!head.includes('/free-postal-entry'), `${path}: no Free postal entry in the header or menu`);
    assert.ok(!/free postal entry on every paid competition/i.test(html), `${path}: no "on every paid competition" banner`);
    // (The basket's terms tick-box and the competition's Terms summary may say "enter free by post": that's the legal minimum.)
    assert.ok(!/>Enter free by post|<strong>Free<\/strong> by post|Two ways to enter|>Option [AB12]</.test(html), `${path}: no postal option cards or buttons`);
    assert.ok(!/class="btn[^"]*"[^>]*href="\/free-postal-entry"|href="\/free-postal-entry"[^>]*class="btn/.test(html), `${path}: never a button to the postal page`);
  }
  const home = await page('/');
  assert.ok(home.includes('<strong>Bundle deals</strong><br>Multi-buy ticket bundles') || home.includes('<strong>Bundle deals</strong><br />Multi-buy ticket bundles'), 'item 04 is bundle deals');
  assert.ok(home.includes('Enter in three steps') && home.includes('Answer the question and check out securely') && /<div class="steps-cta"[^>]*><a href="\/competitions"[^>]*>View competitions/.test(home), 'one 3-step how it works with a competitions CTA');
  const comp = await page(`/competitions/${PAID.slug}`);
  assert.ok(!/By post/.test(comp), 'no "By post: Free" price next to the ticket price');
});

const DEFAULTS = ['Ticket bundles available', 'Low ticket numbers, better odds', 'Numbers emailed instantly', 'Drawn live on Instagram @wds_competitions'];
const tickerOf = (html) => { const i = html.indexOf('data-ticker'); return i < 0 ? '' : html.slice(i, html.indexOf('</div>', html.indexOf('class="track"', i))); };
const shown = (t) => [...t.matchAll(/<span class="msg(?: last)?"[^>]*>([^<]*)<i><\/i><\/span>/g)].map((m) => m[1]);

test('the scrolling banner: empty hides it; messages scroll in order under the header on the home page', async () => {
  SETTINGS = { ...SITE, bannerMessages: [] };
  try {
    assert.ok(!(await page('/')).includes('data-ticker'), 'no messages: no ticker');
    SETTINGS = { ...SITE, bannerMessages: ['  ', ''] };
    assert.ok(!(await page('/')).includes('data-ticker'), 'blank lines count as empty');

    SETTINGS = { ...SITE, bannerMessages: DEFAULTS };
    const home = await page('/');
    const t = tickerOf(home);
    assert.deepEqual(shown(t), DEFAULTS, 'each message once, in order (the screen-reader copy)');
    const all = [...t.matchAll(/<span class="msg[^"]*"[^>]*>([^<]*)<i><\/i>/g)].map((m) => m[1]);
    assert.ok(all.length >= 12 && all.length % DEFAULTS.length === 0, 'repeated for a seamless loop');
    all.forEach((m, i) => assert.equal(m, DEFAULTS[i % DEFAULTS.length], 'the loop keeps the order'));
    assert.ok((t.match(/<i><\/i>/g) ?? []).length === all.length, 'the diamond separator after each message');
    assert.ok(home.indexOf('data-ticker') > home.indexOf('</header>') && home.indexOf('data-ticker') < home.indexOf('class="hero"'), 'directly under the header, above the hero');
    assert.ok(!(await page(`/competitions/${PAID.slug}`)).includes('data-ticker'), 'home page only, as before');
    for (const path of ['/', `/competitions/${PAID.slug}`, '/faqs']) assert.ok(!(await page(path)).includes('data-announce'), `${path}: no static white bar`);
  } finally {
    SETTINGS = null;
  }
  assert.deepEqual(shown(tickerOf(await page('/'))), DEFAULTS, 'sample settings: the default messages');
});

test('the default banner messages never mention free post', () => {
  for (const m of DEFAULTS) assert.ok(!/free|post/i.test(m), m);
});

test('admin settings: "Scrolling banner messages" is a one-per-line textarea, filled with the saved messages', async () => {
  const html = await page('/admin/settings', 'wds_admin=admin');
  assert.ok(html.includes('Scrolling banner messages'));
  assert.match(html, /<textarea id="bm" name="bannerMessages" data-type="lines"[^>]*>Ticket bundles available\nLow ticket numbers, better odds<\/textarea>/);
  assert.ok(!html.includes('name="announcementBar"'), 'the old single-line field is gone');
});

const STREET = 'Knoll House, Knoll Road';
const mainOf = (html) => html.slice(html.indexOf('<main'), html.indexOf('</main>'));
const footerOf = (html) => html.slice(html.indexOf('<footer'), html.indexOf('</footer>'));

test('company details: full in the T&Cs and privacy policy; no street address on the About page, Contact page or footer', async () => {
  const terms = await page('/terms');
  assert.match(terms, /<h2 class="h3" id="company-details">\d+\. Company details<\/h2>/, 'a Company details section in the T&Cs');
  for (const fact of ['WDS Originals Limited, trading as WDS Competitions', 'company no. 13330886', 'Registered office: Knoll House, Knoll Road, Camberley, Surrey, GU15 3SY']) {
    assert.ok(mainOf(terms).includes(fact), `T&Cs: ${fact}`);
  }
  assert.ok(mainOf(terms).includes('The promoter is WDS Originals Limited (company no. 13330886), trading as WDS Competitions, of Knoll House, Knoll Road, Camberley, Surrey, GU15 3SY.'), 'the promoter clause');
  assert.ok(mainOf(await page('/privacy')).includes('Registered office: Knoll House, Knoll Road, Camberley, Surrey, GU15 3SY'), 'privacy policy');

  for (const path of ['/', '/about', '/contact', '/competitions', `/competitions/${PAID.slug}`, '/faqs', '/how-it-works', '/terms', '/free-postal-entry']) {
    const html = await page(path);
    assert.ok(!footerOf(html).includes(STREET) && !footerOf(html).includes('GU15'), `${path}: no address in the footer`);
    assert.match(footerOf(html), /© \d{4} WDS Originals Limited<\/span>.*Registered in England &amp; Wales, company no\. 13330886<\/span>.{0,120}Full company details in our <a href="\/terms#company-details">Terms<\/a>\./s, `${path}: minimal legal line`);
  }
  const about = await page('/about');
  assert.ok(!about.includes(STREET) && !about.includes('Company details'), 'About: no Company details section');
  assert.ok(!mainOf(await page('/contact')).includes(STREET), 'Contact: no registered office');
  // The free postal entry page keeps its postal address.
  assert.ok(mainOf(await page('/free-postal-entry')).includes('Knoll Road'), 'postal address kept');
});

test('client copy: home Dream it block, competitions intro, meta description', async () => {
  const home = await page('/');
  // The "Dream it. Build it. Drive it. Win it." paragraph: different wording on the home and About pages.
  assert.ok(home.includes('<p class="body" data-dream>WDS Originals builds high-performance and modern classic BMWs, from beautifully refreshed OEM+ examples to full race-inspired track day builds. Now is your chance to win them.</p>'), 'home');
  const about = await page('/about');
  assert.ok(about.includes('<p class="body" data-dream>WDS Competitions exists to give you the chance to win prizes straight from the WDS Originals workshop.</p>'), 'About');
  assert.ok(/Dream it\. Build it\.<br\s*\/?>Drive it\. Win it\./.test(about) && about.includes('>View competitions</a>'), 'About keeps the heading and buttons');
  assert.ok(!home.includes('Win genuine WDS Originals builds. Every draw streamed live.'), 'old tagline gone');
  assert.ok(home.includes('<meta name="description" content="Win modern classic BMWs, parts and merchandise. Low ticket numbers, great odds, and every draw is streamed live on our social media.">'));
  const comps = await page('/competitions');
  const pts = [...comps.matchAll(/<span class="pt">([^<]+)/g)].map((m) => m[1]);
  assert.deepEqual(pts, ['Low ticket numbers', 'Great odds', 'Highly exclusive prizes']);
  assert.ok(/data-points[^>]*>.*Low ticket numbers.*·.*Great odds.*·.*Highly exclusive prizes/s.test(comps));
  assert.ok(!comps.includes('Low ticket numbers and a live draw on every competition.'));
});

test('footer: no tagline; social links as icon + handle, in order, with the right YouTube link; Facebook only when set', async () => {
  const sl = (html) => [...footerOf(html).matchAll(/<a href="([^"]+)" target="_blank" rel="noopener" data-social="(\w+)"/g)].map((m) => [m[2], m[1]]);
  const home = await page('/');
  assert.ok(!footerOf(home).includes('class="strap"') && !footerOf(home).includes('Be sure to enter for your chance to win'), 'no tagline');
  assert.deepEqual(sl(home), [
    ['instagram', 'https://www.instagram.com/wds_originals/'],
    ['youtube', 'https://www.youtube.com/@wds_originals'],
    ['instagram', 'https://www.instagram.com/wds_competitions/'],
  ], 'defaults (sample settings)');
  assert.ok(!home.includes('youtube.com/@wdsoriginals'), 'never the old, wrong YouTube link');

  const links = [
    { network: 'instagram', label: '@wds_originals', url: 'https://www.instagram.com/wds_originals/' },
    { network: 'youtube', label: '@wds_originals', url: 'https://www.youtube.com/@wds_originals' },
    { network: 'instagram', label: '@wds_competitions', url: 'https://www.instagram.com/wds_competitions/' },
  ];
  SETTINGS = { ...SITE, socialLinks: links };
  try {
    const html = await page('/');
    assert.equal(sl(html).length, 3);
    assert.ok(!footerOf(html).includes('data-social="facebook"'), 'Facebook hidden when empty');
    assert.match(footerOf(html), /<span class="sr-only"[^>]*>YouTube <\/span>@wds_originals/, 'handle label, with the network for screen readers');
    SETTINGS = { ...SITE, socialLinks: [...links, { network: 'facebook', label: 'Facebook', url: 'https://www.facebook.com/wdsoriginals' }] };
    for (const path of ['/', '/contact']) {
      const h = await page(path);
      assert.deepEqual(sl(h).at(-1), ['facebook', 'https://www.facebook.com/wdsoriginals'], `${path}: Facebook shown when set`);
    }
    assert.ok(/data-social="youtube"/.test(mainOf(await page('/contact'))), 'Contact page has the social links too');
  } finally {
    SETTINGS = null;
  }
});

test('no mojibake: the competitions intro has real middle dots', async () => {
  const comps = await page('/competitions');
  assert.ok(comps.includes('·'), 'has ·');
  // A-circumflex, a-circumflex + euro sign, A-tilde (escaped so this file itself stays clean for the encoding check).
  const MOJIBAKE = ['\u00c2', '\u00e2\u20ac', '\u00c3'];
  for (const bad of MOJIBAKE) assert.ok(!comps.includes(bad), `no ${bad}`);
  for (const path of ['/', '/about', '/contact', '/terms', '/free-postal-entry', `/competitions/${PAID.slug}`]) {
    const html = await page(path);
    for (const bad of MOJIBAKE) assert.ok(!html.includes(bad), `${path}: no ${bad}`);
  }
});

test('FAQs: every question closed by default, each with an id to link to (free entry: #free-entry)', async () => {
  const html = await page('/faqs');
  const details = [...html.matchAll(/<details([^>]*)>/g)].map((m) => m[1]);
  assert.ok(details.length >= 15);
  assert.ok(details.every((attrs) => !/\sopen(\s|=|$)/.test(attrs)), 'none open');
  const ids = details.map((attrs) => attrs.match(/id="([^"]+)"/)?.[1]);
  assert.ok(ids.every(Boolean) && new Set(ids).size === ids.length, 'unique ids');
  assert.ok(/<details id="free-entry"[^>]*><summary>Can I enter for free\?<\/summary>/.test(html));
});

// Pre-launch cleanup (reviewer): no placeholders, debit cards only, official payment marks, tighter postal rules,
// plain-English draws on the public pages, short FAQs.
const PUBLIC = ['/', '/competitions', `/competitions/${PAID.slug}`, '/faqs', '/terms', '/privacy', '/cookies', '/refunds', '/complaints',
  '/our-commitment', '/website-terms', '/draw-process', '/how-it-works', '/free-postal-entry', '/responsible-play', '/live-draws',
  '/verify', '/about', '/contact', '/winners', '/basket', '/sign-in'];
const textOf = (html) => html.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<style[\s\S]*?<\/style>/g, '').replace(/<[^>]+>/g, ' ')
  .replace(/&amp;/g, '&').replace(/&#39;|&rsquo;/g, "'").replace(/\s+/g, ' ');
const faqAnswers = (html) => Object.fromEntries([...html.matchAll(/<summary>([^<]*)<\/summary><p>([\s\S]*?)<\/p><\/details>/g)]
  .map((m) => [textOf(m[1]).trim(), textOf(m[2]).trim()]));

test('no placeholder text on any public page', async () => {
  for (const path of PUBLIC) {
    const text = textOf(await page(path));
    for (const bad of ['[Confirm', '[TBC', '[Insert', '[To confirm', '[DATE]', 'Placeholder:', 'Draft for legal review', 'TBC']) assert.ok(!text.includes(bad), `${path}: ${bad}`);
    assert.ok(!/lorem/i.test(text), `${path}: lorem`);
    assert.ok(!/\[[A-Z][^\]]{2,}\]/.test(text), `${path}: bracketed note ${text.match(/\[[A-Z][^\]]{2,}\]/)?.[0]}`);
  }
});

const WHO = "Anyone aged 18 or over who lives in the UK. Employees and contractors of WDS Originals Limited, their immediate families and households, and anyone professionally connected with running the competitions can't enter.";
test('who can enter: the same wording in the FAQ and the T&Cs eligibility clause', async () => {
  assert.equal(faqAnswers(await page('/faqs'))['Who can enter?'], WHO);
  assert.ok(textOf(await page('/terms')).includes(`2. Who can enter ${WHO}`), 'T&Cs clause 2');
});

test('debit cards only: no credit card wording on public pages; How do I pay? and the T&Cs say so plainly', async () => {
  for (const path of PUBLIC.filter((p) => p !== '/terms')) assert.ok(!/credit card|credit-card|£250 a month/i.test(textOf(await page(path))), path);
  assert.equal(faqAnswers(await page('/faqs'))['How do I pay?'], "Add your tickets to the basket and pay on our payment provider's secure checkout page by debit card, Apple Pay or Google Pay. Any site credit in your account is used first.");
  const terms = textOf(await page('/terms'));
  assert.ok(terms.includes("Payment Payment is by debit card, including Apple Pay or Google Pay using a debit card. Credit cards aren't accepted."), 'T&Cs payment clause');
  assert.equal((terms.match(/credit card/gi) ?? []).length, 1, 'the T&Cs mention credit cards only to say they are not accepted');
  assert.ok(textOf(await page('/basket')).includes('pay by debit card, Apple Pay or Google Pay'), 'basket');
});

test('footer payments: official marks on light badges, same size, alt text; "Debit cards only" in normal case', async () => {
  const html = await page('/faqs');
  const pay = footerOf(html).match(/<div class="payments"[\s\S]*?<\/div>/)[0];
  assert.ok(pay.includes('Secure payments by Cashflows'));
  const imgs = [...pay.matchAll(/<img src="\/images\/payments\/([a-z-]+)\.svg" alt="([^"]+)" width="38" height="24"/g)].map((m) => [m[1], m[2]]);
  assert.deepEqual(imgs, [['visa', 'Visa'], ['mastercard', 'Mastercard'], ['apple-pay', 'Apple Pay'], ['google-pay', 'Google Pay']]);
  assert.ok(pay.includes('<span class="debit">Debit cards only</span>') && !pay.includes('DEBIT'));
  const { readFileSync } = await import('node:fs');
  const footerSrc = readFileSync(new URL('../src/components/Footer.astro', import.meta.url), 'utf8');
  assert.ok(!/\.debit \{[^}]*uppercase/.test(footerSrc), 'not upper-cased by CSS');
  const svg = (k) => readFileSync(new URL(`../public/images/payments/${k}.svg`, import.meta.url), 'utf8');
  // Brand colours of the official marks (Shopify payment_icons v1.7.60): Visa blue, Mastercard red / orange / overlap.
  assert.ok(svg('visa').includes('#142688'), 'Visa blue');
  for (const c of ['#EB001B', '#F79E1B', '#FF5F00']) assert.ok(svg('mastercard').includes(c), `Mastercard ${c}`);
  for (const k of ['visa', 'mastercard', 'google-pay']) assert.match(svg(k), /fill="#(fff|FFF)"/, `${k}: light badge`);
  for (const k of ['visa', 'mastercard', 'apple-pay', 'google-pay']) {
    assert.match(svg(k), /<title[^>]*>[^<]+<\/title>/, `${k}: title`);
    assert.match(svg(k), /width="38" height="24"/, `${k}: 38×24`);
  }
});

const POSTAL = ['One entry per envelope, sent by post with the correct stamp. Envelopes containing more than one entry count as one entry.',
  "Hand-delivered, bulk, mass-produced or third-party entries (entries sent on someone else's behalf) aren't accepted.",
  'If a competition has a maximum per person, online and postal entries count towards the same limit.'];
test('postal entry rules: the same short bullets on /free-postal-entry and in the T&Cs', async () => {
  const fpe = textOf(await page('/free-postal-entry'));
  const termsHtml = await page('/terms');
  const terms = textOf(termsHtml);
  for (const r of POSTAL) { assert.ok(fpe.includes(r), `page: ${r}`); assert.ok(terms.includes(r), `terms: ${r}`); }
  assert.ok(fpe.includes('Entries must arrive by the postal deadline shown on the competition.'));
  assert.ok(terms.includes('Entries must arrive by the postal deadline shown on the competition'));
  assert.ok(termsHtml.includes('<div class="body"><ul><li>One entry per envelope'), 'a real list, not a list inside a paragraph');
});

test('FAQ: how many times can I enter', async () => {
  assert.equal(faqAnswers(await page('/faqs'))['How many times can I enter?'], "If a competition has a maximum per person, it's shown on the competition page, and online and postal entries count towards it. Otherwise you can enter as many times as you like, as long as tickets remain available.");
});

test('verify page: plain-English draw explanation, no A/B or drand jargon; the lookup box and draws list stay', async () => {
  const html = await page('/verify');
  assert.ok(textOf(mainOf(html)).includes("How our draws work When a competition closes at 7pm, the entry list is locked and can't be changed. At 7:30pm we draw the winning number live on Instagram from numbered chips. The result is recorded here, so anyone can check the winning ticket was a genuine entry."));
  for (const p of ['/verify', '/faqs', '/how-it-works', '/draw-process', '/live-draws', '/our-commitment']) {
    const t = textOf(mainOf(await page(p)));
    for (const bad of ['Verity', 'drand', 'beacon', 'External draw', 'A ·', 'B ·', 'randomness', 'protocol R2', 'audit chain']) assert.ok(!t.includes(bad), `${p}: ${bad}`);
  }
  assert.ok(!/drand|Verity/.test(html.match(/<meta name="description" content="([^"]*)"/)?.[1] ?? ''), 'meta description');
  assert.ok(html.includes('method="get" action="/verify"') && html.includes('name="q"'), 'lookup box');
  assert.ok(html.includes('Published draws'), 'draws list');
});

test('FAQs are short: at most three sentences each, with the legal essentials kept', async () => {
  const html = await page('/faqs');
  const answers = faqAnswers(html);
  assert.ok(Object.keys(answers).length >= 15, 'all questions found');
  for (const [q, a] of Object.entries(answers)) {
    const sentences = a.replace(/ [A-Z][^.]* →$/, '').split(/(?<=[.?!])\s+(?=[A-Z])/).filter(Boolean);
    assert.ok(sentences.length <= 3, `${q}: ${sentences.length} sentences`);
  }
  const all = Object.values(answers).join(' ');
  assert.ok(all.includes('18 or over') && all.includes('lives in the UK'), '18+, UK');
  assert.ok(answers['Can I enter for free?'].startsWith('Yes. Every paid competition can be entered free by post.') && html.includes('href="/free-postal-entry"'), 'free route');
  assert.ok(answers['When do postal entries close?'] && answers['What if not every ticket sells?'].includes('Closing dates are not extended'), 'closing dates');
  assert.ok(mainOf(html).includes('href="/terms"'), 'T&Cs link');
});

// Client tweaks: the full postal address, the limits line at the top of /free-postal-entry, winner publicity.
const POSTAL_ADDRESS = ['WDS Originals Ltd', 'WDS Competitions – Free Entry', 'Knoll House', 'Knoll Road', 'Camberley', 'Surrey', 'GU15 3SY', 'United Kingdom'];
test('/free-postal-entry shows the full address, in order, in the address box, the copy button and the example', async () => {
  const html = await page('/free-postal-entry'); // SETTINGS null: the site's sample settings, which match the API default
  const box = html.slice(html.indexOf('<address data-address>'), html.indexOf('</address>'));
  assert.equal(box.replace('<address data-address>', ''), POSTAL_ADDRESS.join('<br>'));
  assert.ok(html.includes(`data-copy="${POSTAL_ADDRESS.join(', ')}"`), 'copy button');
  assert.ok(html.includes(`<b>${POSTAL_ADDRESS.join('<br>')}</b>`), 'example envelope');
});

test('/free-postal-entry: the limits line sits at the top, and the four bullets stay', async () => {
  const html = await page('/free-postal-entry');
  const line = '<p class="limits" data-postal-limits>One entry per envelope. If a competition has a maximum per person, postal and online entries count towards the same limit.</p>';
  assert.ok(html.includes(line), 'the line');
  assert.ok(html.indexOf(line) < html.indexOf('</h1>') + 2000 && html.indexOf(line) < html.indexOf('data-address'), 'in the page header, before the address');
  const rules = html.slice(html.indexOf('<ol class="rules">'), html.indexOf('</ol>', html.indexOf('<ol class="rules">')));
  assert.equal((rules.match(/<li>/g) ?? []).length, 4, 'four bullets');
});

const PUBLICITY = "If you win, we'll publish your first name, surname and county, and a photo of you with your prize where you've agreed to one, on our website and social media. You can ask us not to publish your name or photo; if you do, we may still have to share these details with the Advertising Standards Authority or another regulator if asked.";
test('winner publicity: in the T&Cs winners clause, the privacy policy and a short FAQ', async () => {
  const terms = textOf(await page('/terms'));
  const winners = terms.slice(terms.lastIndexOf('. Winners '), terms.lastIndexOf('. Prizes '));
  assert.ok(winners.includes(PUBLICITY), 'T&Cs winners clause');
  const privacy = textOf(await page('/privacy'));
  assert.ok(privacy.slice(privacy.lastIndexOf('Who we share it with'), privacy.lastIndexOf('How long we keep it')).includes(PUBLICITY), 'privacy: who we share it with');
  const a = faqAnswers(await page('/faqs'))['Will my name be published if I win?'];
  assert.ok(a && a.includes('first name, surname and county') && a.includes('Advertising Standards Authority'), a);
});
