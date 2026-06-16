# Terrific Digital — WordPress Plugin

A reusable WordPress plugin (a toolkit) for Terrific Digital's client landing‑page
sites. Its first and main feature is **Terrific Forms** — a drag‑and‑drop form
builder with first‑class lead capture, tracking, and delivery. Built to replace
bespoke per‑page form JavaScript with one maintainable, trackable system so every
new landing page "just works" for leads + analytics.

**Current version:** 2.21.1

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
    class-td-snippets.php       "Code Snippets" feature: admin-only td_snippet CPT (raw HTML/JS),
                                4 inject locations + conditions; output-buffer injection (template_redirect)
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
    form.js / form.css          Frontend form behaviour (steps, validation, dataLayer, submit, geo,
                                sliders, honeypot, session capture, "Edit form" link)
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
  multiple,                        // dropdown multi-select
  country_code,                    // phone: show dial-code selector (default true)
  min_from_today, max_from_today,  // date field bounds (days from today; 0 valid)
  step, default, prefix, suffix, show_scale, value_inline,   // slider
  html,                            // custom_html content
  next_text,                       // per-STEP (on the step object): Next button label
  options: [ {label, value} ] }    // select/radio/checkbox
```
**Field types:** text, email, tel (phone), number, **slider**, date, textarea,
select (dropdown), radio, checkbox, heading, **paragraph**, **separator**, **custom_html**, hidden.
Each step object also supports `next_text` (its Next button label; falls back to "Next →").
**phone** has a `country_code` toggle (default on) — off renders a plain tel input.

> The **date range** field was removed in 2.17.0 (flatpickr range UX wasn't reliable enough). The
> renderer skips any leftover `daterange` fields. To re-introduce, revert the removal commit.

- **Static/display fields** (no submitted value, no `key`): `heading` (`<h4>`), `paragraph`
  (multi-line `<p>`, text kept in `label`), `separator` (`<hr>`), `custom_html` (raw HTML in
  `html`; stored raw for `unfiltered_html` authors, else `wp_kses_post`). The builder hides the
  Key field for all of these.
- **slider** — `<input type="range">` with `min`/`max`/`step`/`default` (default value falls back
  to `min`). By default the value shows in a **bubble** beside the track (`.tf-range-val` output);
  opt into `value_inline` to show it **inline in the label** instead. Optional `prefix`/`suffix`
  (e.g. `$`, ` days` — leading/trailing spaces preserved) and `show_scale` (a `.tf-range-scale`
  min/max caption row). Values format with thousands separators. Submits the number under its `key`.

**Single `date` field** — also supports `min_from_today` / `max_from_today` (earliest/latest in
days from today; 0 = today, blank = no limit); converted to the input's `min`/`max` client-side
(cache-safe) with matching validation.

**Live preview** (builder) — a "Live Preview" meta box renders the real form server-side via the
`tf_preview` AJAX action (admin-only, nonce'd) into an iframe, updating ~400ms after each edit.
`TF_Render::markup($def, $post_id, $preview=true)` adds a `tf-preview` class (all steps shown
stacked, nav hidden, `onsubmit` disabled) reusing the exact frontend renderer for WYSIWYG accuracy.

**Submissions table `{prefix}tf_submissions`**
`id, form_post_id, form_id, form_name, data (JSON of fields), page_url, user_ip,
user_agent, intent, meta (JSON {intent, submission, device, cookies, session}),
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
form.js reads this object (sessionStorage or cookie) and posts it as `session` with each
submission/step; the server stores it in `meta.session`, adds it to the webhook payload as
`session` (omitted when empty), shows it in the submission detail view, and exports it to CSV.

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
  "cookies": { /* all request cookies except WP auth/internal */ },
  "session": { "timestamp", "url", "ref" } }   // only when Store Session Data collected it
```

**AJAX / endpoints**
- `wp_ajax(_nopriv)_tf_submit` — final submit (stores/completes the row, fires final webhooks, returns redirect)
- `wp_ajax(_nopriv)_tf_step` — per-step ping: stores/updates the **partial** row + fires "every"-trigger webhooks
- `wp_ajax(_nopriv)_tf_geo` — returns the visitor's `country_code` (phone auto-select; cache-safe)
- `admin_post_tf_export` — CSV export · `admin_post_tf_delete` — delete one

**Code Snippets** (`td_snippet` CPT, `capability_type=page` → `edit_pages`, like the Forms CPT and
the rest of the TD menu). Each snippet:
`_td_snippet_code` (raw), `_td_snippet_type` (html | js — js is wrapped in `<script>` on output),
`_td_snippet_location` (after_head_open · before_head_close · after_body_open · **before_body_close**),
`_td_snippet_enabled`, `_td_snippet_condition` (all · specific · url_contains · url_not_contains) +
`_td_snippet_pages` / `_td_snippet_url`. Injected via a single output buffer on `template_redirect`
(string-spliced at each position — `after_head_open` and the body positions have no WP hook),
skipped for admin/ajax/REST/feed/embed.

**Admin menu:** Terrific Digital → **Dashboard · Forms · Submissions · Code Snippets · Settings** (no "Add New"
link — new forms are created from the Forms list screen's own "Add New" button).

**Front-end "Edit form" link:** every rendered form prepends a small `.tf-edit-link` pill — the
colour **Terrific "t" brand mark** + "Edit form" — linking to that form's editor. Shown only to
logged-in users who `current_user_can( 'edit_post', $post_id )`, and never in preview or
`is_admin()` (block-editor SSR) contexts. Hidden from visitors.

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
- **Store Session Data** (2.14.0) — opt-in plugin-wide feature recording a `td_session_data`
  object `{timestamp, url, ref}` once per session in sessionStorage or a session cookie.
  (2.21.0: also captured into the submission `meta.session` + webhook `session` param + CSV.)
- **Brand icon** (2.14.0) — the Terrific Digital "t" mosaic mark is the top-level menu icon,
  CSS-masked white (grey at rest, white on hover/current) to match native WP admin icons.
- **More field types** (2.15.0) — `slider`, `paragraph` (multi-line static text), `separator`
  (`<hr>`), `custom_html` (embed arbitrary HTML). `heading` lost its Key field; static/display
  fields carry no data-layer key.
- **Honeypot** (2.15.0) — every form auto-includes an off-screen bait field; filled = bot →
  submission silently dropped (success-shaped response, nothing stored/sent).
- Removed the **"Add New"** admin submenu (create forms from the Forms list screen).
- **Per-step Next text** (2.15.1) — each step has an editable Next button label (default "Next →");
  the last step uses the form's Submit button text.
- **Phone country-code toggle** (2.15.1) — "Country-code selector" checkbox (default on); off
  renders a plain tel input.
- **Forms list** (2.15.2) — a **Submissions** column (linked count) + a "Submissions (N)" row
  action per form. Also fixed a slider-not-saving bug (a builder field input class collided with
  the multi-step container class `tf-b-step`, throwing in `sync()`); builder cards now render
  full-width while editing (the chosen ½/⅓ width shows as a badge and applies on the live form).
- **Dashboard** — logo + version pill (2.15.3), last-updated date (2.19.1), and a **Code Snippets**
  card (2.19.3).
- **Date field bounds** (2.15.4) — `min_from_today` / `max_from_today` (earliest/latest, days from
  today; cache-safe, client-computed) with validation.
- **Live preview** (2.16.0) — "Live Preview" builder meta box; AJAX-renders the real form into an
  iframe, updating ~400ms after each edit (see §3).
- **Date range field** — added 2.14.0 (two date inputs), reworked to a single flatpickr range input
  (2.16.x), then **removed in 2.17.0** (range UX wasn't reliable; flatpickr dependency dropped).
  Renderer skips any leftover `daterange` fields. Revert the removal commit to bring it back.
- **Palette icons** (2.17.1) — each field type shows a distinct dashicon in the builder palette and
  on each field card's type badge.
- **Slider readout** (2.18.x) — value shown in a bubble by default; opt-in `value_inline` puts it in
  the label; optional `prefix`/`suffix` (spaces preserved) and `show_scale` min/max captions.
- **Code Snippets** (2.19.0) — `td_snippet` CPT: inject custom HTML/JS at a chosen location with
  page/URL conditions (see §4). 2.20.0: HTML/JS **type** dropdown (JS auto-wrapped in `<script>`),
  and the body location is **before** `</body>`.
- **Capability fix** (2.19.2) — Settings + Code Snippets menus use `edit_pages` (not
  `manage_options`) to match the rest of the TD menu (see gotcha in §6).
- **"Edit form" front-end link** (added earlier; 2.21.1 restyled) — small pill above each form with
  the Terrific "t" brand mark, linking to the editor; editors only (see §4).

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
- **Capability — use `edit_pages`, not `manage_options`.** The test site's administrator account
  resolves `manage_options` → **NO** (the role lists it, but it's denied at the user level), so any
  menu/cap gated on `manage_options` is invisible to it. The whole TD plugin therefore gates admin
  features on `edit_pages` (Forms/Submissions/Settings/Code Snippets, the `td_snippet` CPT, and the
  Settings `option_page_capability_*` save cap). Don't introduce `manage_options` checks.
- **Code Snippets injection** is done with a single output buffer on `template_redirect` (not
  hooks) so it can hit positions WordPress has no hook for ("after `<head>`", "before `</body>`").
  It string-splices into the final HTML and is skipped for admin/ajax/REST/feed/embed and
  non-HTML responses. Snippet code is stored & output **raw** (JS type wrapped in `<script>`).
- **`number_format_i18n` / `wp_date`** for human-facing numbers/dates (slider values, dashboard
  "Updated"). Slider `prefix`/`suffix` are sanitized with `wp_kses(...,array())` (not
  `sanitize_text_field`, which trims a leading space like in `" days"`).

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

- **Conditional logic** (show/hide fields based on other answers).
- More **action types** (send email, Slack/Teams notification).
- **Submissions filtering** by status (partial/completed) + date range; partial-lead nurturing.
- **Code Snippets** niceties: ordering/priority, a "Snippets" dashboard count is done — consider
  device/role conditions, and a safer cap option for multi-editor sites (currently `edit_pages`).
- Re-introduce **date range** with a more reliable picker (was removed in 2.17.0).
- Package/distribute for reuse across other client sites (already generic).
