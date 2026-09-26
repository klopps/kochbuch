Vendored third-party frontend libraries (plain `<script>`/`<link>` includes,
no bundler/npm - see CLAUDE.md), each in its own subdirectory:

- `bootstrap/` - Bootstrap 5.3 CSS/JS (vendored from `cdn.jsdelivr.net/npm/bootstrap@5.3.8` -
  bumped from 5.3.3 to match the Bootstrap version the vendored AdminLTE build below is
  compiled against; only the `/admin/*` shell actually needs 5.3.8's fixes, but there's no
  reason to keep two copies since 5.3.3->5.3.8 is patch-level and the main app's own UI
  doesn't rely on anything that changed).
- `bootstrap-icons/` - Bootstrap Icons 1.11 webfont + CSS.
- `adminlte/` - AdminLTE 4.9.1 CSS/JS (vendored from `cdn.jsdelivr.net/npm/admin-lte@4.9.1/dist/{css,js}/adminlte.min.{css,js}`)
  for the `/admin/*` shell (see CLAUDE.md's "Planned admin area"). Only these two files are
  vendored - AdminLTE's demo-only dependencies (ApexCharts, jsVectorMap, SortableJS,
  OverlayScrollbars, Fontsource) aren't needed for a stat-tile dashboard + plain forms.
  AdminLTE 4.x's own JS only requires `bootstrap.bundle.min.js` to be loaded first - jQuery
  is **not** needed anywhere in the admin area.
