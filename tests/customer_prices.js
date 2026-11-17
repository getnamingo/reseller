const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../panel/public/prices.js`, 'utf8');
let checks = 0;
function check(value, message) { checks++; assert.ok(value, message); }
function page(rows) {
  const events = {}, downloads = [], blobs = [], revoked = [], timers = [];
  const search = {value: '', addEventListener: (name, fn) => {events.search = fn;}};
  const button = {disabled: false, addEventListener: (name, fn) => {events.export = fn;}};
  const elements = {'customer-prices': {querySelectorAll: () => rows}, 'price-tld-search': search,
    'price-export': button, 'price-no-matches': {}, 'price-result-count': {}, 'customer-prices-tools': {hidden: true}};
  const document = {getElementById: id => elements[id] ?? null, body: {appendChild() {}},
    createElement() { return {click() {downloads.push({href: this.href, download: this.download});}, remove() {}}; }};
  vm.runInNewContext(source, {document, Blob, URL: {createObjectURL(blob) {blobs.push(blob); return 'blob:prices';}, revokeObjectURL(url) {revoked.push(url);}}, setTimeout: fn => timers.push(fn)});
  return {events, search, button, elements, downloads, blobs, revoked, timers};
}
function row(tld, registry, values = ['12.5000 EUR', '0.0000 EUR', '', '40.0000 EUR']) {
  return {dataset: {priceTld: tld, priceRegistry: registry}, hidden: false,
    querySelectorAll: () => values.map(priceValue => ({dataset: {priceValue}}))};
}
(async () => {
  const rows = [row('com', 'Example'), row('co.uk', '=HYPERLINK("evil")'), row('net', 'A,"B"\nC')];
  const p = page(rows);
  check(!p.elements['customer-prices-tools'].hidden && !p.button.disabled, 'Controls enabled with data');
  check(p.elements['price-result-count'].textContent === '3 of 3 price rows', 'Initial count');
  p.search.value = ' .CO.UK '; p.events.search();
  check(rows[0].hidden && !rows[1].hidden && rows[2].hidden, 'Case-insensitive literal TLD filtering accepts a leading dot');
  p.events.export();
  const filtered = await p.blobs[0].text();
  check(filtered.includes('".co.uk"') && !filtered.includes('".com"'), 'Export limited to filtered rows');
  check(filtered.includes('"\'=HYPERLINK(""evil"")"'), 'Formula prefixes neutralized and quotes escaped');
  check(filtered.includes('"12.5000 EUR","0.0000 EUR","","40.0000 EUR"'), 'Exact precision, currency, free and missing prices retained');
  check(p.downloads[0].download === 'prices.csv' && p.downloads[0].href === 'blob:prices', 'CSV download created locally');
  check(p.blobs[0].type === 'text/csv;charset=utf-8', 'CSV media type');
  p.timers.forEach(fn => fn()); check(p.revoked[0] === 'blob:prices', 'Download object URL released');
  p.search.value = '<script>.*'; p.events.search();
  check(rows.every(r => r.hidden) && !p.elements['price-no-matches'].hidden && p.button.disabled, 'No-match state handles arbitrary input without regex or HTML');
  p.search.value = ''; p.events.search(); p.events.export();
  const all = await p.blobs[1].text();
  check(rows.every(r => !r.hidden) && p.elements['price-no-matches'].hidden, 'Clearing search restores rows');
  check(all.includes('"A,""B""\nC"'), 'CSV commas and embedded newlines quoted');
  const hostile = page([row('test', ' \t+SUM(1,2)')]); hostile.events.export();
  check((await hostile.blobs[0].text()).includes('"\' \t+SUM(1,2)"'), 'Whitespace-prefixed formulas neutralized');
  const empty = page([]);
  check(empty.button.disabled && empty.elements['price-no-matches'].hidden, 'Empty catalogue has no duplicate search-empty message');
  vm.runInNewContext(source, {document: {getElementById: () => null}});
  check(true, 'No effect on unrelated pages');
  console.log(`PASS: ${checks} customer price search/export assertions`);
})().catch(error => {console.error(error); process.exitCode = 1;});

