/**
 * Kochbuch client-side configuration constants.
 */
const LOG_ERROR = 0;
const LOG_WARN = 1;
const LOG_INFO = 2;
const LOG_DEBUG = 3;

// Max recipe image upload size - must match the backend's own limit once
// image upload is implemented (see todo.md: "Bilder vom Rezept").
const RECIPE_IMAGE_MAX_BYTES = 5 * 1024 * 1024;

// Admin list pagination (e.g. /admin/users) - matches YTAN's own
// config.js values (todo.md: "Benutzerverwaltung ... Gestaltung übernommen").
const ADMIN_LIST_PAGE_SIZES = [5, 10, 50, 100];
const ADMIN_LIST_DEFAULT_PAGE_SIZE = 10;
