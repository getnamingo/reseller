// Exercise the actual polling script with a minimal DOM and controlled timers/fetch.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../panel/public/checks.js`, 'utf8');

function page(responses, pending = true) {
  const timers = new Map();
  const calls = [];
  const message = { textContent: '' };
  const cells = Array.from({ length: 4 }, () => ({ textContent: '' }));
  let next = 0;
  const card = {
    dataset: { pending: String(pending), pollUrl: '/checks?batch=test&format=json' },
    querySelector: () => message, querySelectorAll: () => [{ cells }]
  };
  const document = { hidden: false, getElementById: () => card };
  vm.runInNewContext(source, {
    document, AbortController,
    setTimeout: (fn, delay) => { timers.set(++next, { fn, delay }); return next; },
    clearTimeout: id => timers.delete(id),
    fetch: async (url, options) => {
      calls.push({ url, options });
      const data = responses.shift();
      if (data instanceof Error) throw data;
      return { ok: true, headers: { get: () => 'application/json' }, json: async () => data };
    }
  });
  return {
    timers, calls, message, cells, document,
    async tick() {
      const [id, timer] = [...timers].sort((a, b) => a[1].delay - b[1].delay)[0];
      timers.delete(id);
      await timer.fn();
    }
  };
}

(async () => {
  const p = page([
    { rows: [{ status: 'Processing', reason: '' }], pending: true },
    { rows: [{ status: 'Available', reason: '<script>untrusted</script>' }], pending: false }
  ]);
  await p.tick();
  assert.equal(p.cells[2].textContent, 'Checking…');
  assert.equal(p.timers.size, 1);
  await p.tick();
  assert.equal(p.cells[2].textContent, 'Available');
  assert.equal(p.cells[3].textContent, '<script>untrusted</script>');
  assert.equal(p.message.textContent, 'Checks complete.');
  assert.equal(p.timers.size, 0);
  assert.equal(p.calls.length, 2);
  assert.equal(p.calls[0].url, '/checks?batch=test&format=json');
  assert.equal(p.calls[0].options.cache, 'no-store');
  assert.equal(p.calls[0].options.method, undefined); // GET, never POST.
  assert.equal(page([], false).timers.size, 0);

  const hidden = page([]);
  hidden.document.hidden = true;
  await hidden.tick();
  assert.equal(hidden.calls.length, 0);
  assert.equal(hidden.timers.size, 1);

  const failed = page([new Error(), new Error(), new Error()]);
  for (let i = 0; i < 3; i++) await failed.tick();
  assert.equal(failed.calls.length, 3);
  assert.equal(failed.timers.size, 0);
  assert.match(failed.message.textContent, /Automatic updates paused/);
  console.log('PASS: 16 availability polling assertions');
})().catch(error => { console.error(error); process.exitCode = 1; });
