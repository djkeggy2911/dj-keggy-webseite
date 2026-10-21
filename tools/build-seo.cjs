// Regenerate crawlable language pages after editing index.html or translations.js.
const fs = require('node:fs');
const crypto = require('node:crypto');
const source = fs.readFileSync('translations.js', 'utf8');
const data = JSON.parse(source.slice(source.indexOf('{'), source.indexOf('\nconst applyTranslation')).trim().replace(/;$/, ''));
const urls = {de:'https://marrydj.com/',en:'https://marrydj.com/en.html',it:'https://marrydj.com/it.html',hr:'https://marrydj.com/hr.html'};
const escape = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
let template = fs.readFileSync('index.html','utf8').replace(/\r\n/g,'\n');
for (const file of ['style.css','script.js','translations.js']) {
 const hash = crypto.createHash('sha256').update(fs.readFileSync(file,'utf8').replace(/\r\n/g,'\n')).digest('hex').slice(0,16);
 template = template.replace(new RegExp(file.replace('.','\\.')+'\\?v=[a-f0-9]+','g'),file+'?v='+hash);
}
template = template.replace(/<link rel="alternate" hreflang="[^"]+" href="[^"]+">\n?/g,'');
template = template.replace('</head>',Object.entries({...urls,'x-default':urls.de}).map(([lang,url])=>`<link rel="alternate" hreflang="${lang}" href="${url}">`).join('\n')+'\n</head>');
for(const [lang,url] of Object.entries(urls)) {
 const get = key => key.split('.').reduce((v,k)=>v[k],data[lang]);
 let html = template.replace(/<html lang="[^"]+">/,`<html lang="${lang}">`);
 html = html.replace(/(<([a-z][a-z0-9]*)\b[^>]*\bdata-i18n="([^"]+)"[^>]*>)[\s\S]*?(<\/\2>)/g,(_,open,tag,key,close)=>open+escape(get(key))+close);
 html = html.replace(/<[a-z][a-z0-9]*\b[^>]*>/g,tag=>{
  for(const [key,attr] of [['alt','alt'],['placeholder','placeholder'],['aria-label','aria-label']]) {
   const match=tag.match(new RegExp('data-i18n-'+key+'="([^"]+)"'));
   if(match) tag=tag.replace(new RegExp('(?<![\\w-])'+attr+'="[^"]*"'),attr+'="'+escape(get(match[1]))+'"');
  }
  return tag;
 });
 html=html.replace(/<title>[\s\S]*?<\/title>/,'<title>'+escape(data[lang].seo.title)+'</title>')
 .replace(/(<meta (?:name="description"|property="og:description") content=")[^"]*(">)/g,'$1'+escape(data[lang].seo.description)+'$2')
 .replace(/(<meta property="og:title" content=")[^"]*(">)/,'$1'+escape(data[lang].seo.title)+'$2')
 .replace(/(<link rel="canonical" href=")[^"]*(">)/,'$1'+url+'$2')
 .replace(/(<meta property="og:url" content=")[^"]*(">)/,'$1'+url+'$2');
 html=html.replace(/(<script type="application\/ld\+json">)([\s\S]*?)(<\/script>)/,(_,a,json,b)=>{
  const business=JSON.parse(json); business.description=data[lang].seo.description;
  return a+JSON.stringify(business)+b;
 });
 if (lang === 'hr') html = html.replace('data-hr-guide hidden', 'data-hr-guide');
 fs.writeFileSync(lang==='de'?'index.html':lang+'.html',html);
}
const guideUrl = 'https://marrydj.com/dj-za-vjencanja-hrvatska.html';
const guideTitle = 'DJ za vjenčanja u Hrvatskoj | Glazba i cijena | DJ Keggy';
const guideDescription = 'DJ za svadbe u Istri i cijeloj Hrvatskoj: glazba, razglas, rasvjeta i moderacija po dogovoru. Saznajte kako planirati vjenčanje i zatražiti ponudu.';
let head = fs.readFileSync('hr.html','utf8').match(/<head>([\s\S]*?)<\/head>/)[1];
head = head.replace(/<link rel="alternate"[^>]*>\n?/g,'')
 .replace(/<title>[\s\S]*?<\/title>/,()=>'<title>'+escape(guideTitle)+'</title>')
 .replace(/(<meta (?:name="description"|property="og:description") content=")[^"]*(">)/g,(_,a,b)=>a+escape(guideDescription)+b)
 .replace(/(<meta property="og:title" content=")[^"]*(">)/,(_,a,b)=>a+escape(guideTitle)+b)
 .replace(/(<link rel="canonical" href=")[^"]*(">)/,(_,a,b)=>a+guideUrl+b)
 .replace(/(<meta property="og:url" content=")[^"]*(">)/,(_,a,b)=>a+guideUrl+b)
 .replace(/(<script type="application\/ld\+json">)([\s\S]*?)(<\/script>)/,(_,a,json,b)=>{
  const business = JSON.parse(json);
  const service = {...business.hasOfferCatalog.itemListElement[0].itemOffered,
   '@id':guideUrl+'#service',url:guideUrl,name:'DJ za vjenčanja u Hrvatskoj',
   description:guideDescription,provider:{'@id':business['@id']}};
  const page = {'@type':'WebPage','@id':guideUrl+'#webpage',url:guideUrl,name:guideTitle,
   description:guideDescription,inLanguage:'hr',mainEntity:{'@id':service['@id']}};
  return a+JSON.stringify({'@context':'https://schema.org','@graph':[business,service,page]})+b;
 });
const guide = fs.readFileSync('tools/croatian-wedding-page.html','utf8').replace(/\r\n/g,'\n').replace('<!-- SEO_HEAD -->',()=>head.trim());
fs.writeFileSync('dj-za-vjencanja-hrvatska.html',guide);
fs.writeFileSync('sitemap.xml','<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'+[...Object.values(urls),guideUrl].map(url=>'  <url><loc>'+url+'</loc></url>').join('\n')+'\n</urlset>\n');
console.log('Generated four localized homepages, Croatian wedding guide and sitemap.xml.');
