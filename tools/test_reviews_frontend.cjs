/* Offline tests for review rendering and fragment exchange; no real browser. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
  constructor() { this.children = []; this.attributes = {}; this.hidden = true; }
  append(...children) { this.children.push(...children); }
  replaceChildren() { this.children = []; }
  setAttribute(key, value) { this.attributes[key] = value; }
  get childElementCount() { return this.children.length; }
  set innerHTML(_) { throw new Error('Guest text must never be parsed as HTML'); }
}
async function main() {
  const grid = new Element(), section = new Element();
  section.querySelector = () => grid;
  const review = {stars: 5, display_name: '<img onerror=alert(1)>', body: '<script>alert(1)</script>', language: 'hr'};
  vm.runInNewContext(fs.readFileSync('review/homepage.js','utf8'), {
    document: { getElementById: () => section, createElement: () => new Element() },
    fetch: async (url, options) => {
      assert.equal(url, '/review/feed.php'); assert.equal(options.credentials, 'omit'); assert.equal(options.cache, 'no-store');
      return {ok: true, json: async () => ({reviews: [review]})};
    }
  });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(section.hidden, false); assert.equal(grid.childElementCount, 1);
  assert.equal(grid.children[0].lang, 'hr');
  assert.equal(grid.children[0].children[1].textContent, review.body);
  assert.equal(grid.children[0].children[2].textContent, review.display_name);
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
