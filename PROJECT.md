# Terrific Digital — WordPress Plugin

A reusable WordPress plugin (a toolkit) for Terrific Digital's client landing‑page
sites. Its first and main feature is **Terrific Forms** — a drag‑and‑drop form
builder with first‑class lead capture, tracking, and delivery. Built to replace
bespoke per‑page form JavaScript with one maintainable, trackable system so every
new landing page "just works" for leads + analytics.

**Current version:** 2.12.2

---

## 1. Where everything lives

| | |
|---|---|
| **Canonical source (work here)** | `/Users/matangaash/Claude/terrific-digital-plugin/` (git repo) |
| **Deployed to** | Cloudways app `travelbyluxe-lp` → `wp-content/plugins/terrific-digital/` |
| **Server SSH** | `ssh -i ~/.ssh/tbl_cloudways master_emqjrcyekf@46.101.96.39` |
| **WP path** | `~/applications/mpggmnftkb/public_html` |
| **WP admin** | https://wordpress-1636848-6491457.cloudwaysapps.com/wp-admin/ (creds in the LP repo `.cloudways-wp.local.md`) |
| **Demo form** | post **62** (`tf_62_3ea8e1`) · pages `/tf-demo/` (shortcode) and `/tf-block-demo/` (block) |

> ⚠️ Earlier the version string in `terrific-digital.php` drifted (stuck at 2.6.0) because
> `sed` version‑bumps silently failed to match. `TD_VERSION` is the asset cache‑buster
> (`?ver=`), so **always bump it on every change** or returning visitors get stale JS/CSS.

---

## 2. Architecture / file map

```
terrific-digital/
  terrific-digital.php          Bootstrap: constants, requires, init(), and all global tf_* helpers
  includes/
    class-td-menu.php           Top-level "Terrific Digital" admin menu; nests the CPT under it
    class-tf-settings.php       Settings page → option tf_settings { global_webhook }
    class-tf-cpt.php            Registers the `terrific_form` CPT (+ Form ID/Shortcode list columns)
    class-tf-builder.php        Admin builder: meta boxes, save(), sanitize_def(), sanitize_actions(),
                                find_usages() ("used on these pages"), render_settings/actions/usage
    class-tf-render.php         Frontend: [terrific_form] shortcode + markup() + field() renderer
    class-tf-submissions.php    AJAX (tf_submit/tf_step/tf_geo), store_submission(), webhook_payload(),
                                request_context(), device/geo/cookies, list/detail/CSV/delete screens
    class-tf-list-table.php     WP_List_Table for the Submissions screen
    class-tf-block.php          Gutenberg block "Terrific Form" (dynamic, server-rendered)
  assets/
    builder.js / builder.css    Drag-and-drop form builder UI (SortableJS)
    actions.js                  Actions list UI (Webhook / Redirect cards)
    form.js / form.css          Frontend form behaviour (steps, validation, dataLayer, submit, geo)
    block.js                    Block editor UI (no build step; wp.element.createElement)
    sortable.min.js             Bundled SortableJS 1.15.2 (no runtime CDN)
```

**Constants** (in `terrific-digital.php`): `TD_VERSION`, `TD_MENU` = `'terrific-digital'`,
`TF_VERSION` (= TD_VERSION), `TF_FILE/TF_DIR/TF_URL`, `TF_CPT` = `'terrific_form'`.

**Global helpers** (`terrific-digital.php`): `tf_get_form`, `tf_ensure_form_id`,
`tf_client_ip`, `tf_user_agent`, `tf_global_webhook`, `tf_intents`/`tf_get_intent`/
`tf_form_intent_param`, `tf_get_actions`/`tf_resolve_webhook_url`/`tf_resolve_redirect_url`,
`tf_form_has_step_webhook`, `tf_device_category`/`tf_device_os`/`tf_device_browser`,
`tf_geo_lookup`, `tf_collect_cookies`, `tf_countries`, `tf_flag`.

---

## 3. Data model

**CPT `terrific_form` post meta**
- `_tf_form_id` — static id `tf_{postID}_{6hex}` (never changes when renamed)
- `_tf_definition` — JSON `{ type:'regular'|'multistep', steps:[ {title, fields:[…]} ] }` (store **wp_slash'd**)
- `_tf_type`, `_tf_submit_text`, `_tf_success`, `_tf_intent` (default `lead`)
- `_tf_actions` — JSON `[ { type:'webhook'|'redirect', enabled:bool, config:{…} } ]` (one of each max)

**Field shape**
```
{ id, type, label, key, placeholder, required, show_label,
  width: full|half|third, min, max, minlength, maxlength,
  multiple,                       // dropdown multi-select
  options: [ {label, value} ] }   // select/radio/checkbox
```
**Field types:** text, email, tel (phone), number, date, textarea, select (dropdown),
radio, checkbox, heading, hidden.

**Submissions table `{prefix}tf_submissions`**
`id, form_post_id, form_id, form_name, data (JSON of fields), page_url, user_ip,
user_agent, intent, meta (JSON {intent, submission, device, cookies}),
status ('partial'|'completed'), last_step, token, updated_at, created_at`.

**Options:** WP option `tf_settings` = `{ global_webhook }`.

---

## 4. Front-end contracts

**dataLayer events** (form.js) — `form_step` on each Next, `form_submission` on submit:
```js
{ event:'form_step'|'form_submission', form_id, form_name, form_type:'Terrific Form',
  form_step?, form_intent?, form_fields:{ key: value } }
```

**Webhook payload** (POSTed JSON):
```jsonc
{ "event": "form_submission" | "form_step",
  "data": { /* all form fields */ },
  "form": { "id", "name", "intent", "wp_id" },
  "submission": { "id", "timestamp", "submission_link", "page_url", "page_title", "page_ref",
                  "current_step"?, "max_step"?, "final_step"? },   // step fields only when "every step"
  "device": { "user_agent","user_ip","category","ip_country","ip_country_code",
              "platform","language","os","browser" },
  "cookies": { /* all request cookies except WP auth/internal */ } }
```

**AJAX / endpoints**
- `wp_ajax(_nopriv)_tf_submit` — final submit (stores/completes the row, fires final webhooks, returns redirect)
- `wp_ajax(_nopriv)_tf_step` — per-step ping: stores/updates the **partial** row + fires "every"-trigger webhooks
- `wp_ajax(_nopriv)_tf_geo` — returns the visitor's `country_code` (phone auto-select; cache-safe)
- `admin_post_tf_export` — CSV export · `admin_post_tf_delete` — delete one

**Admin menu:** Terrific Digital → **Dashboard · Forms · Add New · Submissions · Settings**.

---

## 5. Feature history (what's built)

- **Builder** — drag-and-drop (SortableJS), regular + multi-step, per-field **Width** (full/½/⅓),
  per-field **Show label** toggle, collapsible field cards ("Edit"⇄"Minimize", click name to toggle).
- **Field options** — number **min/max**, **min/max length** (text/email/tel/textarea),
  **phone** field with IP-geolocated country dial-code dropdown, **dropdown** single/multi +
  **Label/Value** options table (value can differ from label).
- **Intent** — per-form (only "Lead" → `generate_lead`); adds `form_intent` to dataLayer + webhook.
- **Placement** — shortcode `[terrific_form id="N"]` **and** a Gutenberg block "Terrific Form"
  (live, non-interactive preview + "Edit form ↗" + form switcher).
- **Actions** (one of each) — **Webhook** (global from Settings or custom URL; for multi-step a
  "Send on: Final step only / Every step" option) and **Redirect** (URL or WP page). Each toggleable.
- **Submissions** — table (filter by form, CSV export, delete, per-row **View** detail),
  auto-captured IP / user-agent / conversion URL / referrer / device (category/os/browser/
  platform/language) / **IP country** (ipwho.is, cached) / cookies. Detail view + status badges.
- **Partial / abandoned capture** — multi-step forms create a submission row on **step 1**
  (Status = Partial) keyed by a client token; it updates each step and flips to **Completed**
  on submit (no duplicates).
- Form edit screen extras: "View submissions (N)" link + "Used on these pages" finder.

---

## 6. Conventions & gotchas

- **`wp_slash(wp_json_encode(...))`** when storing JSON in **post meta** — `update_post_meta`
  internally `wp_unslash`es and would mangle `\uXXXX` (e.g. `…` → `u2026`). `$wpdb->insert/update`
  do **not** need it (they escape).
- **Bump `TD_VERSION`** on every change (asset cache-buster). After deploy: `wp breeze purge --cache=all`.
- **Geo:** `tf_geo_lookup()` uses ipwho.is over HTTPS, transient-cached per IP (1 week), honours a
  `CF-IPCountry` header if present, skips private IPs. Runs on every submission + the tf_geo endpoint.
- **Cookies:** `tf_collect_cookies()` returns all request cookies **except** `wordpress_*`, `wp-*`,
  `wp_*`, `comment_*` (never leak WP auth cookies to a webhook).
- **Block** is dynamic/server-rendered (render_callback reuses the shortcode renderer), no build step.
- **SortableJS** is bundled (no CDN at runtime).
- **Partial capture** keyed by `form.__tfToken` (client-generated, sent on every step + final);
  server `store_submission()` inserts-or-updates by token.

---

## 7. Deploy & test workflow

**Deploy** (from the canonical source):
```bash
# edit files in ~/Claude/terrific-digital-plugin/, bump TD_VERSION, then:
scp -i ~/.ssh/tbl_cloudways <file> master_emqjrcyekf@46.101.96.39:tmp.x
ssh -i ~/.ssh/tbl_cloudways master_emqjrcyekf@46.101.96.39 \
  'cd ~/applications/mpggmnftkb/public_html/wp-content/plugins/terrific-digital && cp ~/tmp.x <dest> && \
   php -l <dest>; cd ~/applications/mpggmnftkb/public_html && wp breeze purge --cache=all'
```
- DB ops / inspection: `wp eval` / `wp eval-file` (e.g. `TF_Submissions::create_table()` to migrate).
- On schema change, bump `TD_VERSION` so `maybe_create_table()` re-runs dbDelta.

**Test** (headless Chrome via CDP — Chrome 149 at `/Applications/Google Chrome.app`):
- Full-page screenshots: `/tmp/shot.js` (scroll-and-tile; use isolated `--user-data-dir`, unique port).
- Admin tests: log in at `/wp-login.php` with the WP creds, then drive the screen.
- Front-end behaviour: fill fields, click, read `window.dataLayer` / network for `tf_step` etc.
- Protected PHP methods: verify via `ReflectionMethod` in `wp eval`.
- Webhook payloads: echo through `https://httpbin.org/post` (blocking) — **do not** use webhook.site
  (blocked by the sandbox as exfil-shaped).

---

## 8. Roadmap / ideas (not yet built)

- Dedicated **range-slider** field type (the LP form used sliders for budget/length/pax).
- **Conditional logic** (show/hide fields based on other answers).
- More **action types** (send email, Slack/Teams notification).
- **Submissions filtering** by status (partial/completed) + date range; partial-lead nurturing.
- Wire a Terrific Forms form into the **luxury-travel-v3** page to replace the ported bespoke form.
- Package for reuse across other client sites (it's already generic).
