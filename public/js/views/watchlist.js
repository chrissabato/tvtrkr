import { api, posterUrl } from '../api.js';

export async function renderWatchlist(view) {
  view.innerHTML = '<p class="loading">Loading your shows…</p>';

  const shows = await api.listLibrary();

  if (shows.length === 0) {
    view.innerHTML = `
      <h2 class="section-title">My Shows</h2>
      <p class="empty">You haven't added any shows yet. Find some in Discover.</p>
    `;
    return;
  }

  view.innerHTML = `
    <h2 class="section-title">My Shows</h2>
    <div id="list"></div>
  `;

  const list = view.querySelector('#list');

  shows.forEach((show) => {
    const row = document.createElement('div');
    row.className = 'watchlist-row';
    row.innerHTML = `
      ${show.poster_path ? `<img src="${posterUrl(show.poster_path, 'w92')}" alt="${show.name}" />` : ''}
      <div class="info" data-id="${show.tmdb_id}">
        <div class="show-name">${show.name}</div>
        <div class="progress">${show.watched_count} episode${show.watched_count === 1 ? '' : 's'} watched</div>
      </div>
      <button class="btn danger" data-remove="${show.tmdb_id}">Remove</button>
    `;
    list.appendChild(row);
  });

  list.addEventListener('click', async (e) => {
    const removeBtn = e.target.closest('[data-remove]');
    const info = e.target.closest('.info');

    if (removeBtn) {
      e.stopPropagation();
      if (confirm('Remove this show from My Shows? Watched history will be deleted too.')) {
        await api.removeFromLibrary(removeBtn.dataset.remove);
        renderWatchlist(view);
      }
      return;
    }

    if (info) {
      window.location.hash = `#/show/${info.dataset.id}`;
    }
  });
}
