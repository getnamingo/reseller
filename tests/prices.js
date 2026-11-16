const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const group = {registry_id: 'registry-id', registry_name: 'Example', scope: 'global', scope_name: 'Global defaults', tld: 'com', currency: 'EUR', version: 'version-1', prices: {'domain.create': {1: '12.5000'}, 'domain.restore': {0: '8.0000'}}};
const inputs = Object.fromEntries(['registry_id', 'scope', 'tld', 'currency', 'version'].map(key => [key, {value: ''}]));
const amounts = [{dataset: {priceCommand: 'domain.create', pricePeriod: '1'}}, {dataset: {priceCommand: 'domain.create', pricePeriod: '2'}}, {dataset: {priceCommand: 'domain.restore', pricePeriod: '0'}}];
const description = {textContent: ''}; const terms = {hidden: false}; const listeners = {};
const form = {reset() { amounts.forEach(input => { input.value = ''; input.disabled = false; }); }};
const dialog = {
  open: false, showModal() { this.open = true; }, close() { this.open = false; },
  querySelector(selector) {
    if (selector === 'form') return form;
    if (selector === '[data-price-close]') return {addEventListener: (event, fn) => {listeners.cancel = fn;}};
    if (selector === '[data-price-description]') return description;
    if (selector === '[data-price-term-fields]') return terms;
    return inputs[selector.match(/name="([^"]+)"/)[1]];
  },
  querySelectorAll() { return amounts; }
};
const button = {dataset: {priceEdit: JSON.stringify(group)}, addEventListener: (event, fn) => {listeners.open = fn;}};
vm.runInNewContext(fs.readFileSync(`${__dirname}/../panel/public/prices.js`, 'utf8'), {document: {getElementById: () => dialog, querySelectorAll: () => [button]}});
listeners.open();
assert.equal(dialog.open, true);
for (const key of Object.keys(inputs)) assert.equal(inputs[key].value, group[key]);
assert.equal(amounts[0].value, '12.5000');
assert.equal(amounts[1].value, '');
assert.equal(amounts[2].value, '8.0000');
assert.equal(amounts[0].disabled, false);
assert.equal(terms.hidden, false);
assert.match(description.textContent, /Example.*\.com.*Global defaults.*EUR/);
listeners.cancel(); assert.equal(dialog.open, false);
button.dataset.priceEdit = JSON.stringify({...group, tld: '', prices: {'domain.restore': {0: '0.0000'}}});
listeners.open();
assert.equal(amounts[0].disabled, true);
assert.equal(amounts[0].value, '');
assert.equal(amounts[2].disabled, false);
assert.equal(amounts[2].value, '0.0000');
assert.equal(terms.hidden, true);
button.dataset.priceEdit = JSON.stringify(group); listeners.open();
assert.equal(amounts[0].disabled, false);
assert.equal(terms.hidden, false);
console.log('PASS: 20 grouped price editor assertions');
