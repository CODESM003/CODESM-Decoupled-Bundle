# CODESM Decoupled Bundle

A WordPress plugin that acts as the **settings and configuration layer** for a decoupled Astro + WordPress setup. All settings are stored in a single WordPress option and exposed via a single public REST API endpoint that Astro fetches at build time.

---

## How It Works

```
WordPress Admin                REST API              Astro (build time)
─────────────────   ────────────────────────────   ──────────────────────
Plugin settings  →  GET /wp-json/codesm-          →  Layout.astro injects
(title, contact,    decoupled-bundle/v1/settings      GTM, JSON-LD, scripts
 GTM, scripts,
 per-page rules)

Publish/trash    →  WP Cron (debounced)           →  GitHub Actions
post or page                                           Sevalla runs build
Plugin settings                                        → site deploys
```

Since the frontend is Astro (fully static), WordPress cannot inject scripts at runtime via `wp_head`. Instead, this plugin stores everything centrally and the Astro site fetches it once per build.

---

## Requirements

- WordPress **6.9 or later**
- PHP **8.0 or later**

The Abilities API integration requires WordPress 6.9+. The plugin loads cleanly on older versions — Abilities registration is silently skipped if the API is not available.

---

## Installation

1. Upload the `codesm-decoupled-bundle` folder to `/wp-content/plugins/`
2. Activate the plugin in **Plugins → Installed Plugins**
3. Navigate to **Decoupled Bundle** in the WordPress admin sidebar
4. Fill in your settings and click **Save Settings**

### Building the admin UI

The React admin panel requires a build step:

```bash
cd codesm-decoupled-bundle
npm install
npm run build
```

For development with hot reload:

```bash
npm start
```

---

## Admin Interface

The settings page is organised into six tabs:

| Tab | Contents |
|-----|----------|
| **Site** | Title, description, title format and separator |
| **Contact** | Phone numbers, email addresses, locations (each with address + coordinates) — all as multi-entry arrays |
| **Social** | Multiple entries per platform — each entry is a platform + URL pair |
| **Scripts** | GTM container ID, global JSON-LD, global script injection (header/body), per-page rules |
| **Build** | GitHub credentials, multi-branch auto-build targets, manual build trigger |
| **Deployments** | Live GitHub Actions run history and local WP dispatch log |

The **Save Settings** button lives in a footer below all tabs (hidden on the Deployments tab). The active tab is persisted in the URL as `?tab=` so the page can be bookmarked or refreshed without losing context.

Within the **Build** tab, entering your GitHub repository and Personal Access Token unlocks a **Load Workflows & Branches** button that fetches live data from GitHub — no manual IDs to copy. GitHub data is also auto-loaded when the tab opens (if credentials are present). Clicking **Trigger Build Now** shows a confirmation modal before dispatching.

A site-wide WordPress admin notice appears whenever an auto-build is pending, showing the scheduled time, repository, and branch targets.

---

## Settings Reference

All settings are stored in a single WordPress option key: `codesm_decoupled_bundle_settings` as a hierarchical array organised into logical sections.

### `site` — Site Identity

| Field | Path | Description |
|-------|------|-------------|
| Site Title | `site.title` | Defaults to the WordPress site name. Used in Astro `<title>` and OG tags. |
| Site Description | `site.description` | Defaults to the WordPress tagline. Used in meta description. |
| Title Format | `site.title_format` | Token template for the `<title>` tag. Default: `%title% %sep% %site%`. Tokens: `%title%`, `%site%`, `%sep%`. |
| Title Separator | `site.title_separator` | Character used as `%sep%` in the title format. Default: `\|`. Options: `\|`, `-`, `–`, `·`, `•`, `/`, `:`. |

### `contact` — Contact Information

All contact fields are arrays to support multiple entries.

#### `contact.phones[]`

| Key | Description |
|-----|-------------|
| `label` | Optional label, e.g. "Main", "Support" |
| `number` | Phone number string |

#### `contact.emails[]`

| Key | Description |
|-----|-------------|
| `label` | Optional label, e.g. "Sales", "Support" |
| `address` | Email address |

#### `contact.locations[]`

Each location entry contains a label, a full address object, and GPS coordinates.

| Key | Description |
|-----|-------------|
| `label` | Optional label, e.g. "Main Office", "Warehouse" |
| `address.street` | Street address line |
| `address.city` | City name |
| `address.state` | State, province, or region |
| `address.zip` | Postal code |
| `address.country` | Country name or ISO code |
| `coordinates.lat` | GPS latitude string |
| `coordinates.lng` | GPS longitude string |

### `social` — Social Media Links

`social` is an **array** of `{ platform, url }` objects. Multiple entries for the same platform are allowed (e.g. two Instagram accounts).

| Key | Description |
|-----|-------------|
| `platform` | Platform key (e.g. `facebook`, `instagram`, `twitter`) |
| `url` | Full URL |

Supported platform keys: `facebook`, `instagram`, `twitter`, `linkedin`, `youtube`, `tiktok`, `threads`, `pinterest`, `snapchat`, `whatsapp`, `telegram`, `discord`, `reddit`, `vimeo`, `github`, `behance`, `dribbble`.

### `gtm` — Google Tag Manager

| Field | Path | Description |
|-------|------|-------------|
| Container ID | `gtm.id` | e.g. `GTM-XXXXXXX`. Astro uses this to inject the GTM `<head>` snippet and `<noscript>` body tag. |

### `scripts` — Script Injection

These scripts are injected on **every page** of the Astro site.

| Field | Path | Description |
|-------|------|-------------|
| Global JSON-LD | `scripts.json_ld` | Sitewide structured data. Injected on every page without `<script>` wrappers. |
| Header Scripts | `scripts.header` | Raw HTML injected inside `<head>`. Use full `<script>` or `<link>` tags. |
| Body Start Scripts | `scripts.body_start` | Raw HTML injected immediately after `<body>` opens. |
| Body End Scripts | `scripts.body_end` | Raw HTML injected just before `</body>` closes. |
| Per-page Rules | `scripts.pages` | Array of per-URL injection rules (see below). |

#### Per-page rules — `scripts.pages[]`

Each entry targets a specific URL pattern and overrides/supplements the globals for that page.

| Field | Key | Description |
|-------|-----|-------------|
| URL Pattern | `url_pattern` | Path to match. Exact (`/about`) or wildcard (`/blog/*`). |
| JSON-LD | `json_ld` | Page-specific structured data. |
| Header Scripts | `header` | Raw HTML injected in `<head>` on this page only. |
| Body Start Scripts | `body_start` | Raw HTML injected after `<body>` open on this page only. |
| Body End Scripts | `body_end` | Raw HTML injected before `</body>` close on this page only. |

**URL pattern matching** (handled in Astro):
- `/about` — matches exactly `/about`
- `/blog/*` — matches `/blog/`, `/blog/my-post`, `/blog/category/post`
- `/services/*` — matches any path under `/services/`

### `build` — GitHub Actions Build Trigger

> These fields are **never** included in the public GET response. They are only returned to authenticated admin requests.

| Field | Path | Description |
|-------|------|-------------|
| GitHub Repository | `build.github_repo` | Format: `owner/repository-name`. |
| GitHub Token | `build.github_token` | Personal Access Token. Fine-grained: `Actions` read/write + `Contents` read. Classic: `repo` scope (private) or `public_repo` + `workflow` (public). |
| Auto-trigger | `build.auto_enabled` | Master switch — enables the debounced auto-build system. |
| Debounce delay | `build.debounce_minutes` | Minutes to wait after the last change before firing. Minimum 1, default 5. |
| Auto Targets | `build.auto_targets` | Array of branch targets for auto-builds (see below). |

#### Auto targets — `build.auto_targets[]`

Each entry defines a branch and the set of workflows to dispatch when that branch should be rebuilt.

| Key | Description |
|-----|-------------|
| `ref` | Branch name, e.g. `production`, `staging` |
| `workflows` | Array of numeric workflow ID strings to dispatch on this branch |

Multiple targets allow a single content change to trigger builds across several branches simultaneously (e.g. deploy to both production and staging).

---

## REST API

### `GET /wp-json/codesm-decoupled-bundle/v1/settings`

**Public — no authentication required.**

Returns all non-sensitive settings. This is the single endpoint your Astro site calls at build time. The `build` section (GitHub credentials) is always stripped server-side.

**Response shape:**

```json
{
  "site": {
    "title": "My Site",
    "description": "We build great things.",
    "title_separator": "|",
    "title_format": "%title% %sep% %site%"
  },
  "contact": {
    "phones": [
      { "label": "Main",    "number": "+1 (555) 000-0000" },
      { "label": "Support", "number": "+1 (555) 000-0001" }
    ],
    "emails": [
      { "label": "General", "address": "hello@mysite.com" },
      { "label": "Support", "address": "support@mysite.com" }
    ],
    "locations": [
      {
        "label": "Main Office",
        "address": {
          "street":  "123 Main St",
          "city":    "Austin",
          "state":   "TX",
          "zip":     "78701",
          "country": "US"
        },
        "coordinates": { "lat": "30.2672", "lng": "-97.7431" }
      }
    ]
  },
  "social": [
    { "platform": "facebook",  "url": "https://facebook.com/mypage" },
    { "platform": "instagram", "url": "https://instagram.com/myhandle" },
    { "platform": "instagram", "url": "https://instagram.com/myotherbrand" }
  ],
  "gtm": {
    "id": "GTM-XXXXXXX"
  },
  "scripts": {
    "json_ld":    "{\"@context\":\"https://schema.org\", ...}",
    "header":     "<script>/* global header script */</script>",
    "body_start": "",
    "body_end":   "<script>/* global footer script */</script>",
    "pages": [
      {
        "url_pattern": "/about",
        "json_ld":     "{\"@context\":\"https://schema.org\",\"@type\":\"AboutPage\",...}",
        "header":      "",
        "body_start":  "",
        "body_end":    ""
      }
    ]
  }
}
```

### `POST /wp-json/codesm-decoupled-bundle/v1/settings`

**Requires authentication** (`manage_options` capability). Used by the admin panel.

Accepts the same shape as the GET response plus the `build` section. Returns `{ success: true, settings: { ...fullSettings } }`.

### `GET /wp-json/codesm-decoupled-bundle/v1/workflows`

**Requires authentication** (`manage_options` capability).

Fetches all `workflow_dispatch`-enabled workflows from GitHub. Call this when opening the Build tab to populate the workflow checkboxes and branch selector.

**Response shape:**

```json
{
  "workflows": [
    {
      "id":       "12345678",
      "name":     "Deploy to Production",
      "filename": "deploy.yml",
      "state":    "active",
      "html_url": "https://github.com/owner/repo/actions/workflows/deploy.yml"
    },
    {
      "id":       "87654321",
      "name":     "Deploy to Staging",
      "filename": "deploy-staging.yml",
      "state":    "active",
      "html_url": "https://github.com/owner/repo/actions/workflows/deploy-staging.yml"
    }
  ],
  "error": null
}
```

### `GET /wp-json/codesm-decoupled-bundle/v1/branches`

**Requires authentication** (`manage_options` capability).

Fetches all branches for the configured repository from GitHub. The repo's default branch is flagged so the UI can pre-select it.

**Response shape:**

```json
{
  "branches": [
    { "name": "main",       "default": true  },
    { "name": "develop",    "default": false },
    { "name": "staging",    "default": false },
    { "name": "production", "default": false }
  ],
  "error": null
}
```

Branches are sorted with the default branch first, then alphabetically.

### `POST /wp-json/codesm-decoupled-bundle/v1/trigger-build`

**Requires authentication** (`manage_options` capability).

Immediately dispatches a `workflow_dispatch` event for the specified workflow on the specified branch. Manual triggers are rate-limited to once per 60 seconds per workflow.

**Request body:**

```json
{ "workflow_id": "12345678", "ref": "main" }
```

**Response:** `{ success: true, message: "...", timestamp: "...", workflow_id: "..." }`

Returns `400` for an invalid/missing `workflow_id`, `429` if rate-limited, `500` on GitHub API failure.

### `POST /wp-json/codesm-decoupled-bundle/v1/cancel-build`

**Requires authentication** (`manage_options` capability).

Cancels the pending debounced auto-build cron event, if one is scheduled. Has no effect if no build is queued.

**Response:** `{ success: true }`

### `GET /wp-json/codesm-decoupled-bundle/v1/builds`

**Requires authentication** (`manage_options` capability).

Returns the 15 most recent workflow runs across all workflows in the repo, merged with the local dispatch log. Also includes the next scheduled auto-build time and its configured targets.

**Response shape (abbreviated):**

```json
{
  "runs": [ ... ],
  "local_log": [ ... ],
  "next_scheduled": "2026-05-23T14:00:00Z",
  "next_scheduled_targets": [
    { "ref": "production", "workflow_count": 2 },
    { "ref": "staging",    "workflow_count": 1 }
  ],
  "error": null
}
```

---

## Abilities API

Requires WordPress 6.9+. The plugin registers a `codesm-decoupled-bundle` ability category and five abilities that expose the same operations as the REST API in a machine-readable, discoverable format — intended for AI agents and automation tools.

| Ability slug | Annotation | Auth | Description |
|---|---|---|---|
| `codesm-decoupled-bundle/get-settings` | `readonly` | Public | Returns the public site settings |
| `codesm-decoupled-bundle/save-settings` | `idempotent` | `manage_options` | Saves the full settings payload including build configuration |
| `codesm-decoupled-bundle/trigger-build` | — | `manage_options` | Dispatches a GitHub Actions workflow by ID and branch |
| `codesm-decoupled-bundle/get-builds` | `readonly` | `manage_options` | Returns GitHub workflow runs merged with the local dispatch log |
| `codesm-decoupled-bundle/cancel-build` | `destructive` | `manage_options` | Cancels the pending debounced auto-build cron event |

Each ability declares full JSON Schema definitions for its inputs and outputs and is marked `show_in_rest: true` so it appears in the WordPress REST discovery index. The `trigger-build` ability shares its validation and dispatch logic with the REST route via `BuildManager::trigger_manual()` — no duplicated code between the two interfaces.

### Discovery

List all abilities (requires authentication):

```bash
curl -u "username:app-password" \
  "https://example.com/wp-json/wp-abilities/v1/abilities?category=codesm-decoupled-bundle"
```

Authentication options:
- **Browser / WP admin** — `wp.apiFetch({ path: '/wp-abilities/v1/abilities?category=codesm-decoupled-bundle' }).then(console.log)` in the browser console on any admin page
- **External** — [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) via HTTP Basic Auth. Requires the `Authorization` header to reach PHP — add `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` to `.htaccess` if it doesn't work out of the box.

### Executing abilities

The `/run` suffix executes an ability. The HTTP method is determined by the ability's annotation:

**`readonly` → GET**

```bash
curl -u "username:app-password" \
  "https://example.com/wp-json/wp-abilities/v1/abilities/codesm-decoupled-bundle/get-settings/run"

curl -u "username:app-password" \
  "https://example.com/wp-json/wp-abilities/v1/abilities/codesm-decoupled-bundle/get-builds/run"
```

**No annotation → POST** (pass input as JSON body)

```bash
curl -X POST -u "username:app-password" \
  -H "Content-Type: application/json" \
  -d '{"input": {"workflow_id": "12345678", "ref": "main"}}' \
  "https://example.com/wp-json/wp-abilities/v1/abilities/codesm-decoupled-bundle/trigger-build/run"
```

**`destructive` → DELETE**

```bash
curl -X DELETE -u "username:app-password" \
  "https://example.com/wp-json/wp-abilities/v1/abilities/codesm-decoupled-bundle/cancel-build/run"
```

### Executing from PHP

```php
$ability = wp_get_ability( 'codesm-decoupled-bundle/trigger-build' );
if ( $ability ) {
    $result = $ability->execute( [ 'workflow_id' => '12345678', 'ref' => 'main' ] );
    if ( ! is_wp_error( $result ) ) {
        // $result['success'], $result['timestamp'], etc.
    }
}
```

### When to use Abilities vs REST routes

Use the **existing REST routes** (`/wp-json/codesm-decoupled-bundle/v1/...`) for your own code, scripts, and the Astro build — they are simpler to call directly.

Use the **Abilities API** when the caller has no prior knowledge of this plugin:
- An AI agent discovering and invoking site capabilities autonomously
- A plugin that wants to optionally integrate without a hard dependency — it calls `wp_get_ability()` and does nothing if the ability isn't present

---

## Astro Integration

### 1. Fetch settings helper

Add to `src/lib/wordpress.js`:

```js
export async function getSiteSettings() {
    const res = await fetch(
        `${import.meta.env.WORDPRESS_API_URL}/wp-json/codesm-decoupled-bundle/v1/settings`
    );
    if (!res.ok) throw new Error('Failed to fetch site settings');
    return res.json();
}
```

### 2. URL pattern matching utility

Add to `src/lib/siteSettings.js`:

```js
/**
 * Returns the merged script/JSON-LD payload for a given page path.
 * Global settings are the base; any matching per-page rule is merged on top.
 */
export function getPageInjections(settings, currentPath) {
    const global = {
        json_ld:    settings.scripts?.json_ld     || '',
        header:     settings.scripts?.header     || '',
        body_start: settings.scripts?.body_start || '',
        body_end:   settings.scripts?.body_end   || '',
    };

    const pageRules = settings.scripts?.pages || [];
    const match = pageRules.find(({ url_pattern }) =>
        matchesPattern(url_pattern, currentPath)
    );

    if (!match) return global;

    // Page-level values supplement globals; empty string = no override
    return {
        json_ld:    match.json_ld    || global.json_ld,
        header:     (global.header     + '\n' + (match.header     || '')).trim(),
        body_start: (global.body_start + '\n' + (match.body_start || '')).trim(),
        body_end:   (global.body_end   + '\n' + (match.body_end   || '')).trim(),
    };
}

function matchesPattern(pattern, path) {
    if (pattern.endsWith('/*')) {
        const base = pattern.slice(0, -2);
        return path === base || path.startsWith(base + '/');
    }
    return pattern === path;
}
```

### 3. Layout.astro

```astro
---
import { getSiteSettings } from '../lib/wordpress.js';
import { getPageInjections } from '../lib/siteSettings.js';

interface Props {
    title?:       string;  // page-level title — omit to use site title
    description?: string;  // page-level description — omit to use site description
    canonicalUrl?: string;
}

const settings    = await getSiteSettings();
const currentPath = new URL(Astro.request.url).pathname;
const inject      = getPageInjections(settings, currentPath);

// Build <title> using the stored format template
const pageTitle   = Astro.props.title       ?? settings.site.title;
const pageDesc    = Astro.props.description  ?? settings.site.description;
const sep         = settings.site.title_separator ?? '|';
const titleFormat = settings.site.title_format    ?? '%title% %sep% %site%';
const fullTitle   = titleFormat
    .replace('%title%', pageTitle)
    .replace('%sep%',   sep)
    .replace('%site%',  settings.site.title);
---
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{fullTitle}</title>
    <meta name="description" content={pageDesc} />

    <!-- Open Graph -->
    <meta property="og:title"       content={fullTitle} />
    <meta property="og:description" content={pageDesc} />
    <meta property="og:type"        content="website" />
    {Astro.props.canonicalUrl && <meta property="og:url" content={Astro.props.canonicalUrl} />}
    {Astro.props.canonicalUrl && <link rel="canonical" href={Astro.props.canonicalUrl} />}

    <!-- JSON-LD structured data -->
    {inject.json_ld && (
        <script type="application/ld+json" set:html={inject.json_ld} />
    )}

    <!-- Google Tag Manager -->
    {settings.gtm.id && (
        <script set:html={`
            (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
            new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
            j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
            'https://www.googletagmanager.com/gtm.js?id='+i+dl;
            f.parentNode.insertBefore(j,f);
            })(window,document,'script','dataLayer','${settings.gtm.id}');
        `} />
    )}

    <!-- Global + page-specific header scripts -->
    {inject.header && (
        <Fragment set:html={inject.header} />
    )}
</head>
<body>
    <!-- Google Tag Manager (noscript) -->
    {settings.gtm.id && (
        <noscript>
            <iframe
                src={`https://www.googletagmanager.com/ns.html?id=${settings.gtm.id}`}
                height="0" width="0"
                style="display:none;visibility:hidden"
            ></iframe>
        </noscript>
    )}

    <!-- Global + page-specific body start scripts -->
    {inject.body_start && (
        <Fragment set:html={inject.body_start} />
    )}

    <slot />

    <!-- Global + page-specific body end scripts -->
    {inject.body_end && (
        <Fragment set:html={inject.body_end} />
    )}
</body>
</html>
```

### 4. Using contact info in components

```astro
---
// src/components/Footer.astro
import { getSiteSettings } from '../lib/wordpress.js';
const { contact, social } = await getSiteSettings();
---
<footer>
    <!-- Phone numbers -->
    {contact.phones.map(({ label, number }) => (
        <a href={`tel:${number}`}>{label ? `${label}: ` : ''}{number}</a>
    ))}

    <!-- Email addresses -->
    {contact.emails.map(({ label, address }) => (
        <a href={`mailto:${address}`}>{label ? `${label}: ` : ''}{address}</a>
    ))}

    <!-- Locations -->
    {contact.locations.map(({ label, address, coordinates }) => (
        <div>
            {label && <strong>{label}</strong>}
            <address>
                {address.street  && <span>{address.street}</span>}
                {address.city    && <span>{address.city}{address.state ? `, ${address.state}` : ''}{address.zip ? ` ${address.zip}` : ''}</span>}
                {address.country && <span>{address.country}</span>}
            </address>
            {coordinates.lat && coordinates.lng && (
                <a href={`https://maps.google.com/maps?q=${coordinates.lat},${coordinates.lng}`} target="_blank" rel="noopener">
                    View on map
                </a>
            )}
        </div>
    ))}

    <!-- Social links — social is an array of { platform, url } -->
    <nav aria-label="Social media">
        {social.map(({ platform, url }) => (
            <a href={url} target="_blank" rel="noopener noreferrer">
                {platform}
            </a>
        ))}
    </nav>
</footer>
```

### 5. Embedding a map for a specific location

```astro
---
const { contact } = await getSiteSettings();
const main = contact.locations.find(l => l.label === 'Main Office') ?? contact.locations[0];
---
{main?.coordinates.lat && main?.coordinates.lng && (
    <iframe
        src={`https://maps.google.com/maps?q=${main.coordinates.lat},${main.coordinates.lng}&output=embed`}
        width="600" height="450"
        loading="lazy"
        allowfullscreen
    />
)}
```

> **Performance tip:** `getSiteSettings()` is called at build time, not at runtime. Results are baked into static HTML. Fetch once at the page level and pass data as props to child components to avoid redundant HTTP requests during the build.

---

## GitHub Actions — Build Trigger

### How it works

**Auto-build flow:**

1. Content changes in WordPress — a post/page is published, updated, or trashed; or a watched core option changes
2. Plugin schedules a WP Cron event N minutes in the future (debounced)
3. Each new change cancels and reschedules — rapid edits result in a single build burst
4. When the cron fires, the plugin iterates every entry in `build.auto_targets` and dispatches all configured workflows to their respective branches:
   ```
   POST https://api.github.com/repos/{owner}/{repo}/actions/workflows/{id}/dispatches
   Authorization: Bearer {token}
   { "ref": "production" }
   ```
5. Each dispatched workflow runs independently on GitHub → Sevalla picks up the push and runs `npm ci` + `npm run build` → site deploys

**Manual trigger flow:**

In the **Build** tab, select any loaded workflow and branch from the dropdowns, then click **Trigger Build Now**. The plugin immediately dispatches that single workflow. Rate-limited to once per 60 seconds per workflow.

### Configuring auto-build targets

1. Enter your GitHub token and repo in the **Build** tab, then click **Load Workflows & Branches**
2. Enable **Auto-Build** and set your debounce delay
3. Click **+ Add Branch Target** to add a target — pick a branch from the dropdown and check the workflows to dispatch for that branch
4. Add as many targets as needed (e.g. `production` → Deploy workflow, `staging` → Deploy staging workflow)
5. Save settings

### Setting up the GitHub Personal Access Token

The plugin needs a token with permission to read workflow definitions and trigger dispatches.

#### Option A — Fine-grained token (recommended)

Scope to a single repository with minimal permissions.

1. GitHub → **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**
2. Set **Repository access** → **Only select repositories** → pick your Astro repo
3. Under **Permissions → Repository permissions**, set:
   - **Actions** → **Read and write**
   - **Contents** → **Read-only**
   - **Metadata** → **Read-only** (auto-selected)
4. Copy the token and paste it into **Build → Personal Access Token**

> **Organisation repos:** Fine-grained tokens require the org owner to enable them under **Organisation settings → Personal access tokens → Allow fine-grained personal access tokens**. If your org repo doesn't appear in the picker, use Option B.

#### Option B — Classic token (org repos or fallback)

1. GitHub → **Settings → Developer settings → Personal access tokens → Tokens (classic) → Generate new token (classic)**
2. Select scopes:
   - **`repo`** — for private repos
   - **`public_repo`** + **`workflow`** — for public repos
3. If SAML SSO is enforced on your org, click **Authorise** next to the organisation after generating

#### Token security tips

- The token is saved in a WordPress option and never exposed via the public API
- Set an expiry date and rotate before it expires
- If compromised: delete on GitHub, generate a new one, update the plugin setting

### Workflow file setup

Two ready-made templates are included in `github-actions-template/`:

| File | Use for |
|------|---------|
| `deploy.yml` | Generic template — add your own deploy step |
| `deploy-to-sevalla.yml` | Pre-wired for [Sevalla](https://sevalla.com) static site hosting |

Copy the appropriate file into your Astro repository at `.github/workflows/` and set the required secrets (see file headers for details).

Every workflow **must** include `workflow_dispatch:` in its `on:` trigger so the plugin can invoke it:

```yaml
on:
  workflow_dispatch:   # required for WP plugin to trigger it
  push:
    branches: [main]
```

#### Sevalla template

The `deploy-to-sevalla.yml` template pushes the source to Sevalla and lets **Sevalla run `npm ci` + `npm run build`** on its own infrastructure — no Node setup steps needed in GitHub Actions.

Required secrets in your GitHub repository:
- `SEVALLA_DEPLOYMENT_TOKEN` — API token from Sevalla dashboard (Settings → API Tokens)
- `SEVALLA_STATIC_SITE_ID` — Static site ID from your Sevalla dashboard

Set `WORDPRESS_API_URL` and any other build-time environment variables in the **Sevalla dashboard** under your static site's Environment Variables settings.

---

## Auto-build Debounce

When **auto-build** is enabled and at least one target has a branch and workflows configured, every qualifying change resets the debounce timer. This means:

- You publish 3 posts in quick succession → only 1 burst of builds fires (N minutes after the last save)
- You update a post and then immediately update a setting → timer resets, 1 burst fires
- Multiple targets each dispatch their own workflows in the same burst

**What triggers the debounce timer:**
- Any post/page moved to `publish` or `private` (new or updated content live)
- A `publish`/`private` post moved to `trash` (content removed from the site)
- WordPress core settings: `blogname`, `blogdescription`, `siteurl`, `home`
- Saving plugin settings (any tab)

**What does NOT trigger it:**
- Trashing a draft or unpublished post (was never visible on the frontend)
- Post revisions / autosaves
- Other plugin option changes

---

## Deployment History

The **Deployments** tab shows the 15 most recent GitHub Actions runs across all workflows in the repo, merged with the local WP dispatch log (capped at 20 entries).

If an auto-build is scheduled, a banner at the top of the tab shows the next build time (UTC), a live countdown, the repository, and each branch target with its workflow count. A **Cancel Scheduled Build** button on the right opens a confirmation modal before unscheduling the cron event.

| Column | Description |
|--------|-------------|
| Status | `queued`, `in_progress`, or `completed` with a colour-coded conclusion badge |
| # | GitHub run number |
| Workflow | Name of the workflow that ran |
| Source | `WP Manual`, `WP Auto`, `Code Push`, `Scheduled`, etc. |
| Branch | Branch the workflow ran against |
| Commit | Short SHA linked to GitHub |
| Started | Timestamp (UTC) |
| Duration | How long the run took once completed |

Cross-referencing uses the GitHub run `created_at` timestamp matched against the local dispatch log within ±60 seconds and the same workflow ID. The WP Dispatch Log (expandable below the table) shows every dispatch attempt with its trigger type, result, and any error message.

---

## Security Notes

- The `GET /settings` endpoint is **intentionally public** — it is designed to be called by the Astro CDN/build runner at build time. Treat it like a `robots.txt`: publicly readable, not sensitive.
- The entire `build` section (GitHub token, repo, auto_targets) is **always stripped server-side** before the public response is sent.
- Script injection fields (header/body/JSON-LD) are stored raw and output unescaped. They are only editable by `manage_options` users (admins). This is intentional — they contain trusted admin-entered HTML/JS.
- GitHub API errors are logged server-side only; sanitized generic messages are returned to the browser.
- The `html_url` field in build runs is validated against `https://github.com/` before being stored.
- All data created by the plugin is removed when the plugin is deleted (settings, build log, transients, scheduled cron).

---

## File Structure

```
codesm-decoupled-bundle/
├── codesm-decoupled-bundle.php       Main plugin file, constants, boot hooks
├── uninstall.php                      Cleans up all options and cron on plugin deletion
├── index.php                          Directory security
├── package.json                       npm build config (@wordpress/scripts)
├── .gitignore
├── github-actions-template/
│   ├── deploy.yml                     Generic workflow template
│   └── deploy-to-sevalla.yml          Sevalla static site deploy template (build runs on Sevalla)
├── includes/
│   └── classes/
│       ├── Core.php                   Boots Admin, RestApi, BuildManager, Abilities
│       ├── Settings.php               Option read/write and sanitization
│       ├── BuildManager.php           GitHub dispatch, cron debounce, build log
│       ├── RestApi.php                REST route registration and handlers
│       ├── Admin.php                  WP admin menu page and script enqueue
│       └── Abilities.php              WordPress Abilities API registration (WP 6.9+)
└── src/
    ├── index.js                        React admin app (compiled to build/)
    └── styles.scss                     Admin styles
```

---

## Development

```bash
npm install          # install build dependencies
npm start            # dev mode with hot reload (watches src/)
npm run build        # production build → build/
npm run lint:js      # ESLint
npm run lint:css     # StyleLint
npm run plugin-zip   # create distributable .zip
```

The compiled output (`build/`) is not committed to git. Run `npm run build` after cloning or updating `src/`.
