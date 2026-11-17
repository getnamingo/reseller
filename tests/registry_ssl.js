const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../panel/public/registry-ssl.js`, 'utf8');
const pair = {client_cert_pem: '-----BEGIN CERTIFICATE-----\nCERT\n-----END CERTIFICATE-----', client_key_pem: '-----BEGIN PRIVATE KEY-----\nKEY\n-----END PRIVATE KEY-----'};
let checks = 0;
function check(ok, message) { checks++; assert.ok(ok, message); }
function page({debug = '0', response = pair, action = null} = {}) {
    const events = {}, clicks = {}, calls = [];
    const fields = Object.fromEntries(['ca_pem', 'client_cert_pem', 'client_key_pem', 'client_key_passphrase', 'csrf'].map(name => [name, {value: name === 'csrf' ? 'csrf-token' : ''}]));
    const button = {disabled: false, innerHTML: 'Generate SSL', addEventListener: (type, fn) => { clicks[type] = fn; }};
    const save = {disabled: false};
    const generator = {hidden: true, querySelector: () => button};
    const status = {textContent: '', className: ''};
    const radio = {value: debug};
    const form = {
        // Named form controls shadow native form properties in browsers.
        action: {value: 'registry.save', toString: () => '[object HTMLInputElement]'},
        getAttribute: name => name === 'action' ? action : null,
        querySelector(selector) {
            if (selector === '[data-registry-ssl-generator]') return generator;
            if (selector === '[data-registry-ssl-status]') return status;
            if (selector === 'button[type="submit"]') return save;
            if (selector === '[name="registry_debug"]:checked') return radio;
            return fields[selector.match(/^\[name="(.+)"\]$/)[1]];
        },
        addEventListener: (type, fn) => { events[type] = fn; }
    };
    const context = {URLSearchParams, location: {href: 'https://panel.example/admin/registries/registry-id'}, document: {getElementById: () => form}, fetch: async (url, options) => {
        calls.push({url, options});
        const data = typeof response === 'function' ? await response() : response;
        if (data instanceof Error) throw data;
        return {ok: true, redirected: false, json: async () => data};
    }};
    vm.runInNewContext(source, context);
    return {fields, radio, generator, button, save, status, events, calls, click: () => clicks.click()};
}
(async () => {
    const p = page(); check(p.generator.hidden, 'Hidden with debug off');
    p.radio.value = '1'; p.events.change(); check(!p.generator.hidden, 'Visible after enabling debug on empty SSL fields');
    for (const field of ['ca_pem', 'client_cert_pem', 'client_key_pem']) {
        p.fields[field].value = 'existing'; p.events.input(); check(p.generator.hidden, `${field} blocks generation`);
        p.fields[field].value = ''; p.events.input();
    }
    await p.click();
    check(p.calls.length === 1 && p.calls[0].options.method === 'POST', 'Generation uses one POST');
    check(p.calls[0].options.body.get('action') === 'registry.generate_ssl' && p.calls[0].options.body.get('csrf') === 'csrf-token', 'Dedicated generation action with CSRF');
    check(p.calls[0].url === 'https://panel.example/admin/registries/registry-id', 'Posts to current registry URL despite hidden action input');
    const explicitAction = page({debug: '1', action: '/admin/registries/other-registry'}); await explicitAction.click();
    check(explicitAction.calls[0].url === '/admin/registries/other-registry', 'Explicit form action attribute respected despite hidden action input');
    check(p.calls[0].options.cache === 'no-store' && p.calls[0].options.credentials === 'same-origin', 'Authenticated noncached request');
    check(p.fields.client_cert_pem.value === pair.client_cert_pem && p.fields.client_key_pem.value === pair.client_key_pem, 'Both PEM fields filled');
    check(p.fields.ca_pem.value === '' && p.generator.hidden, 'CA unchanged and generator hidden after fill');
    check(p.status.textContent.includes('Save registry') && !p.save.disabled, 'Explicit save still required');
    await p.click(); check(p.calls.length === 1, 'Existing pair is never overwritten');
    let complete;
    const pending = page({debug: '1', response: () => new Promise(resolve => { complete = resolve; })});
    const task = pending.click();
    check(pending.button.disabled && pending.save.disabled, 'Busy generation prevents duplicate clicks and premature save');
    let prevented = false; pending.events.submit({preventDefault() { prevented = true; }}); check(prevented, 'Implicit form submission blocked while busy');
    pending.fields.client_cert_pem.value = 'unsaved manual certificate'; pending.events.input();
    complete(pair); await task;
    check(pending.fields.client_cert_pem.value === 'unsaved manual certificate' && pending.fields.client_key_pem.value === '', 'Concurrent manual SSL edits preserved');
    check(pending.status.textContent.includes('Your edits were kept') && !pending.save.disabled, 'Stale generation reports discard and reenables save');
    let completePassphrase;
    const changedPassphrase = page({debug: '1', response: () => new Promise(resolve => { completePassphrase = resolve; })});
    const passTask = changedPassphrase.click(); changedPassphrase.fields.client_key_passphrase.value = 'changed'; completePassphrase(pair); await passTask;
    check(changedPassphrase.fields.client_key_pem.value === '', 'Passphrase changes discard mismatched generated key');
    const failed = page({debug: '1', response: new Error('Unavailable')}); await failed.click();
    check(failed.fields.client_cert_pem.value === '' && !failed.generator.hidden && !failed.button.disabled, 'Failure leaves fields empty and allows retry');
    const malformed = page({debug: '1', response: {client_cert_pem: 'bad'}}); await malformed.click();
    check(malformed.fields.client_key_pem.value === '' && malformed.status.className.includes('text-danger'), 'Invalid response never populates SSL');
    vm.runInNewContext(source, {document: {getElementById: () => null}}); check(true, 'No effect on add-registry or unrelated pages');
    console.log(`PASS: ${checks} registry SSL UI assertions`);
})().catch(error => { console.error(error); process.exitCode = 1; });
