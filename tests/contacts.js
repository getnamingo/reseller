const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../panel/public/contacts.js`, 'utf8');
function page(responses, pending = '1') {
  const timers = [], calls = [], events = {}, navigation = [];
  const state = {dataset: {pending, version: 'old', list: '/contacts'}};
  const context = {
    document: {getElementById: () => state, addEventListener: (type, fn) => { events[type] = fn; }},
    location: {pathname: '/contacts/contact-id', reload: () => navigation.push('reload'), assign: path => navigation.push(path)},
    setTimeout: fn => timers.push(fn),
    fetch: async (url, options) => {
      calls.push({url, options}); const data = responses.shift();
      if (data instanceof Error) throw data;
      return {ok: true, json: async () => data};
    }
  };
  vm.runInNewContext(source, context);
  return {timers, calls, events, navigation, tick: async () => { await timers.shift()(); }};
}
(async () => {
  const completed = page([{version: 'new', pending: false}]); await completed.tick();
  assert.deepEqual(completed.navigation, ['reload']);
  assert.equal(completed.calls[0].url, '/contacts/contact-id?format=json');
  assert.equal(completed.calls[0].options.cache, 'no-store');
  assert.equal(completed.calls[0].options.method, undefined);
  const dirty = page([{version: 'new', pending: false}]); dirty.events.input(); await dirty.tick();
  assert.equal(dirty.navigation.length, 0); assert.equal(dirty.timers.length, 0);
  const deleted = page([{deleted: true}]); await deleted.tick(); assert.deepEqual(deleted.navigation, ['/contacts']);
  const pending = page([{version: 'old', pending: true}]); await pending.tick(); assert.equal(pending.timers.length, 1);
  const failed = page([new Error(), new Error(), new Error()]);
  await failed.tick(); await failed.tick(); await failed.tick();
  assert.equal(failed.calls.length, 3); assert.equal(failed.timers.length, 0);
  assert.equal(page([], '0').timers.length, 0);
  console.log('PASS: 11 contact polling assertions');
})().catch(error => { console.error(error); process.exitCode = 1; });
