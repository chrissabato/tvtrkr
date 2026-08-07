import { api, posterUrl } from '../api.js';

const PAGE_SIZE = 20;

function formatDate(iso) {
  const d = new Date(iso + 'T00:00:00');
  return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
}

function cursorOf(item) {
  return {
    air_date: item.air_date,
    show_id: item.show_id,
    season_number: item.season_number,
    episode_number: item.episode_number,
  };
}

function episodeRow(item) {
  return `
    <div class="calendar-row ${item.watched ? 'watched' : ''}" data-id="${item.show_id}">
      ${item.poster_path ? `<img src="${posterUrl(item.poster_path, 'w92')}" alt="${item.show_name}" />` : ''}
      <div class="calendar-row-info">
        <div class="date">${formatDate(item.air_date)}</div>
        <div class="show-name">${item.show_name}</div>
        <div class="ep-info">S${item.season_number}E${item.episode_number} · ${item.episode_name || ''}</div>
      </div>
      ${
        item.aired
          ? `<input
              type="checkbox"
              class="watched-circle"
              data-show="${item.show_id}"
              data-season="${item.season_number}"
              data-episode="${item.episode_number}"
              ${item.watched ? 'checked' : ''}
              aria-label="Mark ${item.show_name} season ${item.season_number} episode ${item.episode_number} watched"
            />`
          : ''
      }
    </div>
  `;
}

export async function renderCalendar(view) {
  view.innerHTML = '<p class="loading">Loading calendar…</p>';

  const { recent, upcoming } = await api.getCalendar();

  if (recent.length === 0 && upcoming.length === 0) {
    view.innerHTML = `
      <h2 class="section-title">Calendar</h2>
      <p class="empty">No episodes to show yet. Add shows to My Shows to see recent and upcoming air dates here.</p>
    `;
    return;
  }

  view.innerHTML = `
    <h2 class="section-title">Calendar</h2>
    <div class="calendar-list" id="calendar-list">
      <div class="calendar-sentinel" data-sentinel="top"></div>
      ${recent.map(episodeRow).join('')}
      <div class="calendar-today-marker" data-today-marker><span>Today</span></div>
      ${upcoming.map(episodeRow).join('')}
      <div class="calendar-sentinel" data-sentinel="bottom"></div>
    </div>
  `;

  const listEl = view.querySelector('#calendar-list');
  const topSentinel = listEl.querySelector('[data-sentinel="top"]');
  const bottomSentinel = listEl.querySelector('[data-sentinel="bottom"]');
  const todayMarker = listEl.querySelector('[data-today-marker]');

  // Only a full page implies there might be more in that direction — a
  // short/empty initial page means we've already hit the edge of the data.
  let oldestCursor = recent.length === PAGE_SIZE ? cursorOf(recent[0]) : null;
  let newestCursor = upcoming.length === PAGE_SIZE ? cursorOf(upcoming[upcoming.length - 1]) : null;
  let loadingBefore = false;
  let loadingAfter = false;

  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        if (entry.target === topSentinel) loadMore('before');
        if (entry.target === bottomSentinel) loadMore('after');
      }
    },
    { rootMargin: '600px 0px' }
  );

  async function loadMore(dir) {
    const isBefore = dir === 'before';
    if (isBefore ? loadingBefore || !oldestCursor : loadingAfter || !newestCursor) {
      return;
    }
    if (isBefore) loadingBefore = true;
    else loadingAfter = true;

    let episodes = [];
    try {
      const res = await api.getCalendarPage(dir, isBefore ? oldestCursor : newestCursor, PAGE_SIZE);
      episodes = res.episodes;
    } catch (err) {
      // Leave the cursor as-is so a later scroll can retry.
    }

    if (episodes.length === 0) {
      if (isBefore) {
        oldestCursor = null;
        observer.unobserve(topSentinel);
      } else {
        newestCursor = null;
        observer.unobserve(bottomSentinel);
      }
    } else {
      const html = episodes.map(episodeRow).join('');
      if (isBefore) {
        // Prepending shifts everything below it down; hold the viewport
        // steady by scrolling by exactly the height we just added.
        const previousHeight = document.documentElement.scrollHeight;
        topSentinel.insertAdjacentHTML('afterend', html);
        window.scrollBy(0, document.documentElement.scrollHeight - previousHeight);
        oldestCursor = cursorOf(episodes[0]);
      } else {
        bottomSentinel.insertAdjacentHTML('beforebegin', html);
        newestCursor = cursorOf(episodes[episodes.length - 1]);
      }

      if (episodes.length < PAGE_SIZE) {
        if (isBefore) {
          oldestCursor = null;
          observer.unobserve(topSentinel);
        } else {
          newestCursor = null;
          observer.unobserve(bottomSentinel);
        }
      }
    }

    if (isBefore) loadingBefore = false;
    else loadingAfter = false;
  }

  if (oldestCursor) observer.observe(topSentinel);
  if (newestCursor) observer.observe(bottomSentinel);

  listEl.addEventListener('click', (e) => {
    if (e.target.closest('.watched-circle')) return;
    const row = e.target.closest('.calendar-row');
    if (row) window.location.hash = `#/show/${row.dataset.id}`;
  });

  listEl.addEventListener('change', async (e) => {
    const checkbox = e.target.closest('.watched-circle');
    if (!checkbox) return;

    const showId = Number(checkbox.dataset.show);
    const season = Number(checkbox.dataset.season);
    const episode = Number(checkbox.dataset.episode);
    const row = checkbox.closest('.calendar-row');
    const checked = checkbox.checked;

    checkbox.disabled = true;
    try {
      if (checked) {
        await api.markWatched(showId, season, episode);
      } else {
        await api.unmarkWatched(showId, season, episode);
      }
      row.classList.toggle('watched', checked);
    } catch (err) {
      checkbox.checked = !checked;
      alert(err.message || 'Failed to update watched status.');
    } finally {
      checkbox.disabled = false;
    }
  });

  // Land on Today, leaving room for the sticky topbar.
  const topbar = document.querySelector('.topbar');
  const topbarHeight = topbar ? topbar.getBoundingClientRect().height : 0;
  const targetY = todayMarker.getBoundingClientRect().top + window.scrollY - topbarHeight - 12;
  window.scrollTo({ top: Math.max(0, targetY) });
}
