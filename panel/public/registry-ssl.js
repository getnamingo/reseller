(() => {
    'use strict';
    const form = document.getElementById('update-registry-form');
    if (!form) return;
    const generator = form.querySelector('[data-registry-ssl-generator]');
    if (!generator) return;
    const button = generator.querySelector('button');
    const status = form.querySelector('[data-registry-ssl-status]');
    const save = form.querySelector('button[type="submit"]');
    const names = ['ca_pem', 'client_cert_pem', 'client_key_pem', 'client_key_passphrase'];
    const field = name => form.querySelector(`[name="${name}"]`);
    const debug = () => form.querySelector('[name="registry_debug"]:checked')?.value === '1';
    const eligible = () => debug() && names.slice(0, 3).every(name => field(name).value.trim() === '');
    const snapshot = () => JSON.stringify([debug(), ...names.map(name => field(name).value)]);
    let busy = false;
    const update = () => { generator.hidden = !eligible(); };
    const changed = () => { if (!busy) status.textContent = ''; update(); };
    form.addEventListener('input', changed);
    form.addEventListener('change', changed);
    form.addEventListener('submit', event => { if (busy) event.preventDefault(); });
    button.addEventListener('click', async () => {
        if (busy || !eligible()) return;
        const before = snapshot(), original = button.innerHTML, saveDisabled = save.disabled;
        busy = true; button.disabled = true; save.disabled = true;
        button.textContent = 'Generating…'; status.textContent = ''; status.className = 'small mt-2';
        const body = new URLSearchParams();
        body.set('action', 'registry.generate_ssl');
        body.set('csrf', field('csrf').value);
        body.set('registry_debug', '1');
        names.forEach(name => body.set(name, field(name).value));
        try {
            const response = await fetch(form.action, {method: 'POST', body, cache: 'no-store', credentials: 'same-origin', headers: {Accept: 'application/json'}});
            if (!response.ok || response.redirected) throw new Error('Unable to generate SSL. Reload the page and try again.');
            const result = await response.json();
            if (typeof result.client_cert_pem !== 'string' || typeof result.client_key_pem !== 'string'
                || !result.client_cert_pem.includes('BEGIN CERTIFICATE') || !result.client_key_pem.includes('PRIVATE KEY')) {
                throw new Error('The server returned an invalid SSL pair. Try again.');
            }
            if (snapshot() !== before) throw new Error('SSL settings changed while generating. Your edits were kept; generate again if needed.');
            field('client_cert_pem').value = result.client_cert_pem;
            field('client_key_pem').value = result.client_key_pem;
            status.className = 'small mt-2 text-success';
            status.textContent = 'SSL generated. Click Save registry to store the certificate and key.';
        } catch (error) {
            status.className = 'small mt-2 text-danger';
            status.textContent = error.message || 'SSL generation failed. Try again.';
        } finally {
            busy = false; button.disabled = false; save.disabled = saveDisabled; button.innerHTML = original; update();
        }
    });
    update();
})();
