(() => {
  'use strict';
  const table = document.getElementById('customer-prices');
  if (!table) return;
  const search = document.getElementById('price-tld-search');
  const exportButton = document.getElementById('price-export');
  const rows = Array.from(table.querySelectorAll('[data-price-row]'));
  const matches = document.getElementById('price-no-matches');
  const count = document.getElementById('price-result-count');
  const filter = () => {
    const query = search.value.trim().toLowerCase().replace(/^\./, '');
    let visible = 0;
    rows.forEach(row => {
      row.hidden = !row.dataset.priceTld.toLowerCase().includes(query);
      if (!row.hidden) visible++;
    });
    matches.hidden = rows.length === 0 || visible !== 0;
    exportButton.disabled = visible === 0;
    count.textContent = `${visible} of ${rows.length} price rows`;
  };
  search.addEventListener('input', filter);
  exportButton.addEventListener('click', () => {
    // Quote every cell and neutralize spreadsheet formula prefixes, including after whitespace.
    const cell = value => '"' + (/^\s*[=+@-]|^[\t\r\n]/.test(value) ? "'" + value : value).replace(/"/g, '""') + '"';
    const records = [['TLD', 'Registry', 'Period', 'Registration', 'Renewal', 'Incoming transfer', 'Restore']];
    rows.filter(row => !row.hidden).forEach(row => {
      records.push([row.dataset.priceTld ? '.' + row.dataset.priceTld : 'Registry-wide defaults', row.dataset.priceRegistry,
        row.dataset.priceTld ? 'Yearly' : 'Per operation', ...Array.from(row.querySelectorAll('[data-price-value]'), td => td.dataset.priceValue)]);
    });
    const csv = '\uFEFF' + records.map(record => record.map(cell).join(',')).join('\r\n') + '\r\n';
    const url = URL.createObjectURL(new Blob([csv], {type: 'text/csv;charset=utf-8'}));
    const link = document.createElement('a');
    link.href = url; link.download = 'prices.csv';
    document.body.appendChild(link); link.click(); link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 0);
  });
  document.getElementById('customer-prices-tools').hidden = false;
  filter();
})();
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
