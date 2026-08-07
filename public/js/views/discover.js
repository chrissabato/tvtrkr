import { api, posterUrl } from '../api.js';

function showCard(show) {
  const title = show.name || 'Untitled';
  const year = (show.first_air_date || '').slice(0, 4);
  const poster = posterUrl(show.poster_path);

  return `
    <button class="card" data-id="${show.id}">
      ${poster ? `<img src="${poster}" alt="${title}" loading="lazy" />` : '<div class="card-body"><div class="card-title">' + title + '</div></div>'}
      ${poster ? `<div class="card-body"><div class="card-title">${title}</div><div class="card-sub">${year || ''}</div></div>` : ''}
    </button>
  `;
}

function wireCards(container) {
  container.querySelectorAll('.card').forEach((card) => {
    card.addEventListener('click', () => {
      window.location.hash = `#/show/${card.dataset.id}`;
    });
  });
}

export async function renderDiscover(view) {
  view.innerHTML = `
    <form class="search-row" id="search-form">
      <input type="search" id="search-input" placeholder="Search TV shows…" autocomplete="off" />
      <button type="submit">Search</button>
    </form>
    <div id="results"></div>
  `;

  const results = view.querySelector('#results');
  const form = view.querySelector('#search-form');
  const input = view.querySelector('#search-input');

  async function loadPopular() {
    results.innerHTML = '<p class="loading">Loading popular shows…</p>';
    const data = await api.discoverShows();
    renderGrid('Popular', data.results || []);
  }

  async function runSearch(query) {
    results.innerHTML = '<p class="loading">Searching…</p>';
    const data = await api.searchShows(query);
    renderGrid(`Results for "${query}"`, data.results || []);
  }

  function renderGrid(title, shows) {
    if (shows.length === 0) {
      results.innerHTML = `<h2 class="section-title">${title}</h2><p class="empty">No shows found.</p>`;
      return;
    }
    results.innerHTML = `
      <h2 class="section-title">${title}</h2>
      <div class="grid">${shows.map(showCard).join('')}</div>
    `;
    wireCards(results);
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const query = input.value.trim();
    if (query) {
      runSearch(query);
    } else {
      loadPopular();
    }
  });

  await loadPopular();
}
