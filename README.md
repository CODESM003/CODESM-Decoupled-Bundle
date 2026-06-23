# CODESM Decoupled Bundle

A WordPress plugin that centralizes site configuration (title, contact, GTM, scripts, maintenance mode) and exposes it via REST API for static site generators like Astro.

---

## Requirements

- WordPress **6.9 or later**
- PHP **8.0 or later**

---

## Installation

1. Upload `codesm-decoupled-bundle` to `/wp-content/plugins/`
2. Activate in **Plugins → Installed Plugins**
3. Go to **Decoupled Bundle** in the admin sidebar to configure

---

## Settings Structure

All settings are stored under the WordPress option `codesm_decoupled_bundle_settings` as a hierarchical array. **Public endpoints never expose the `build` section** (GitHub credentials).

### `site` — Site Identity

| Field | Path |
|-------|------|
| Site Title | `site.title` |
| Site Description | `site.description` |
| Title Format | `site.title_format` — template using `%title%`, `%sep%`, `%site%` tokens |
| Title Separator | `site.title_separator` — one of: `\|`, `-`, `–`, `·`, `•`, `/`, `:` |

### `contact` — Contact Information

| Field | Type |
|-------|------|
| `contact.phones[]` | `{ label, number }` |
| `contact.emails[]` | `{ label, address }` |
| `contact.locations[]` | `{ label, address: { street, city, state, zip, country }, coordinates: { lat, lng } }` |

### `social` — Social Media Links

| Field | Type |
|-------|------|
| `social[]` | `{ platform, url }` — multiple entries per platform allowed |

### `gtm` — Google Tag Manager

| Field | Type |
|-------|------|
| `gtm.id` | GTM container ID, e.g. `GTM-XXXXXXX` |

### `scripts` — Script Injection

| Field | Type | Description |
|-------|------|-------------|
| `scripts.json_ld` | string | Global structured data (no `<script>` wrapper) |
| `scripts.header` | string | Injected in `<head>` |
| `scripts.body_start` | string | Injected after `<body>` opens |
| `scripts.body_end` | string | Injected before `</body>` closes |
| `scripts.pages[]` | `{ url_pattern, json_ld, header, body_start, body_end }` | Per-URL rules |

**URL patterns:**
- `/about` — exact match
- `/blog/*` — wildcard suffix

### `build` — GitHub Actions (admin-only, never public)

| Field | Type |
|-------|------|
| `build.github_repo` | `owner/repo` |
| `build.github_token` | Personal Access Token |
| `build.auto_enabled` | boolean |
| `build.debounce_minutes` | number (minimum 1) |
| `build.auto_targets[]` | `{ ref, workflows[] }` |

### `plugin` — Plugin Settings

| Field | Type |
|-------|------|
| `plugin.prerelease_enabled` | boolean — auto-update to prerelease versions from GitHub |

### `maintenance` — Maintenance Mode

| Field | Type |
|-------|------|
| `maintenance.enabled` | boolean |
| `maintenance.from` | Unix timestamp (0 = immediate) |
| `maintenance.to` | Unix timestamp (0 = open-ended) |

---

## REST API

All requests use nonce-based CSRF protection via `X-WP-Nonce` header.

### `GET /wp-json/codesm-decoupled-bundle/v1/settings`

**Public — no auth required.**

Returns all non-sensitive settings (everything except `build` section).

**Response:**

```json
{
  "site": { "title": "...", "description": "...", "title_separator": "|", "title_format": "%title% %sep% %site%" },
  "contact": { "phones": [...], "emails": [...], "locations": [...] },
  "social": [...],
  "gtm": { "id": "..." },
  "scripts": { "json_ld": "...", "header": "...", "body_start": "...", "body_end": "...", "pages": [...] },
  "maintenance": { "enabled": false, "from": 0, "to": 0 }
}
```

---

### `POST /wp-json/codesm-decoupled-bundle/v1/settings`

**Requires `manage_options` capability.**

Accepts same structure as GET response plus the `build` section.

**Response:** `{ success: true, settings: { ...fullSettings } }`

---

### `GET /wp-json/codesm-decoupled-bundle/v1/workflows`

**Requires `manage_options` capability.**

Fetches all `workflow_dispatch`-enabled workflows from GitHub.

**Response:**

```json
{
  "workflows": [
    { "id": "12345678", "name": "Deploy to Production", "filename": "deploy.yml", "state": "active", "html_url": "..." }
  ],
  "error": null
}
```

---

### `GET /wp-json/codesm-decoupled-bundle/v1/branches`

**Requires `manage_options` capability.**

Fetches all branches from the configured repository.

**Response:**

```json
{
  "branches": [
    { "name": "main", "default": true },
    { "name": "staging", "default": false }
  ],
  "error": null
}
```

---

### `POST /wp-json/codesm-decoupled-bundle/v1/trigger-build`

**Requires `manage_options` capability.**

Dispatches a workflow on a specific branch.

**Request body:**

```json
{ "workflow_id": "12345678", "ref": "main" }
```

**Response:** `{ success: true, message: "...", timestamp: "...", workflow_id: "..." }`

---

### `POST /wp-json/codesm-decoupled-bundle/v1/cancel-build`

**Requires `manage_options` capability.**

Cancels the pending debounced auto-build.

**Response:** `{ success: true }`

---

### `GET /wp-json/codesm-decoupled-bundle/v1/builds`

**Requires `manage_options` capability.**

Returns 10 most recent workflow runs merged with local dispatch log. Results cached 5 minutes.

**Response:**

```json
{
  "runs": [...],
  "local_log": [...],
  "next_scheduled": "2026-05-23T14:00:00Z",
  "next_scheduled_targets": [{ "ref": "production", "workflow_count": 2 }],
  "error": null
}
```

---

### `POST /wp-json/codesm-decoupled-bundle/v1/clear-logs`

**Requires `manage_options` capability.**

Clears the local WP dispatch log.

**Response:** `{ success: true, cleared: true }`

---

### `GET /wp-json/codesm-decoupled-bundle/v1/update-info`

**Public — no auth required.**

Returns latest stable and prerelease versions from GitHub.

**Response:**

```json
{
  "stable": { "version": "1.0.0", "url": "...", "download_url": "...", "body": "..." },
  "prerelease": { "version": "1.1.0-prerelease", "url": "...", "download_url": "...", "body": "..." },
  "error": null
}
```

---

## Security

### Public vs. Authenticated

- `GET /settings` and `GET /update-info` are **public** — designed for frontend build processes
- Admin endpoints require `manage_options` capability and nonce verification
- The `build` section is **never** included in public responses

### GitHub Token

- Stored server-side only, never exposed to frontend or public APIs
- Set an expiry date and rotate before expiration
- If compromised: delete on GitHub and generate a new token immediately

### Script Injection

- Header/body/JSON-LD fields are stored raw and output unescaped
- Only editable by `manage_options` admins (trusted input)
- GitHub API errors are sanitized before returning to browser

### Data Cleanup

- All plugin data removed on uninstall (settings, logs, transients, cron events)
- Comprehensive wildcard-based transient cleanup prevents orphaned cached data

### Input Validation

- Coordinates validated as numeric
- GitHub repo validated against allowed characters
- URL patterns for per-page rules validated (no regex metacharacters)
- All settings sanitized server-side before API responses

---

## File Structure

```
codesm-decoupled-bundle/
├── codesm-decoupled-bundle.php       Main plugin file
├── uninstall.php                      Cleanup on deletion
├── package.json                       Build config
├── includes/classes/
│   ├── Core.php                       Bootstrap
│   ├── Settings.php                   Option storage & sanitization
│   ├── BuildManager.php               GitHub dispatch & cron
│   ├── RestApi.php                    REST routes
│   ├── Admin.php                      WordPress admin menu
│   ├── Abilities.php                  Abilities API (WP 6.9+)
│   └── GitHubUpdater.php              Plugin auto-updates
├── src/
│   ├── index.js                       React admin app
│   └── styles.scss                    Admin styles
└── build/                             Compiled assets
```

---

## Development

```bash
npm install              # Install build dependencies
npm start               # Dev mode with hot reload
npm run build           # Production build
npm run lint:js         # ESLint
npm run lint:css        # StyleLint
npm run plugin-zip      # Create distributable .zip
```
