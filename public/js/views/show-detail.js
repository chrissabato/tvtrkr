import { api, posterUrl } from '../api.js';

function episodeKey(season, episode) {
  return `${season}-${episode}`;
}

function hasAired(airDate) {
  if (!airDate) return false;
  const todayStr = new Date().toISOString().slice(0, 10);
  return airDate <= todayStr;
}

export async function renderShowDetail(view, id) {
  const showId = Number(id);

  view.innerHTML = '<p class="loading">Loading show…</p>';

  const [show, library, watchedRows] = await Promise.all([
    api.getShow(showId),
    api.listLibrary(),
    api.listWatched(showId).catch(() => []), // show may not be in library yet
  ]);

  let inLibrary = library.some((s) => s.tmdb_id === showId);
  const watchedSet = new Set(watchedRows.map((w) => episodeKey(w.season_number, w.episode_number)));

  const poster = posterUrl(show.poster_path);
  const seasons = (show.seasons || []).filter((s) => s.season_number !== 0 || s.episode_count > 0);

  // Episode lists (with air dates) are fetched once per season and reused for
  // header counts, the expanded episode list, and bulk "mark watched" actions.
  const seasonEpisodesCache = new Map();
  async function getSeasonEpisodes(seasonNumber) {
    if (!seasonEpisodesCache.has(seasonNumber)) {
      const season = await api.getSeason(showId, seasonNumber);
      seasonEpisodesCache.set(seasonNumber, season.episodes || []);
    }
    return seasonEpisodesCache.get(seasonNumber);
  }

  view.innerHTML = `
    <div class="detail-header">
      ${poster ? `<img src="${poster}" alt="${show.name}" />` : ''}
      <div class="detail-meta">
        <h2>${show.name}</h2>
        <div class="card-sub">${(show.first_air_date || '').slice(0, 4) || ''} · ${show.status || ''}</div>
        <p class="overview">${show.overview || ''}</p>
        <button class="btn ${inLibrary ? 'active' : ''}" id="toggle-library">
          ${inLibrary ? '✓ In My Shows' : '+ Add to My Shows'}
        </button>
      </div>
    </div>
    <h2 class="section-title">Seasons</h2>
    <div id="seasons"></div>
  `;

  const toggleBtn = view.querySelector('#toggle-library');

  function syncLibraryButton() {
    toggleBtn.textContent = inLibrary ? '✓ In My Shows' : '+ Add to My Shows';
    toggleBtn.classList.toggle('active', inLibrary);
  }

  async function addShowToLibrary() {
    await api.addToLibrary({
      tmdb_id: show.id,
      name: show.name,
      poster_path: show.poster_path,
      backdrop_path: show.backdrop_path,
      overview: show.overview,
      first_air_date: show.first_air_date,
      status: show.status,
    });
    inLibrary = true;
    syncLibraryButton();
  }

  toggleBtn.addEventListener('click', async () => {
    toggleBtn.disabled = true;
    if (inLibrary) {
      await api.removeFromLibrary(showId);
      inLibrary = false;
      syncLibraryButton();
    } else {
      await addShowToLibrary();
    }
    toggleBtn.disabled = false;
  });

  const seasonsContainer = view.querySelector('#seasons');

  seasons.forEach((season) => {
    const block = document.createElement('div');
    block.className = 'season-block';
    block.dataset.seasonBlock = season.season_number;
    block.innerHTML = `
      <div class="season-header" data-season="${season.season_number}">
        <div>
          <h3>${season.name} (${season.episode_count} eps)</h3>
          <div class="season-progress card-sub"></div>
        </div>
        <div>
          <button class="btn" data-mark-season="${season.season_number}" hidden>Mark aired watched</button>
        </div>
      </div>
      <div class="episode-list" data-episodes="${season.season_number}" hidden></div>
    `;
    seasonsContainer.appendChild(block);
  });

  // Renders the collapsed-state summary for one season: watched/aired count,
  // and whether the bulk "mark watched" button has anything left to do.
  function renderSeasonHeader(season) {
    const block = seasonsContainer.querySelector(`[data-season-block="${season.season_number}"]`);
    if (!block) return;

    const episodes = seasonEpisodesCache.get(season.season_number);
    const progressEl = block.querySelector('.season-progress');
    const markBtn = block.querySelector('[data-mark-season]');

    if (!episodes) {
      progressEl.textContent = 'Loading…';
      markBtn.hidden = true;
      return;
    }

    const airedEpisodes = episodes.filter((ep) => hasAired(ep.air_date));
    const watchedAiredCount = airedEpisodes.filter((ep) =>
      watchedSet.has(episodeKey(season.season_number, ep.episode_number))
    ).length;

    progressEl.textContent =
      airedEpisodes.length === 0 ? "Hasn't aired yet" : `${watchedAiredCount}/${airedEpisodes.length} watched`;

    // Nothing left to do: either nothing has aired yet, or everything aired has been watched.
    markBtn.hidden = watchedAiredCount === airedEpisodes.length;
  }

  // The season currently being watched: the first season (in order) that
  // has aired episodes still unwatched. A season with nothing aired yet is
  // skipped (nothing to catch up on there yet). If every aired episode
  // everywhere has been watched, falls through to the newest season —
  // for a brand new show this is season 1, since nothing is watched yet.
  function currentSeasonNumber() {
    const regularSeasons = seasons.filter((s) => s.season_number !== 0);
    for (const season of regularSeasons) {
      const episodes = seasonEpisodesCache.get(season.season_number) || [];
      const airedEpisodes = episodes.filter((ep) => hasAired(ep.air_date));
      if (airedEpisodes.length === 0) continue;
      const watchedAiredCount = airedEpisodes.filter((ep) =>
        watchedSet.has(episodeKey(season.season_number, ep.episode_number))
      ).length;
      if (watchedAiredCount < airedEpisodes.length) {
        return season.season_number;
      }
    }
    const fallback = regularSeasons.length ? regularSeasons[regularSeasons.length - 1] : seasons[0];
    return fallback ? fallback.season_number : null;
  }

  // Warm the episode cache for every season up front so headers can show
  // real counts without requiring the accordion to be opened, then expand
  // the season currently being watched.
  Promise.all(seasons.map((s) => getSeasonEpisodes(s.season_number))).then(async () => {
    seasons.forEach(renderSeasonHeader);

    const anyExpanded = [...seasonsContainer.querySelectorAll('.episode-list')].some((el) => !el.hidden);
    if (anyExpanded) return; // respect a season the user already opened manually

    const seasonNumber = currentSeasonNumber();
    const list = seasonNumber !== null ? seasonsContainer.querySelector(`[data-episodes="${seasonNumber}"]`) : null;
    if (list) {
      list.hidden = false;
      await loadEpisodes(seasonNumber, list);
    }
  });

  seasonsContainer.addEventListener('click', async (e) => {
    const header = e.target.closest('.season-header');
    const markBtn = e.target.closest('[data-mark-season]');

    if (markBtn) {
      const seasonNumber = Number(markBtn.dataset.markSeason);
      if (!inLibrary) {
        alert('Add this show to My Shows before marking episodes watched.');
        return;
      }
      markBtn.disabled = true;

      // Only mark episodes that have actually aired, rather than trusting
      // the season's total episode count.
      const episodes = await getSeasonEpisodes(seasonNumber);
      const airedEpisodes = episodes.filter((ep) => hasAired(ep.air_date));
      const airedCount = airedEpisodes.reduce((max, ep) => Math.max(max, ep.episode_number), 0);

      if (airedCount > 0) {
        await api.markSeasonWatched(showId, seasonNumber, airedCount);
        airedEpisodes.forEach((ep) => watchedSet.add(episodeKey(seasonNumber, ep.episode_number)));
      }

      renderSeasonHeader(seasonByNumber(seasonNumber));
      const list = seasonsContainer.querySelector(`[data-episodes="${seasonNumber}"]`);
      if (!list.hidden) {
        await loadEpisodes(seasonNumber, list);
      }
      markBtn.disabled = false;
      return;
    }

    if (header) {
      const seasonNumber = Number(header.dataset.season);
      const list = seasonsContainer.querySelector(`[data-episodes="${seasonNumber}"]`);
      list.hidden = !list.hidden;
      if (!list.hidden && !list.dataset.loaded) {
        await loadEpisodes(seasonNumber, list);
      }
    }
  });

  function seasonByNumber(seasonNumber) {
    return seasons.find((s) => s.season_number === seasonNumber);
  }

  function hasPriorAiredEpisode(seasonNumber, episodeNumber) {
    const regularSeasons = seasons.filter((s) => s.season_number !== 0);
    const minSeason = Math.min(...regularSeasons.map((s) => s.season_number));
    return seasonNumber > minSeason || episodeNumber > 1;
  }

  // Bulk-marks every aired episode strictly before (uptoSeasonNumber, uptoEpisodeNumber)
  // — all of earlier seasons, plus earlier episodes within the target season.
  async function markAllPriorEpisodes(uptoSeasonNumber, uptoEpisodeNumber) {
    const targets = seasons.filter((s) => s.season_number !== 0 && s.season_number <= uptoSeasonNumber);

    for (const s of targets) {
      const episodes = await getSeasonEpisodes(s.season_number);
      const toMark = episodes.filter(
        (ep) => hasAired(ep.air_date) && (s.season_number < uptoSeasonNumber || ep.episode_number < uptoEpisodeNumber)
      );
      if (toMark.length === 0) continue;

      const count = toMark.reduce((max, ep) => Math.max(max, ep.episode_number), 0);
      await api.markSeasonWatched(showId, s.season_number, count);
      toMark.forEach((ep) => watchedSet.add(episodeKey(s.season_number, ep.episode_number)));

      renderSeasonHeader(s);
      const list = seasonsContainer.querySelector(`[data-episodes="${s.season_number}"]`);
      if (list && !list.hidden) {
        await loadEpisodes(s.season_number, list);
      }
    }
  }

  seasonsContainer.addEventListener('change', async (e) => {
    if (e.target.matches('input[type="checkbox"][data-episode]')) {
      if (e.target.dataset.aired !== '1') {
        e.target.checked = false;
        alert("You can't check off an episode before it has aired.");
        return;
      }

      const seasonNumber = Number(e.target.dataset.season);
      const episodeNumber = Number(e.target.dataset.episode);
      const row = e.target.closest('.episode-row');

      if (e.target.checked) {
        let justAddedShow = false;

        if (!inLibrary) {
          await addShowToLibrary();
          justAddedShow = true;

          if (hasPriorAiredEpisode(seasonNumber, episodeNumber)) {
            const markPrevious = confirm(
              `Added "${show.name}" to My Shows.\n\nMark all previously aired episodes as watched too?`
            );
            if (markPrevious) {
              await markAllPriorEpisodes(seasonNumber, episodeNumber);
            }
          }
        }

        await api.markWatched(showId, seasonNumber, episodeNumber);
        watchedSet.add(episodeKey(seasonNumber, episodeNumber));

        if (justAddedShow) {
          // markAllPriorEpisodes may have already re-rendered this season's
          // list, detaching the original row element — do a full refresh.
          const list = seasonsContainer.querySelector(`[data-episodes="${seasonNumber}"]`);
          await loadEpisodes(seasonNumber, list);
        } else {
          row.classList.add('watched');
        }
      } else {
        await api.unmarkWatched(showId, seasonNumber, episodeNumber);
        watchedSet.delete(episodeKey(seasonNumber, episodeNumber));
        row.classList.remove('watched');
      }

      renderSeasonHeader(seasonByNumber(seasonNumber));
    }
  });

  async function loadEpisodes(seasonNumber, list) {
    if (!seasonEpisodesCache.has(seasonNumber)) {
      list.innerHTML = '<p class="loading">Loading episodes…</p>';
    }
    const episodes = await getSeasonEpisodes(seasonNumber);
    list.innerHTML = episodes
      .map((ep) => {
        const aired = hasAired(ep.air_date);
        const watched = aired && watchedSet.has(episodeKey(seasonNumber, ep.episode_number));
        const dateLabel = ep.air_date ? (aired ? ep.air_date : `Airs ${ep.air_date}`) : 'Air date TBA';
        return `
          <label class="episode-row ${watched ? 'watched' : ''} ${aired ? '' : 'unaired'}">
            <input
              type="checkbox"
              data-season="${seasonNumber}"
              data-episode="${ep.episode_number}"
              data-aired="${aired ? '1' : '0'}"
              ${watched ? 'checked' : ''}
              ${aired ? '' : 'disabled'}
            />
            <span class="ep-title">${ep.episode_number}. ${ep.name}</span>
            <span class="ep-date">${dateLabel}</span>
          </label>
        `;
      })
      .join('');
    list.dataset.loaded = '1';
  }
}
