const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../panel/public/domains.js`, 'utf8');
function page(responses, pending = '1') {
    const timers = [], calls = [], events = {}, navigation = [];
    const state = {dataset: {pending, version: 'old'}, textContent: ''};
    const context = {
        URL,
        document: {getElementById: () => state, addEventListener: (type, fn) => { events[type] = fn; }},
        location: {href: 'https://panel.example/domains?batch=saved#renew', reload: () => navigation.push('reload')},
        setTimeout: fn => timers.push(fn),
        fetch: async (url, options) => {
            calls.push({url: String(url), options});
            const result = responses.shift();
            if (result instanceof Error) throw result;
            if (result && result.httpError) return {ok: false};
            if (result && result.redirect) return {ok: true, redirected: true};
            return {ok: true, redirected: false, json: async () => result};
        }
    };
    vm.runInNewContext(source, context);
    return {timers, calls, events, navigation, state, tick: async () => { await timers.shift()(); }};
}
(async () => {
    const complete = page([{version:'new',pending:false}]); await complete.tick();
    assert.deepEqual(complete.navigation,['reload']);
    assert.equal(complete.calls[0].url,'https://panel.example/domains?batch=saved&format=json');
    assert.equal(complete.calls[0].options.cache,'no-store');
    const dirty=page([{version:'new',pending:false}]);
    dirty.events.input({target:{closest:()=>true}}); await dirty.tick();
    assert.equal(dirty.navigation.length,0);
    assert.match(dirty.state.textContent,/Refresh after saving/);
    const changed=page([{version:'new',pending:true}]);
    changed.events.change({target:{closest:()=>true}}); await changed.tick();
    assert.equal(changed.navigation.length,0); assert.equal(changed.timers.length,1);
    const pending=page([{version:'old',pending:true}]); await pending.tick();
    assert.equal(pending.timers.length,1);
    const done=page([{version:'old',pending:false}]); await done.tick(); assert.equal(done.timers.length,0);
    for(const responses of [
        [new Error(),new Error(),new Error()],
        [{httpError:true},{httpError:true},{httpError:true}],
        [{redirect:true},{redirect:true},{redirect:true}],
        [{version:5},{version:5},{version:5}]
    ]) {
        const failed=page(responses); await failed.tick(); await failed.tick(); await failed.tick();
        assert.equal(failed.calls.length,3); assert.equal(failed.timers.length,0);
        assert.match(failed.state.textContent,/do not submit a fresh paid request/);
    }
    const retry=page([new Error(),{version:'old',pending:false}]); await retry.tick(); await retry.tick();
    assert.equal(retry.calls.length,2); assert.equal(retry.timers.length,0);
    const idle=page([],'0'); assert.equal(idle.timers.length,0);
    console.log('PASS: domain polling, retry limits, redirects and unsaved form protection');
})().catch(error=>{ console.error(error); process.exitCode=1; });
