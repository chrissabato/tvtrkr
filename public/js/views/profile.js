import { api, posterUrl } from '../api.js';

function formatDate(iso) {
  const d = new Date(iso + 'T00:00:00');
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

// Calendar-day difference, not a 24h-multiple — "tomorrow" should mean
// tomorrow's date, not "less than 48 hours from now".
function daysUntil(iso) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const target = new Date(iso + 'T00:00:00');
  return Math.round((target - today) / 86400000);
}

function daysAwayLabel(iso) {
  const days = daysUntil(iso);
  if (days <= 0) return 'Today';
  if (days === 1) return 'Tomorrow';
  return `In ${days} days`;
}

function showRow(show) {
  const next = show.next_episode;
  const upcoming = show.upcoming_episode;
  const airingSoon = !next && upcoming;
  const ep = next || upcoming;

  let progressLine;
  let badge;
  if (ep) {
    const dateLabel = next ? formatDate(ep.air_date) : `Airs ${formatDate(ep.air_date)}`;
    progressLine = `S${ep.season_number}E${ep.episode_number} · ${ep.name || ''} <span class="wtw-date">${dateLabel}</span>`;
    badge = airingSoon
      ? `<span class="wtw-badge wtw-badge-zero">${daysAwayLabel(upcoming.air_date)}</span>`
      : `<span class="wtw-badge">${show.available_count} available</span>`;
  } else {
    progressLine = 'All caught up';
    badge = '<span class="wtw-badge wtw-badge-zero">Caught up</span>';
  }

  return `
    <div class="watchlist-row" data-id="${show.tmdb_id}">
      ${show.poster_path ? `<img src="${posterUrl(show.poster_path, 'w92')}" alt="${show.name}" />` : ''}
      <div class="info">
        <div class="show-name">${show.name}</div>
        <div class="progress">${progressLine}</div>
      </div>
      <div class="wtw-actions">${badge}</div>
    </div>
  `;
}

export async function renderProfile(view, id) {
  view.innerHTML = '<p class="loading">Loading profile…</p>';

  const person = await api.getPersonProfile(id);
  const header = `<h2 class="section-title">${person.name || 'Unnamed user'}</h2>`;

  if (person.total === 0) {
    view.innerHTML = `${header}<p class="empty">Not following any shows yet.</p>`;
    return;
  }

  view.innerHTML = `${header}<div id="list">${person.shows.map(showRow).join('')}</div>`;

  view.querySelector('#list').addEventListener('click', (e) => {
    const row = e.target.closest('[data-id]');
    if (row) window.location.hash = `#/show/${row.dataset.id}`;
  });
}
