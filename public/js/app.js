import { api } from './api.js';
import { renderLogin } from './views/login.js';
import { renderDiscover } from './views/discover.js';
import { renderShowDetail } from './views/show-detail.js';
import { renderCalendar } from './views/calendar.js';
import { renderWatchlist } from './views/watchlist.js';
import { renderUsers } from './views/users.js';

const view = document.getElementById('view');
const tabsNav = document.getElementById('tabs');
const usersTab = document.getElementById('users-tab');
const userChip = document.getElementById('user-chip');
const tabLinks = document.querySelectorAll('.tabs a');

let currentUser = null;

function setActiveTab(routeName) {
  tabLinks.forEach((link) => {
    link.classList.toggle('active', link.dataset.route === routeName);
  });
}

function renderUserChip() {
  userChip.hidden = false;
  userChip.innerHTML = `
    ${currentUser.picture_url ? `<img src="${currentUser.picture_url}" alt="" />` : ''}
    <span>${currentUser.name || currentUser.email}</span>
    <button id="logout-btn" class="btn" type="button">Sign out</button>
  `;
  userChip.querySelector('#logout-btn').addEventListener('click', async () => {
    await api.logout().catch(() => {});
    window.location.reload();
  });
}

async function renderRoute() {
  const hash = window.location.hash.replace(/^#\/?/, '');
  const [routeName, param] = hash.split('/');

  view.innerHTML = '<p class="loading">Loading…</p>';

  try {
    switch (routeName) {
      case '':
      case 'discover':
        setActiveTab('discover');
        await renderDiscover(view);
        break;
      case 'calendar':
        setActiveTab('calendar');
        await renderCalendar(view);
        break;
      case 'watchlist':
        setActiveTab('watchlist');
        await renderWatchlist(view);
        break;
      case 'users':
        setActiveTab('users');
        if (!currentUser.is_admin) {
          view.innerHTML = '<p class="error">Admins only.</p>';
          break;
        }
        await renderUsers(view, currentUser);
        break;
      case 'show':
        setActiveTab(null);
        await renderShowDetail(view, param);
        break;
      default:
        view.innerHTML = '<p class="error">Page not found.</p>';
    }
  } catch (err) {
    console.error(err);
    if (err.status === 401) {
      // Session expired mid-use; re-run bootstrap to show the login screen.
      window.location.reload();
      return;
    }
    view.innerHTML = `<p class="error">${err.message || 'Something went wrong.'}</p>`;
  }
}

async function init() {
  try {
    currentUser = await api.getMe();
  } catch (err) {
    tabsNav.hidden = true;
    userChip.hidden = true;
    renderLogin(view);
    return;
  }

  tabsNav.hidden = false;
  usersTab.hidden = !currentUser.is_admin;
  renderUserChip();

  window.addEventListener('hashchange', renderRoute);
  renderRoute();
}

if (document.readyState === 'loading') {
  window.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
