(() => {
  const state = document.getElementById('contact-state');
  if (!state || state.dataset.pending !== '1') return;
  let dirty = false, failures = 0;
  document.addEventListener('input', () => { dirty = true; });
  document.addEventListener('change', () => { dirty = true; });
  async function poll() {
    try {
      const response = await fetch(`${location.pathname}?format=json`, {cache: 'no-store', headers: {Accept: 'application/json'}});
      if (!response.ok) throw new Error('Unavailable');
      const data = await response.json();
      failures = 0;
      if (dirty) return; // Never discard unsaved contact changes.
      if (data.deleted) { location.assign(state.dataset.list); return; }
      if (data.version !== state.dataset.version) { location.reload(); return; }
      if (!data.pending) return;
    } catch (_) { if (++failures >= 3) return; }
    setTimeout(poll, 2000);
  }
  setTimeout(poll, 2000);
})();
