# Kochbuch API

All endpoints are under `/api/v1`, return JSON, and use stateless JWT
bearer auth (`Authorization: Bearer <token>`) rather than PHP sessions.
Success responses are shaped `{"data": ...}`, errors `{"error": {"message": ..., "code"?: ...}}`.

## Auth

| Method | Path              | Auth | Description |
|--------|-------------------|------|--------------|
| GET    | `/api/v1/health`  | none | Liveness check, returns `{"data":{"status":"ok"}}`. |
| POST   | `/api/v1/auth/login` | none | Body `{"username","password"}` (username or email). Returns `{"token","expires_at","user"}`. |
| GET    | `/api/v1/auth/me` | required | Returns the current user. |

## Planned (not yet implemented - see todo.md)

- `/api/v1/recipes` - CRUD, search, portion scaling.
- `/api/v1/categories` - per-user custom categories, assigning recipes to them.
- `/api/v1/recipes/{id}/export` - JSON/PDF/XML export.
- `/api/v1/auth/set-password`, `/api/v1/auth/forgot-password` - invite/reset flow (schema already exists: `user_token`).
- `/api/v1/admin/users` - admin user management (AdminLTE `/admin/users`).
