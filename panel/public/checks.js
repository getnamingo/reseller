(() => {
  const card = document.getElementById('availability-results');
  if (!card || card.dataset.pending !== 'true') return;
  const message = card.querySelector('[data-poll-message]');
  const cells = [...card.querySelectorAll('tbody tr')].map(row => row.cells);
  let failures = 0;

  async function refresh() {
    if (document.hidden) { setTimeout(refresh, 2000); return; }
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(card.dataset.pollUrl, {
        headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal
      });
      if (!response.ok || !response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Refresh failed');
      const data = await response.json();
      if (!Array.isArray(data.rows) || data.rows.length !== cells.length || typeof data.pending !== 'boolean') throw new Error('Invalid result');
      data.rows.forEach((row, index) => {
        cells[index][2].textContent = ['Queued', 'Processing', 'Pending'].includes(row.status) ? 'Checking…' : row.status;
        cells[index][3].textContent = row.reason;
      });
      failures = 0;
      message.textContent = data.pending ? 'Checking… Results update automatically.' : 'Checks complete.';
      if (data.pending) setTimeout(refresh, 2000);
    } catch {
      if (++failures < 3) setTimeout(refresh, 3000);
      else message.textContent = 'Automatic updates paused. Use Refresh results to try again, or sign in again if your session expired.';
    } finally {
      clearTimeout(timeout);
    }
  }
  setTimeout(refresh, 1000);
})();
