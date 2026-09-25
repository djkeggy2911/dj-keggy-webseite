/* Offline tests for review rendering and fragment exchange; no real browser. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
  constructor() { this.children = []; this.attributes = {}; this.hidden = false; this.events = {}; }
  append(...children) { this.children.push(...children); }
  before() {}
  focus() { this.focused = true; }
  addEventListener(name, fn) { this.events[name] = fn; }
  replaceChildren() { this.children = []; }
  setAttribute(key, value) { this.attributes[key] = value; }
  get childElementCount() { return this.children.length; }
  set innerHTML(_) { throw new Error('Guest text must never be parsed as HTML'); }
}
async function main() {
  for (const count of [0, 1, 2, 3, 4, 7, 30]) {
    for (const width of [390, 800, 1280]) {
      const grid = new Element(), section = new Element(), created = [], events = {};
      section.querySelector = () => grid;
      const review = {stars: 5, display_name: '<img onerror=alert(1)>', body: '<script>alert(1)</script>', language: 'hr'};
      const root = {lang: 'de'};
      const media = [];
      vm.runInNewContext(fs.readFileSync('review/homepage.js','utf8'), {
        window: {matchMedia: query => { const m = {matches: width <= (query.includes('640') ? 640 : 960), addEventListener: (_e, fn) => {m.change = fn;}}; media.push(m); return m; }},
        document: {documentElement: root, addEventListener: (e, fn) => {events[e] = fn;}, getElementById: () => section, createElement: () => {const el = new Element(); created.push(el); return el;}},
        fetch: async (url, options) => {
          assert.equal(url, '/review/feed.php'); assert.equal(options.credentials, 'omit'); assert.equal(options.cache, 'no-store');
          return {ok: true, json: async () => ({reviews: Array.from({length: count}, () => ({...review}))})};
        }
      });
      await new Promise(resolve => setImmediate(resolve));
      assert.equal(section.hidden, count === 0);
      assert.equal(grid.childElementCount, count);
      if (!count) continue;
      const visible = () => grid.children.filter(c => !c.hidden);
      const perPage = width <= 640 ? 1 : width <= 960 ? 2 : 3;
      assert.equal(visible().length, Math.min(count, perPage));
      assert.equal(grid.children[0].lang, 'hr');
      assert.equal(grid.children[0].children[1].textContent, review.body);
      assert.equal(grid.children[0].children[2].textContent, review.display_name);
      const prev = created.find(e => e.className === 'review-arrow review-prev');
      const next = created.find(e => e.className === 'review-arrow review-next');
      const shell = created.find(e => e.className === 'review-slider');
      const dots = created.find(e => e.className === 'review-dots');
      assert.equal(prev.disabled, true);
      assert.equal(next.hidden, count <= perPage);
      assert.equal(dots.children.length, Math.ceil(count / perPage));
      if (count > perPage) {
        next.events.click(); assert.equal(grid.children[0].hidden, true);
        prev.events.click(); assert.equal(grid.children[0].hidden, false);
        shell.events.keydown({target: shell, key: 'ArrowRight', preventDefault() {}});
        assert.equal(grid.children[0].hidden, true);
        dots.children[0].events.click(); assert.equal(grid.children[0].hidden, false);
        grid.events.touchstart({touches: [{clientX: 200, clientY: 0}]});
        grid.events.touchend({changedTouches: [{clientX: 50, clientY: 5}]});
        assert.equal(grid.children[0].hidden, true);
        dots.children[dots.children.length - 1].events.click(); assert.equal(next.disabled, true);
        assert.equal(visible().length, count % perPage || perPage);
      }
      for (const lang of ['de','hr','en','it']) {
        root.lang = lang; events.languagechange();
        assert(prev.attributes['aria-label']); assert(next.attributes['aria-label']);
      }
      media[0].matches = true; media[0].change(); assert.equal(visible().length, 1);
    }
  }
  // Long text is safely shortened and expandable in every interface language.
  {
    const grid = new Element(), section = new Element(), events = {}, root = {lang: 'de'};
    section.querySelector = () => grid;
    const body = '<script>' + 'x'.repeat(3100);
    vm.runInNewContext(fs.readFileSync('review/homepage.js','utf8'), {
      window: {matchMedia: () => ({matches: false, addEventListener() {}})},
      document: {documentElement: root, addEventListener: (e, fn) => {events[e] = fn;}, getElementById: () => section, createElement: () => new Element()},
      fetch: async () => ({ok: true, json: async () => ({reviews: [{stars: 5, body, display_name: 'Test fixture'}]})})
    });
    await new Promise(resolve => setImmediate(resolve));
    const quote = grid.children[0].children[1], toggle = grid.children[0].children[2];
    assert(quote.textContent.length < body.length);
    for (const [lang, more, less] of [['de','Mehr lesen','Weniger anzeigen'],['hr','Pročitaj više','Prikaži manje'],['en','Read more','Show less'],['it','Leggi di più','Mostra meno']]) {
      root.lang = lang; events.languagechange(); assert.equal(toggle.textContent, more);
      toggle.events.click(); assert.equal(quote.textContent, body); assert.equal(toggle.textContent, less); assert.equal(toggle.attributes['aria-expanded'], 'true');
      toggle.events.click(); assert.equal(toggle.attributes['aria-expanded'], 'false');
    }
  }
  for (const valid of [true, false]) {
    let submitted = false, cleared = false;
    const token = 'a'.repeat(64);
    const form = {elements: {code: {value: ''}}, requestSubmit() {assert(cleared); assert.equal(this.elements.code.value, token); submitted = true;}};
    vm.runInNewContext(fs.readFileSync('review/review.js','utf8'), {
      URLSearchParams,
      location: {hash: '#code='+ (valid ? token : 'invalid'), pathname:'/review/',search:'?lang=hr'},
      history: {replaceState(_a,_b,url) {assert.equal(url,'/review/?lang=hr'); cleared = true;}},
      document: {getElementById: () => form}
    });
    assert.equal(submitted, valid);
  }
  const translations = JSON.parse(fs.readFileSync('review/translations.json', 'utf8'));
  for (const language of ['de','hr','en','it']) {
    assert.deepEqual(Object.keys(translations[language]),Object.keys(translations.de));
    assert(!Object.values(translations[language]).some(value => value.includes('\uFFFD')), 'Damaged text encoding');
  }
  const policy = fs.readFileSync('review/.htaccess','utf8');
  for (const directive of ["default-src 'none'", "script-src 'self'", "frame-ancestors 'none'", "form-action 'self'", "base-uri 'none'"]) assert(policy.includes(directive));
  console.log('PASS: review text rendering without HTML, fragment removal before POST, four-language completeness/encoding, scoped guest CSP.');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
