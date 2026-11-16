(() => {
  const modal = document.getElementById('admin-confirmation');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', event => {
    const form = modal.querySelector('form');
    form.reset();
    for (const key of ['action', 'id', 'resolution']) form.querySelector(`[name="${key}"]`).value = '';
    let config;
    try {
      config = JSON.parse(event.relatedTarget.dataset.adminAction);
      if (!config || typeof config.action !== 'string' || typeof config.id !== 'string') throw new Error('Invalid action');
    } catch {
      event.preventDefault();
      return;
    }
    for (const key of ['action', 'id', 'resolution']) form.querySelector(`[name="${key}"]`).value = config[key] || '';
    modal.querySelector('#admin-confirmation-title').textContent = config.title;
    modal.querySelector('[data-action-summary]').textContent = config.summary;
    modal.querySelector('[data-action-confirmation]').textContent = config.confirmation;
    modal.querySelector('[data-action-submit]').textContent = config.title;
  });
})();
