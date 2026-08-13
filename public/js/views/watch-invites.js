import { api, posterUrl } from '../api.js';

function inviteRow(invite) {
  const initial = (invite.inviter_name || '?').charAt(0).toUpperCase();
  return `
    <div class="watchlist-row" data-group-id="${invite.group_id}">
      ${
        invite.poster_path
          ? `<img src="${posterUrl(invite.poster_path, 'w92')}" alt="${invite.show_name || ''}" />`
          : ''
      }
      <div class="info">
        <div class="show-name">${invite.show_name || 'Unknown show'}</div>
        <div class="progress">
          <span class="avatar">${
            invite.inviter_picture_url ? `<img src="${invite.inviter_picture_url}" alt="" />` : initial
          }</span>
          ${invite.inviter_name || 'Someone'} wants to watch this with you
        </div>
      </div>
      <div class="wtw-actions">
        <button class="btn" data-accept="${invite.group_id}">Accept</button>
        <button class="btn btn-text" data-decline="${invite.group_id}">Decline</button>
      </div>
    </div>
  `;
}

export async function renderWatchInvites(view, onChange) {
  view.innerHTML = '<p class="loading">Loading invites…</p>';

  const invites = await api.listWatchInvites();

  if (invites.length === 0) {
    view.innerHTML = `
      <h2 class="section-title">Invites</h2>
      <p class="empty">No pending watch-with invites.</p>
    `;
    return;
  }

  view.innerHTML = `
    <h2 class="section-title">Invites</h2>
    <div id="invites-list">${invites.map(inviteRow).join('')}</div>
  `;

  view.querySelector('#invites-list').addEventListener('click', async (e) => {
    const acceptBtn = e.target.closest('[data-accept]');
    const declineBtn = e.target.closest('[data-decline]');
    if (!acceptBtn && !declineBtn) return;

    const btn = acceptBtn || declineBtn;
    const row = btn.closest('[data-group-id]');
    btn.disabled = true;

    if (acceptBtn) {
      await api.acceptWatchInvite(acceptBtn.dataset.accept);
    } else {
      await api.declineWatchInvite(declineBtn.dataset.decline);
    }
    row.remove();
    onChange?.();

    if (!view.querySelector('#invites-list').children.length) {
      view.innerHTML = `
        <h2 class="section-title">Invites</h2>
        <p class="empty">No pending watch-with invites.</p>
      `;
    }
  });
}
