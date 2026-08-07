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
  // Caught up on aired episodes, but something's airing soon — that's why
  // this show made the list at all (see the backend filter).
  const airingSoon = !next && upcoming;

  const ep = next || upcoming;
  const dateLabel = next ? formatDate(ep.air_date) : `Airs ${formatDate(ep.air_date)}`;

  return `
    <div class="watchlist-row wtw-row" data-id="${show.tmdb_id}">
      ${show.poster_path ? `<img src="${posterUrl(show.poster_path, 'w92')}" alt="${show.name}" />` : ''}
      <div class="info">
        <div class="show-name">${show.name}</div>
        <div class="progress">S${ep.season_number}E${ep.episode_number} · ${ep.name || ''} <span class="wtw-date">${dateLabel}</span></div>
      </div>
      <div class="wtw-actions">
        ${
          airingSoon
            ? `<span class="wtw-badge wtw-badge-zero">${daysAwayLabel(upcoming.air_date)}</span>`
            : `<span class="wtw-badge">${show.available_count} available</span>
               <input
                 type="checkbox"
                 class="watched-circle"
                 data-show="${show.tmdb_id}"
                 data-season="${next.season_number}"
                 data-episode="${next.episode_number}"
                 aria-label="Mark ${show.name} season ${next.season_number} episode ${next.episode_number} watched"
               />`
        }
      </div>
    </div>
  `;
}

export async function renderWhatToWatch(view) {
  view.innerHTML = '<p class="loading">Loading what to watch…</p>';

  const { total, shows } = await api.getWhatToWatch();

  if (total === 0) {
    view.innerHTML = `
      <h2 class="section-title">What to Watch</h2>
      <p class="empty">You haven't added any shows yet. Find some in Discover.</p>
    `;
    return;
  }

  if (shows.length === 0) {
    view.innerHTML = `
      <h2 class="section-title">What to Watch</h2>
      <p class="empty">You're all caught up. Nothing new to watch right now.</p>
    `;
    return;
  }

  view.innerHTML = `
    <h2 class="section-title">What to Watch</h2>
    <div id="list">${shows.map(showRow).join('')}</div>
  `;

  const list = view.querySelector('#list');

  list.addEventListener('click', (e) => {
    if (e.target.closest('.watched-circle')) return;
    const row = e.target.closest('.wtw-row');
    if (row) window.location.hash = `#/show/${row.dataset.id}`;
  });

  list.addEventListener('change', async (e) => {
    const checkbox = e.target.closest('.watched-circle');
    if (!checkbox) return;

    const showId = Number(checkbox.dataset.show);
    const season = Number(checkbox.dataset.season);
    const episode = Number(checkbox.dataset.episode);

    checkbox.disabled = true;
    try {
      await api.markWatched(showId, season, episode);
      // The show's next episode/available count may have changed; refresh.
      await renderWhatToWatch(view);
    } catch (err) {
      checkbox.disabled = false;
      alert(err.message || 'Failed to update watched status.');
    }
  });
}
