(() => {
  const dialog = document.getElementById('price-editor');
  if (!dialog) return;
  document.querySelectorAll('[data-price-edit]').forEach(button => {
    button.addEventListener('click', () => {
      const price = JSON.parse(button.dataset.priceEdit);
      for (const key of ['registry_id', 'scope', 'command', 'tld', 'period', 'amount', 'currency']) {
        dialog.querySelector(`[name="${key}"]`).value = price[key];
      }
      dialog.querySelector('[data-price-description]').textContent = `${price.tld ? '.' + price.tld : 'Registry-wide'} · ${price.command} · ${Number(price.period) ? price.period + ' years' : 'Per operation'} · ${price.scope}`;
      dialog.showModal();
    });
  });
  dialog.querySelector('[data-price-close]').addEventListener('click', () => dialog.close());
})();
