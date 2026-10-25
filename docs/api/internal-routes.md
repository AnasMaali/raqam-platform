# Routes outside the public API

Every route of the back end is documented: the public API (`/api/v1/…`) in `openapi.yaml`, the admin panel (Filament,
under the private `ADMIN_PATH`, every page behind the sign-in) by its screens, and the few others here. A test
(`RouteDocumentationTest`) fails when a route is in none of them, or when this list names a route that no longer exists.

| Method | Address | What it is | Protection |
|---|---|---|---|
| GET | `preview/{post}` | Preview of a draft for its editor (21.8) | signed, expiring link; per-visitor limit; noindex, no-store |
| GET | `private-media/{path}` | A private file (booking attachments, invoices) | signed link, 10 minutes; sandboxed, no-store |
| PUT | `private-media/{path}` | Laravel's upload receiver of the local private disk (registered with `serve`) | refused without a signed upload link; the application never makes one |
| GET | `up` | Health check for the server's monitor | none needed; says only "up" |
| GET | `livewire/livewire.js` | The panel's Livewire script | public file |
| GET | `livewire/livewire.min.js.map` | Its source map | public file |
| GET | `livewire/preview-file/{filename}` | Preview of a file chosen in a panel form | Livewire's signed URL |
| POST | `livewire/update` | Every action of a panel screen | session + CSRF; each component checks the signed-in admin |
| POST | `livewire/upload-file` | Temporary upload of a panel form | Livewire's signed URL; the file is checked again by the media library |

Filament also names two download routes outside the panel, for its exports and imports (`filament/exports/{export}/download`,
`filament/imports/{import}/failed-rows/download`). The platform uses neither feature; `routes/web.php` replaces both with a
plain 404, so they never reach the database.
