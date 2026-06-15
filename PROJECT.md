# Terrific Digital — WordPress Plugin

A reusable WordPress plugin (a toolkit) for Terrific Digital's client landing‑page
sites. Its first and main feature is **Terrific Forms** — a drag‑and‑drop form
builder with first‑class lead capture, tracking, and delivery. Built to replace
bespoke per‑page form JavaScript with one maintainable, trackable system so every
new landing page "just works" for leads + analytics.

**Current version:** 2.15.0

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
    class-td-menu.php           Top-level "Terrific Digital" admin menu (white "t" brand-mark icon,
                                CSS-masked to match native WP icon states); nests the CPT under it
    class-td-session.php        "Store Session Data" feature: option td_session {enabled,storage};
                                settings section + front-end session.js enqueue
    class-tf-settings.php       Settings page (shared form): tf_settings { global_webhook } +
                                a do_action('terrific_digital/settings_fields') hook for other features
    class-tf-cpt.php            Registers the `terrific_form` CPT (+ Form ID/Shortcode list columns)
    class-tf-builder.php        Admin builder: meta boxes, save(), sanitize_def(), sanitize_actions(),
                                find_usages() ("used on these pages"), render_settings/actions/usage
    class-tf-render.php         Frontend: [terrific_form] shortcode + markup() + field() renderer
    class-tf-submissions.php    AJAX (tf_submit/tf_step/tf_geo), store_submission(), webhook_payload(),
                                request_context(), device/geo/cookies, list/detail/CSV/delete screens
    class-tf-list-table.php     WP_List_Table for the Submissions screen
    class-tf-revisions.php      Form revision history: {prefix}tf_form_revisions table, capture() on
                                every save (hash-deduped + pruned to 50/form), per-form "Revisions"
                                meta box, and one-click Restore (admin_post_tf_restore_revision)
    class-tf-block.php          Gutenberg block "Terrific Form" (dynamic, server-rendered)
  assets/
    builder.js / builder.css    Drag-and-drop form builder UI (SortableJS)
    actions.js                  Actions list UI (Webhook / Redirect cards)
    form.js / form.css          Frontend form behaviour (steps, validation, dataLayer, submit, geo, date-range)
    session.js                  Store Session Data: writes td_session_data once per session (storage/cookie)
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
  min_from_today, min_days, max_days,   // daterange constraints (days; 0 valid)
  step, default,                  // slider
  html,                           // custom_html content
  options: [ {label, value} ] }   // select/radio/checkbox
```
**Field types:** text, email, tel (phone), number, **slider**, date, **daterange**, textarea,
select (dropdown), radio, checkbox, heading, **paragraph**, **separator**, **custom_html**, hidden.

- **Static/display fields** (no submitted value, no `key`): `heading` (`<h4>`), `paragraph`
  (multi-line `<p>`, text kept in `label`), `separator` (`<hr>`), `custom_html` (raw HTML in
  `html`; stored raw for `unfiltered_html` authors, else `wp_kses_post`). The builder hides the
  Key field for all of these.
- **slider** — `<input type="range">` with `min`/`max`/`step`/`default` (default value falls back
  to `min`); live value readout via `.tf-range-val`; submits the chosen number under its `key`.

**Date range field** (`daterange`) — start + end `<input type="date">`. Edit options:
`min_from_today` (earliest start in days from today; **0** = today/blocks past, blank = no
limit), `min_days` / `max_days` (allowed length of the range, in days). Submits a value
**object** `{ start_date, end_date, duration }` (duration = whole days between the two dates).
Rendered as a `.tf-daterange[data-key]` group; collected/validated specially in form.js
(dynamic min/max on the inputs + live duration readout). Stored as a nested object in the
submission `data`; CSV/detail flatten it to `start → end (N days)` via `flatten_value()`.

**Submissions table `{prefix}tf_submissions`**
`id, form_post_id, form_id, form_name, data (JSON of fields), page_url, user_ip,
user_agent, intent, meta (JSON {intent, submission, device, cookies}),
status ('partial'|'completed'), last_step, token, updated_at, created_at`.

**Revisions table `{prefix}tf_form_revisions`**
`id, form_post_id, form_id, title, snapshot (JSON {title, definition, type, intent,
submit_text, success, actions}), author_id, author_name, note, hash (sha1 of snapshot
for dedupe), created_at`. Pruned to the most recent 50 per form (filter
`terrific_forms/revisions_keep`).

**Store Session Data** — WP option `td_session` = `{ enabled, storage:'session'|'cookie' }`
(TD_Session). When enabled, `session.js` writes a `td_session_data` object **once per session**
(only if not already present), into `sessionStorage` or a **session cookie** (named
`td_session_data`, no Expires → cleared on browser close; `SameSite=Lax`, `Secure` on HTTPS):
```jsonc
{ "timestamp": "<ISO 8601 session-start>", "url": "<landing href>", "ref": "<referrer>" }
```

**Options:** WP option `tf_settings` = `{ global_webhook }`. DB-version gates:
`tf_db_version` (submissions) and `tf_revisions_db_version` (revisions) — each re-runs
its `dbDelta` when ≠ `TF_VERSION`.

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

**Admin menu:** Terrific Digital → **Dashboard · Forms · Submissions · Settings** (no "Add New"
link — new forms are created from the Forms list screen's own "Add New" button).

**Honeypot (anti-bot):** every rendered form includes an off-screen `.tf-hp` field
(`name="tf_hp"`, the bait label "Email"). form.js posts its value as `hp`; if non-empty,
`TF_Submissions::is_spam()` makes `tf_submit`/`tf_step` return a success-shaped response but
**store nothing and fire no actions** (bots think they succeeded, so they don't retry).

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
- **Revisions / version history** (2.13.0) — every form save snapshots the full form state
  (title + all `_tf_*` meta) into `{prefix}tf_form_revisions`. A per-form **Revisions** meta box
  lists each version (relative + absolute time, author, change summary like "Multi-step · 2 steps ·
  3 fields", a "Current" badge) with one-click **Restore** (nonce'd, confirm dialog). Restoring
  re-saves current state as a new revision first ("Restored from #N"), so nothing is ever lost.
  Snapshots are hash-deduped (no-op saves add nothing) and pruned to 50/form. `apply_snapshot()`
  refuses a malformed/empty snapshot so it can never blank a form.
- **Date range field** (2.14.0) — `daterange` field type (start+end dates) with earliest-start
  (days-from-today / block-past), min/max length options; emits `{start_date, end_date, duration}`.
- **Store Session Data** (2.14.0) — opt-in plugin-wide feature recording a `td_session_data`
  object `{timestamp, url, ref}` once per session in sessionStorage or a session cookie.
- **Brand icon** (2.14.0) — the Terrific Digital "t" mosaic mark is the top-level menu icon,
  CSS-masked white (grey at rest, white on hover/current) to match native WP admin icons.
- **More field types** (2.15.0) — `slider` (number range with min/max/step/default + live readout),
  `paragraph` (multi-line static text), `separator` (`<hr>`), `custom_html` (embed arbitrary HTML).
  `heading` no longer has a Key field; static/display fields carry no data-layer key.
- **Honeypot** (2.15.0) — every form auto-includes an off-screen bait field; filled = bot →
  submission silently dropped (success-shaped response, nothing stored/sent).
- Removed the "Add New" admin submenu (create forms from the Forms list screen).

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
