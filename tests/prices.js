// Exercise the actual modal script without a browser or external dependencies.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const price = {registry_id: 'registry-id', scope: 'global', command: 'domain.create', tld: 'com', period: '1', amount: '12.5000', currency: 'EUR'};
const inputs = Object.fromEntries(Object.keys(price).map(key => [key, {value: ''}]));
const description = {textContent: ''};
const listeners = {};
const cancel = {addEventListener: (event, fn) => { listeners.cancel = fn; }};
const dialog = {
  open: false,
  showModal() { this.open = true; }, close() { this.open = false; },
  querySelector(selector) {
    if (selector === '[data-price-close]') return cancel;
    if (selector === '[data-price-description]') return description;
    return inputs[selector.match(/name="([^"]+)"/)[1]];
  }
};
const button = {dataset: {priceEdit: JSON.stringify(price)}, addEventListener: (event, fn) => {listeners.open = fn;}};
vm.runInNewContext(fs.readFileSync(`${__dirname}/../panel/public/prices.js`, 'utf8'), {
  document: {getElementById: () => dialog, querySelectorAll: () => [button]}
});
listeners.open();
assert.equal(dialog.open, true);
for (const [key, value] of Object.entries(price)) assert.equal(inputs[key].value, value);
assert.match(description.textContent, /\.com.*domain.create.*1 years.*global/);
listeners.cancel();
assert.equal(dialog.open, false);
button.dataset.priceEdit = JSON.stringify({...price, tld: '', command: 'domain.restore', period: '0', amount: '0.0000'});
listeners.open();
assert.equal(inputs.amount.value, '0.0000');
assert.match(description.textContent, /Registry-wide.*Per operation/);
console.log('PASS: 12 price modal assertions');
