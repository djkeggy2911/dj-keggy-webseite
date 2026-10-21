const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source=fs.readFileSync('translations.js','utf8');
const data=JSON.parse(source.slice(source.indexOf('{'),source.indexOf('\nconst applyTranslation')).trim().replace(/;$/,''));
const urls={de:'https://marrydj.com/',en:'https://marrydj.com/en.html',it:'https://marrydj.com/it.html',hr:'https://marrydj.com/hr.html'};
const escape=s=>s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
for(const [lang,url] of Object.entries(urls)) {
 const html=fs.readFileSync(lang==='de'?'index.html':lang+'.html','utf8');
 assert(html.includes(`<html lang="${lang}">`));
 assert(html.includes(`<link rel="canonical" href="${url}">`));
 assert(html.includes(`<title>${escape(data[lang].seo.title)}</title>`));
 assert.equal((html.match(/<h1\b/g)||[]).length,1);
 assert(!/noindex/i.test(html));
 for(const [other,href] of Object.entries(urls)) assert(html.includes(`hreflang="${other}" href="${href}"`));
 for(const match of html.matchAll(/<([a-z][a-z0-9]*)\b[^>]*\bdata-i18n="([^"]+)"[^>]*>([\s\S]*?)<\/\1>/g)) {
  const value=match[2].split('.').reduce((v,k)=>v[k],data[lang]);
  assert.equal(match[3],escape(value),`${lang}: static translation ${match[2]}`);
 }
 const business=JSON.parse(html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/)[1]);
 assert.equal(business.description,data[lang].seo.description);
 assert(business.areaServed.includes('Opatija'));
 // Direct language URLs initialize without needing a query parameter.
 let loaded; const metas={};
 const guideLink={hidden:lang!=='hr'};
 const document={documentElement:{lang},querySelectorAll:s=>s==='[data-hr-guide]'?[guideLink]:[],querySelector:s=>metas[s]??={}};
 const window={addEventListener:(event,fn)=>{if(event==='DOMContentLoaded')loaded=fn;}};
 vm.runInNewContext(source,{document,window,location:{search:''},URLSearchParams});loaded();
 assert.equal(window.currentLanguage,lang);
 assert.equal(document.title,data[lang].seo.title);
 assert.equal(metas['link[rel="canonical"]'].href,url);
 assert.equal(guideLink.hidden,lang!=='hr');
 assert.equal(html.includes('data-hr-guide hidden'),lang!=='hr');
 assert(fs.readFileSync('sitemap.xml','utf8').includes(`<loc>${url}</loc>`));
}
assert.match(fs.readFileSync('robots.txt','utf8'),/Sitemap: https:\/\/marrydj.com\/sitemap.xml/);
console.log('PASS: four static languages, headings, metadata, canonical/hreflang, schema, language initialization and sitemap.');
const guideFile='dj-za-vjencanja-hrvatska.html';
const guide=fs.readFileSync(guideFile,'utf8');
const guideUrl='https://marrydj.com/'+guideFile;
assert.equal((guide.match(/<h1\b/g)||[]).length,1);
assert(guide.includes('<html lang="hr">'));
assert(guide.includes(`rel="canonical" href="${guideUrl}"`));
assert(!guide.includes('hreflang='),'Guide has no translated equivalents');
assert(!/noindex|translations\.js|<!-- SEO_HEAD -->/.test(guide));
const graph=JSON.parse(guide.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/)[1])['@graph'];
assert.equal(graph.find(x=>x['@type']==='WebPage').url,guideUrl);
assert.equal(graph.find(x=>x['@type']==='Service').provider['@id'],graph.find(x=>x['@type']==='ProfessionalService')['@id']);
for(const file of ['index.html','en.html','it.html','hr.html',guideFile]) {
 const html=fs.readFileSync(file,'utf8');
 assert(html.includes('content="Xd5tc-gVxEqweweXB4eyCssxd8W_wpnrUkpDgsEE4FI"'));
 for(const m of html.matchAll(/(?:href|src)="([^"?#]*)(?:\?[^"#]*)?(?:#([^"]+))?"/g)) {
  if(/^[a-z]+:|^\/\//i.test(m[1]))continue;
  const target=m[1]||file;assert(fs.existsSync(target),`Missing ${target}`);
  if(m[2])assert(fs.readFileSync(target,'utf8').includes(`id="${m[2]}"`),`Missing anchor ${m[0]}`);
 }
}
assert(fs.readFileSync('sitemap.xml','utf8').includes(`<loc>${guideUrl}</loc>`));
assert(fs.readFileSync('deploy-files.txt','utf8').split(/\r?\n/).includes(guideFile));
console.log('PASS: Croatian guide schema, canonical, links, language visibility, sitemap, deployment and verification token.');
