import { api } from '../api.js';

function personRow(person) {
  const initial = (person.name || '?').charAt(0).toUpperCase();
  return `
    <div class="watchlist-row" data-id="${person.id}">
      <span class="avatar">${person.picture_url ? `<img src="${person.picture_url}" alt="" />` : initial}</span>
      <div class="info">
        <div class="show-name">${person.name || 'Unnamed user'}</div>
      </div>
    </div>
  `;
}

export async function renderPeople(view) {
  view.innerHTML = '<p class="loading">Loading people…</p>';

  const people = await api.listPeople();

  if (people.length === 0) {
    view.innerHTML = `
      <h2 class="section-title">People</h2>
      <p class="empty">No other users yet.</p>
    `;
    return;
  }

  view.innerHTML = `
    <h2 class="section-title">People</h2>
    <div id="people-list">${people.map(personRow).join('')}</div>
  `;

  view.querySelector('#people-list').addEventListener('click', (e) => {
    const row = e.target.closest('[data-id]');
    if (row) window.location.hash = `#/u/${row.dataset.id}`;
  });
}
