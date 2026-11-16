(() => {
  const dialog = document.getElementById('price-editor');
  if (!dialog) return;
  document.querySelectorAll('[data-price-edit]').forEach(button => {
    button.addEventListener('click', () => {
      let group;
      try { group = JSON.parse(button.dataset.priceEdit); } catch { return; }
      dialog.querySelector('form').reset();
      for (const key of ['registry_id', 'scope', 'tld', 'currency', 'version']) {
        dialog.querySelector(`[name="${key}"]`).value = group[key];
      }
      dialog.querySelectorAll('[data-price-amount]').forEach(input => {
        input.value = group.prices?.[input.dataset.priceCommand]?.[input.dataset.pricePeriod] ?? '';
        input.disabled = group.tld === '' && input.dataset.priceCommand !== 'domain.restore';
      });
      dialog.querySelector('[data-price-term-fields]').hidden = group.tld === '';
      dialog.querySelector('[data-price-description]').textContent = `${group.registry_name} · ${group.tld ? '.' + group.tld : 'Registry-wide restore'} · ${group.scope_name} · ${group.currency}`;
      dialog.showModal();
    });
  });
  dialog.querySelector('[data-price-close]').addEventListener('click', () => dialog.close());
})();
