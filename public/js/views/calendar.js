import { api, posterUrl } from '../api.js';

function formatDate(iso) {
  const d = new Date(iso + 'T00:00:00');
  return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
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

  const episodes = await api.getCalendar();

  if (episodes.length === 0) {
    view.innerHTML = `
      <h2 class="section-title">Calendar</h2>
      <p class="empty">No episodes to show yet. Add shows to My Shows to see recent and upcoming air dates here.</p>
    `;
    return;
  }

  // Newest-first for what already aired, soonest-first for what's next.
  const recent = episodes.filter((item) => item.aired).reverse();
  const upcoming = episodes.filter((item) => !item.aired);

  view.innerHTML = `
    ${
      recent.length
        ? `<h2 class="section-title">Recently Aired</h2>
           <div class="calendar-list">${recent.map(episodeRow).join('')}</div>`
        : ''
    }
    ${
      upcoming.length
        ? `<h2 class="section-title">Upcoming</h2>
           <div class="calendar-list">${upcoming.map(episodeRow).join('')}</div>`
        : ''
    }
  `;

  view.querySelectorAll('.calendar-row').forEach((row) => {
    row.addEventListener('click', (e) => {
      if (e.target.closest('.watched-circle')) return;
      window.location.hash = `#/show/${row.dataset.id}`;
    });
  });

  view.querySelectorAll('.watched-circle').forEach((checkbox) => {
    checkbox.addEventListener('click', (e) => e.stopPropagation());
    checkbox.addEventListener('change', async () => {
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
  });
}
