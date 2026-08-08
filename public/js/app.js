import { api } from './api.js';
import { renderLogin } from './views/login.js';
import { renderDiscover } from './views/discover.js';
import { renderShowDetail } from './views/show-detail.js';
import { renderCalendar } from './views/calendar.js';
import { renderWatchlist } from './views/watchlist.js';
import { renderUsers } from './views/users.js';
import { renderWhatToWatch } from './views/whattowatch.js';
import { renderPeople } from './views/people.js';
import { renderProfile } from './views/profile.js';

const view = document.getElementById('view');
const tabsNav = document.getElementById('tabs');
const userMenu = document.getElementById('user-menu');
const userMenuTrigger = document.getElementById('user-menu-trigger');
const userMenuDropdown = document.getElementById('user-menu-dropdown');
const userAvatar = document.getElementById('user-avatar');
const userMenuName = document.getElementById('user-menu-name');
const usersLink = document.getElementById('users-link');
const logoutBtn = document.getElementById('logout-btn');

let currentUser = null;

function setActiveTab(routeName) {
  document.querySelectorAll('[data-route]').forEach((link) => {
    link.classList.toggle('active', link.dataset.route === routeName);
  });
}

function closeUserMenu() {
  userMenuDropdown.hidden = true;
  userMenuTrigger.setAttribute('aria-expanded', 'false');
}

function toggleUserMenu() {
  const isOpen = !userMenuDropdown.hidden;
  userMenuDropdown.hidden = isOpen;
  userMenuTrigger.setAttribute('aria-expanded', String(!isOpen));
}

function renderUserMenu() {
  userMenu.hidden = false;
  const label = currentUser.name || currentUser.email;
  userAvatar.innerHTML = currentUser.picture_url
    ? `<img src="${currentUser.picture_url}" alt="" />`
    : label.charAt(0).toUpperCase();
  userMenuName.textContent = label;
  usersLink.hidden = !currentUser.is_admin;

  userMenuTrigger.addEventListener('click', (e) => {
    e.stopPropagation();
    toggleUserMenu();
  });
  usersLink.addEventListener('click', closeUserMenu);
  document.addEventListener('click', (e) => {
    if (!userMenu.contains(e.target)) closeUserMenu();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeUserMenu();
  });
  logoutBtn.addEventListener('click', async () => {
    await api.logout().catch(() => {});
    window.location.reload();
  });
}

async function renderRoute() {
  const hash = window.location.hash.replace(/^#\/?/, '');
  const [routeName, param] = hash.split('/');

  closeUserMenu();
  view.innerHTML = '<p class="loading">Loading…</p>';

  try {
    switch (routeName) {
      case '':
      case 'whattowatch':
        setActiveTab('whattowatch');
        await renderWhatToWatch(view);
        break;
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
      case 'people':
        setActiveTab('people');
        await renderPeople(view);
        break;
      case 'u':
        setActiveTab(null);
        await renderProfile(view, param);
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
    userMenu.hidden = true;
    renderLogin(view);
    return;
  }

  tabsNav.hidden = false;
  renderUserMenu();

  window.addEventListener('hashchange', renderRoute);
  renderRoute();
}

if (document.readyState === 'loading') {
  window.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
