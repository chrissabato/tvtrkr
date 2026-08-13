import { api } from '../api.js';

export async function renderUsers(view, currentUser) {
  view.innerHTML = '<p class="loading">Loading users…</p>';

  const users = await api.listAllowedUsers();

  view.innerHTML = `
    <h2 class="section-title">Users</h2>
    <form class="search-row" id="add-user-form">
      <input type="email" id="add-user-email" placeholder="name@example.com" autocomplete="off" required />
      <button type="submit">Add</button>
    </form>
    <div id="user-list"></div>
  `;

  const list = view.querySelector('#user-list');

  function renderList() {
    list.innerHTML = users
      .map((u) => {
        const isSelf = u.email === currentUser.email;
        const statusLabel = u.has_signed_in ? (u.name || u.email) : `${u.email} (invited, not signed in yet)`;
        const initial = (u.name || u.email || '?').charAt(0).toUpperCase();
        return `
          <div class="watchlist-row">
            <span class="avatar">${u.picture_url ? `<img src="${u.picture_url}" alt="" />` : initial}</span>
            <div class="info">
              <div class="show-name">${statusLabel}${u.is_admin ? ' · admin' : ''}</div>
              <div class="progress">${u.email}</div>
            </div>
            ${isSelf ? '' : `<button class="btn danger" data-remove="${encodeURIComponent(u.email)}">Remove</button>`}
          </div>
        `;
      })
      .join('');
  }

  renderList();

  const form = view.querySelector('#add-user-form');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = view.querySelector('#add-user-email');
    const email = input.value.trim();
    if (!email) return;

    await api.addAllowedUser(email);
    input.value = '';
    users.push({ email: email.toLowerCase(), added_at: '', name: null, picture_url: null, is_admin: false, has_signed_in: false });
    renderList();
  });

  list.addEventListener('click', async (e) => {
    const removeBtn = e.target.closest('[data-remove]');
    if (!removeBtn) return;

    const email = decodeURIComponent(removeBtn.dataset.remove);
    if (!confirm(`Remove ${email}? They will lose access immediately.`)) return;

    await api.removeAllowedUser(email);
    const idx = users.findIndex((u) => u.email === email);
    if (idx !== -1) users.splice(idx, 1);
    renderList();
  });
}
