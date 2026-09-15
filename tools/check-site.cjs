/* Offline contract tests. No browser rendering and no real email requests. */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync('index.html', 'utf8');
const i18n = fs.readFileSync('translations.js', 'utf8');
const js = fs.readFileSync('script.js', 'utf8');
const data = JSON.parse(i18n.slice(i18n.indexOf('{'), i18n.indexOf('\nconst applyTranslation')).trim().replace(/;$/, ''));
const lookup = (lang, key) => key.split('.').reduce((v,k) => v?.[k], data[lang]);
const keys = [...html.matchAll(/data-i18n(?:-alt|-placeholder|-aria-label)?="([^"]+)"/g)].map(m=>m[1]);
for (const lang of ['de','en','it','hr']) {
 for (const key of keys) assert.equal(typeof lookup(lang,key), 'string', `${lang}: missing ${key}`);
 const serialized = JSON.stringify(data[lang]);
 assert(!/60\+|60 weddings|60 Hochzeiten|385.?916|djkeggybookings@hotmail/.test(serialized), `${lang}: stale public copy`);
 for (const key of ['hero','destination','seo','trust']) assert(!/Schweiz|Switzerland|Svizzera|Švicarsk/i.test(JSON.stringify(data[lang][key])));
 assert(/Schweiz|Switzerland|Svizzera|Švicarsk/i.test(data[lang].about.p2));
}
const ids = [...html.matchAll(/\bid="([^"]+)"/g)].map(m=>m[1]);
assert.equal(ids.length,new Set(ids).size,'Duplicate IDs');
for (const m of html.matchAll(/href="#([^"]+)"/g)) assert(ids.includes(m[1]), `Missing anchor: ${m[1]}`);
for (const m of html.matchAll(/(?:src|poster|href)="((?:images|videos)\/[^"#]+)"/g)) assert(fs.existsSync(m[1]), `Missing asset: ${m[1]}`);
assert(!/tel:|wa.me|385.?916|djkeggybookings@hotmail/.test(html));
assert.match(html,/mailto:info@marrydj\.com/);
assert.match(html,/<strong>40\+<\/strong>/);
const business = JSON.parse(html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/)[1]);
assert.equal(business.address.addressLocality, 'Poreč');
assert.equal(business.email, 'info@marrydj.com');
assert(!/Schweiz|Switzerland|Svizzera|Švicarsk/i.test(JSON.stringify(business.areaServed)));
assert.match(fs.readFileSync('send-offer.php', 'utf8'), /\$recipient\s*=\s*'info@marrydj\.com'/);
assert.match(html,/https:\/\/www.instagram.com\/dj__keggy\//);
assert.match(html,/<section[^>]*id="testimonials"[^>]*hidden/);
const hero=html.match(/<video[^>]*id="hero-video"[^>]*>/)[0];
for(const attr of ['autoplay','muted','loop','playsinline','poster=']) assert(hero.includes(attr));
const gallery=html.slice(html.indexOf('class="gallery-grid'),html.indexOf('id="videos"'));
const images=[...gallery.matchAll(/<img[^>]+src="([^"]+)"/g)].map(m=>m[1]);
assert.equal(images.length,2);assert.equal(new Set(images).size,2);
assert.equal((gallery.match(/loading="lazy"/g)||[]).length,2);
for(const name of ['firstname','lastname','email','phone','event_date','event_type','location','country','guests','start_time','end_time','music[]','services[]','singer','special_wishes','message','privacy']) assert(html.includes(`name="${name}"`),name);

// Test actual translation engine against a minimal DOM interface.
const elements=[...html.matchAll(/<([a-z][a-z0-9]*)\b([^>]*?)>/g)].map(m=>{
 const attrs=Object.fromEntries([...m[2].matchAll(/([\w-]+)="([^"]*)"/g)].map(a=>[a[1],a[2]]));
 return { tag:m[1],attrs,dataset:Object.fromEntries(Object.entries(attrs).filter(([k])=>k.startsWith('data-')).map(([k,v])=>[k.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase()),v])),textContent:'',disabled:false,
 getAttribute(k){return this.attrs[k]},setAttribute(k,v){this.attrs[k]=v},matches(s){return s==='button[type="submit"]' && this.tag==='button' && this.attrs.type==='submit'},classList:{toggle(){}},addEventListener(){} };
});
const findAll=s=>elements.filter(e=>s==='.lang-btn' ? e.attrs.class?.split(' ').includes('lang-btn'):s.startsWith('[data-') ? s.slice(1,-1) in e.attrs : false);
const metas={};
const doc={documentElement:{lang:'de'},title:'',querySelectorAll:findAll,querySelector(s){return metas[s] ||= {content:''}},dispatchEvent(){}};
const win={addEventListener(){}};
const context=vm.createContext({document:doc,window:win,URLSearchParams,URL,history:{replaceState(){}},location:{search:''}});
vm.runInContext(i18n,context);
for(const lang of ['de','en','it','hr']) {
 vm.runInContext(`window.currentLanguage='${lang}'; applyTranslation('${lang}');`,context);
 assert.equal(doc.documentElement.lang,lang);
 assert.equal(doc.title,data[lang].seo.title);
 for(const e of elements) {
  if(e.attrs['data-i18n']) assert.equal(e.textContent,lookup(lang,e.attrs['data-i18n']));
  for(const [attr,target] of [['data-i18n-placeholder','placeholder'],['data-i18n-aria-label','aria-label']]) if(e.attrs[attr]) assert.equal(e.attrs[target],lookup(lang,e.attrs[attr]));
  if(e.attrs['data-i18n-alt']) assert.equal(e.alt,lookup(lang,e.attrs['data-i18n-alt']));
 }
}

// Execute existing serializer, validator and submit handler with controlled I/O.
const extract=(start,end)=>js.slice(js.indexOf(start),js.indexOf(end,js.indexOf(start)));
// Exercise the existing mobile menu and improved lightbox event handlers.
class UIElement {
 constructor(){ this.events={};this.attrs={};this.dataset={};this.style={};this.inert=false;this.children=[];const classes=new Set();this.classList={add:k=>classes.add(k),remove:k=>classes.delete(k),contains:k=>classes.has(k),toggle(k,force){const on=force===undefined?!classes.has(k):force;if(on)classes.add(k);else classes.delete(k);return on}}; }
 addEventListener(name,fn){(this.events[name] ||= []).push(fn)}
 emit(name,event={}){for(const fn of this.events[name]||[])fn(event)}
 setAttribute(k,v){this.attrs[k]=v} getAttribute(k){return this.attrs[k]}
 focus(){this.focused=true} appendChild(el){this.children.push(el)}
 querySelector(s){return this.selectors?.[s]}
 querySelectorAll(){return this.children}
}
const toggle=new UIElement(), nav=new UIElement(), navLink=new UIElement(), body=new UIElement(), uiDoc=new UIElement();nav.children=[navLink];
const ui=vm.createContext({menuToggle:toggle,siteNav:nav,document:uiDoc,console});uiDoc.body=body;
vm.runInContext(extract('const closeMenu =','const updateNavbar ='),ui);
toggle.emit('click');assert.equal(toggle.attrs['aria-expanded'],'true');assert(nav.classList.contains('open'));assert(body.classList.contains('nav-open'));
navLink.emit('click');assert.equal(toggle.attrs['aria-expanded'],'false');assert(!nav.classList.contains('open'));
toggle.emit('click');uiDoc.emit('keydown',{key:'Escape'});assert(!nav.classList.contains('open'));
const lightboxUI=new UIElement(), closeUI=new UIElement(), imageUI=new UIElement(), galleryLink=new UIElement(), galleryImage=new UIElement(), background=new UIElement();
background.tagName='MAIN';body.children=[background];
galleryImage.alt='Wedding';galleryImage.src='images/dj1.jpg';galleryImage.dataset.i18nAlt='a11y.dj';galleryLink.selectors={img:galleryImage};galleryLink.attrs.href='images/dj1.jpg';
lightboxUI.selectors={'.lightbox-image':imageUI,'.lightbox-close':closeUI};
uiDoc.createElement=()=>lightboxUI;uiDoc.querySelectorAll=()=>[galleryLink];
vm.runInContext(extract('const lightbox =','const setStatus ='),ui);
galleryLink.emit('click',{preventDefault(){}});assert(lightboxUI.classList.contains('open'));assert.equal(imageUI.src,'images/dj1.jpg');assert(background.inert);assert(closeUI.focused);
uiDoc.emit('keydown',{key:'Escape'});assert(!lightboxUI.classList.contains('open'));assert(!background.inert);assert(galleryLink.focused);
const fields=[];
assert.match(html, /<input\b[^>]*type="time"[^>]*name="end_time"[^>]*required[^>]*>/);
const phpSource = fs.readFileSync('send-offer.php', 'utf8');
assert(phpSource.includes("$endTime = sanitize(get_value($data, 'end_time'));"));
assert(phpSource.includes('|| !$validEndTime ||'));
assert(phpSource.includes("$adminBody[] = 'Završetak / Ende: ' . $endTime;"));
assert(phpSource.includes("$adminBody[] = 'Početak / Start: ' . $startTime;"));
const field=(name,value,type='text',required=false)=>({name,value,type,required,checked:false,min:'',validationMessage:'',classList:{toggle(){},remove(){}},setAttribute(){},setCustomValidity(v){this.validationMessage=v},get validity(){return {valid:!this.validationMessage && (!this.required || (this.type==='checkbox'?this.checked:!!this.value.trim()))}},checkValidity(){return this.validity.valid}});
for(const [n,v] of Object.entries({firstname:'Test',lastname:'Couple',email:'couple@example.com',phone:'+385 91 123 4567',event_type:'Hochzeit',event_date:'2027-06-12',start_time:'18:00',location:'Poreč',country:'Croatia',guests:'80',end_time:'03:00',singer:'yes',special_wishes:'First dance',message:'Wedding inquiry'})) fields.push(field(n,v));
const privacy=field('privacy','on','checkbox',true); privacy.checked=true;fields.push(privacy);
const endField = fields.find(f => f.name === 'end_time');
endField.required = true; endField.type = 'time'; endField.value = '02:00';
endField.focus = () => {}; endField.scrollIntoView = () => {};
const button={disabled:false,textContent:''}; const handlers={};
const form={elements:{namedItem:n=>fields.find(f=>f.name===n)},querySelector:()=>button,querySelectorAll:()=>fields,addEventListener:(n,f)=>handlers[n]=f,classList:{add(){}},reset(){this.resetCount=(this.resetCount||0)+1}};
let result={ok:true,status:200,body:{success:true,email_sent:true}}; let request; let status;
const runtime=vm.createContext({window:win,document:doc,offerForm:form,HTMLElement:Object,AbortSignal,FormData:class {constructor(form){this.form=form} entries(){return [...fields.filter(f=>f.type!=='checkbox'||f.checked).map(f=>[f.name,f.value]),['music[]','Balkan'],['music[]','House'],['services[]','DJ-Service']][Symbol.iterator]()}},updateProgress(){},setStatus:(message,error)=>status={message,error},fetch:async (url,opts)=>{request={url,opts};if(result.throw)throw Error('Offline');return {...result,json:async()=>result.body}},console});
vm.runInContext(extract('const getFormLabels =','const serializeForm =')+extract('const serializeForm =','const accordionTriggers =')+extract('const validateField =','// Close the mobile menu'),runtime);
(async()=>{
 for(const lang of ['de','en','it','hr']) {
  win.currentLanguage=lang;doc.documentElement.lang=lang;
  result={ok:true,status:200,body:{success:true,email_sent:true}};
  const previousRequest = request;
  endField.value = '';
  await handlers.submit({preventDefault(){}});
  assert.equal(status.message, data[lang].feedback.requiredFields);
  assert.equal(request, previousRequest, 'Empty end time must not trigger a request');
  endField.value = '02:00';
  await handlers.submit({preventDefault(){}});
  assert.equal(status.message,data[lang].feedback.success);
  assert.equal(request.url,'send-offer.php');
  const payload=JSON.parse(request.opts.body);
  for(const name of ['firstname','lastname','email','phone','event_type','event_date','start_time','location','country','guests','privacy']) assert(payload[name]);
  assert.deepEqual(payload['music[]'],['Balkan','House']);
  assert(payload['services[]'].includes('Live-Sänger'));
  assert.equal(payload.start_time, '18:00');
  assert.equal(payload.end_time, '02:00');
  assert(payload.message.includes('First dance') && payload.message.includes('Yes / Ja'));
  assert.equal(payload.language,lang);
  const resetCount=form.resetCount;
  result={ok:false,status:500,body:{success:false}};
  await handlers.submit({preventDefault(){}});
  assert.equal(status.message,data[lang].feedback.error);assert.equal(form.resetCount,resetCount);assert.equal(button.disabled,false);
  result={ok:true,status:200,body:{success:true,email_sent:false}};
  await handlers.submit({preventDefault(){}});assert.equal(status.error,true);
  result={ok:false,status:422,body:{success:false}};
  await handlers.submit({preventDefault(){}});assert.equal(status.message,data[lang].feedback.requiredFields);
  result={throw:true};await handlers.submit({preventDefault(){}});assert.equal(status.message,data[lang].feedback.error);
 }
 const phone=fields.find(f=>f.name==='phone');phone.value='abc';runtime.testField=phone;
 vm.runInContext('validateField(testField)',runtime);assert.equal(phone.checkValidity(),false);
 phone.value='+385 91 123 4567';vm.runInContext('validateField(testField)',runtime);assert.equal(phone.checkValidity(),true);
 console.log('PASS: four-language text/metadata/labels/placeholders; asset and anchor integrity; gallery uniqueness; public contact/Swiss rules; menu/lightbox event logic; PHP payload compatibility; simulated success, failure, validation and network errors.');
 console.log('NOT TESTED: browser layout, native media playback, live PHP/email delivery.');
})().catch(e=>{console.error(e);process.exitCode=1});
