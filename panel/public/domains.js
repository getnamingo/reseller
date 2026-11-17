(() => {
    'use strict';
    const status = document.getElementById('domain-progress');
    if (!status || status.dataset.pending !== '1') return;
    let dirty = false, failures = 0, version = status.dataset.version;
    document.addEventListener('input', event => {
        if (event.target.closest('form')) dirty = true;
    });
    document.addEventListener('change', event => {
        if (event.target.closest('form')) dirty = true;
    });
    const poll = async () => {
        let pending = true;
        try {
            const url = new URL(location.href);
            url.hash = '';
            url.searchParams.set('format', 'json');
            const response = await fetch(url, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok || response.redirected) throw new Error('Unable to refresh');
            const result = await response.json();
            if (typeof result.version !== 'string' || typeof result.pending !== 'boolean') throw new Error('Invalid response');
            failures = 0;
            pending = result.pending;
            if (result.version !== version) {
                if (!dirty) { location.reload(); return; }
                status.textContent = 'New operation results are available. Refresh after saving your form.';
            } else if (!pending) {
                status.textContent = 'Domain operations updated.';
            }
        } catch (_) {
            failures++;
            status.textContent = 'Unable to refresh operation results. Retrying…';
            if (failures >= 3) {
                status.textContent = 'Automatic refresh stopped. Reload to resume the saved operations; do not submit a fresh paid request.';
                return;
            }
        }
        if (pending) setTimeout(poll, 2000);
    };
    setTimeout(poll, 1500);
})();
