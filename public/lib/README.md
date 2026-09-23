Vendored third-party frontend libraries (plain `<script>`/`<link>` includes,
no bundler/npm - see CLAUDE.md), each in its own subdirectory:

- `bootstrap/` - Bootstrap 5.3 CSS/JS (vendored from `cdn.jsdelivr.net/npm/bootstrap@5.3.3`).
- `bootstrap-icons/` - Bootstrap Icons 1.11 webfont + CSS.

Not vendored yet:

- `adminlte/` - the `/admin/*` shell (see CLAUDE.md's "Planned admin area").
- `jquery/` - only needed once AdminLTE is added.
