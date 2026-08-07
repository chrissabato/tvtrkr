// Thin fetch wrapper for our own PHP backend (which itself proxies TMDB).

async function request(path, options = {}) {
  const res = await fetch(`/api${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
  });

  const data = await res.json().catch(() => ({}));

  if (!res.ok) {
    const err = new Error(data.error || `Request failed: ${res.status}`);
    err.status = res.status;
    throw err;
  }

  return data;
}

export const api = {
  // Auth
  getMe: () => request('/auth/me'),
  logout: () => request('/auth/logout', { method: 'POST' }),

  // Admin: allowed users
  listAllowedUsers: () => request('/admin/users'),
  addAllowedUser: (email) =>
    request('/admin/users', { method: 'POST', body: JSON.stringify({ email }) }),
  removeAllowedUser: (email) =>
    request(`/admin/users/${encodeURIComponent(email)}`, { method: 'DELETE' }),

  // TMDB proxy
  searchShows: (query, page = 1) =>
    request(`/tmdb/search?query=${encodeURIComponent(query)}&page=${page}`),
  discoverShows: (page = 1) => request(`/tmdb/discover?page=${page}`),
  getShow: (id) => request(`/tmdb/show/${id}`),
  getSeason: (id, seasonNumber) => request(`/tmdb/show/${id}/season/${seasonNumber}`),

  // Watchlist
  listLibrary: () => request('/shows'),
  addToLibrary: (show) =>
    request('/shows', { method: 'POST', body: JSON.stringify(show) }),
  removeFromLibrary: (id) => request(`/shows/${id}`, { method: 'DELETE' }),

  // Watched episodes
  listWatched: (showId) => request(`/shows/${showId}/watched`),
  markWatched: (showId, season, episode) =>
    request(`/shows/${showId}/watched`, {
      method: 'POST',
      body: JSON.stringify({ season, episode }),
    }),
  unmarkWatched: (showId, season, episode) =>
    request(`/shows/${showId}/watched?season=${season}&episode=${episode}`, {
      method: 'DELETE',
    }),
  markSeasonWatched: (showId, season, episodeCount) =>
    request(`/shows/${showId}/watched/season/${season}`, {
      method: 'POST',
      body: JSON.stringify({ episode_count: episodeCount }),
    }),
  unmarkSeasonWatched: (showId, season) =>
    request(`/shows/${showId}/watched/season/${season}`, { method: 'DELETE' }),

  // Calendar
  getCalendar: () => request('/calendar'),
};

export const TMDB_IMG = 'https://image.tmdb.org/t/p';
export function posterUrl(path, size = 'w342') {
  return path ? `${TMDB_IMG}/${size}${path}` : '';
}
