import { api, posterUrl } from '../api.js';

function formatDate(iso) {
  const d = new Date(iso + 'T00:00:00');
  return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
}

export async function renderCalendar(view) {
  view.innerHTML = '<p class="loading">Loading calendar…</p>';

  const upcoming = await api.getCalendar();

  if (upcoming.length === 0) {
    view.innerHTML = `
      <h2 class="section-title">Upcoming Episodes</h2>
      <p class="empty">No upcoming episodes. Add shows to My Shows to see their next air dates here.</p>
    `;
    return;
  }

  view.innerHTML = `
    <h2 class="section-title">Upcoming Episodes</h2>
    <div class="calendar-list">
      ${upcoming
        .map(
          (item) => `
        <div class="calendar-row" data-id="${item.show_id}">
          ${item.poster_path ? `<img src="${posterUrl(item.poster_path, 'w92')}" alt="${item.show_name}" />` : ''}
          <div>
            <div class="date">${formatDate(item.air_date)}</div>
            <div class="show-name">${item.show_name}</div>
            <div class="ep-info">S${item.season_number}E${item.episode_number} · ${item.episode_name || ''}</div>
          </div>
        </div>
      `
        )
        .join('')}
    </div>
  `;

  view.querySelectorAll('.calendar-row').forEach((row) => {
    row.addEventListener('click', () => {
      window.location.hash = `#/show/${row.dataset.id}`;
    });
  });
}
