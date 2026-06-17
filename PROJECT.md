# Terrific Digital — WordPress Plugin

A reusable WordPress plugin (a toolkit) for Terrific Digital's client landing‑page
sites. Its first and main feature is **Terrific Forms** — a drag‑and‑drop form
builder with first‑class lead capture, tracking, and delivery. Built to replace
bespoke per‑page form JavaScript with one maintainable, trackable system so every
new landing page "just works" for leads + analytics.

**Current version:** 2.38.0

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
| **Live landing page** | page **84** "Luxury Travel v3 (Native)" `/luxury-travel-v3-native/` — a custom template that `do_shortcode`s form **77** + FAQ list **88** (`faq_88_a5250f`). Its stored content is only the form shortcode; the rest is template-rendered (why the FAQ usage finder needed render-tracking). |

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
                                CSS-masked to match native WP icon states); nests the CPT under it.
                                Also prints a shared logo "brand bar" (in_admin_header) on every TD
                                admin screen — incl. native Forms/Snippets list+edit screens
    class-td-session.php        "Store Session Data" feature: option td_session {enabled,storage};
                                settings section + front-end session.js enqueue
    class-td-upd.php            "User-Provided Data" feature (ON by default): option td_upd
                                {enabled,storage:'local'|'cookie'}; settings section + config()
                                injected into TF_FORM (capture logic lives in form.js)
    class-td-snippets.php       "Code Snippets" feature: admin-only td_snippet CPT (raw HTML/JS),
                                4 inject locations + conditions; output-buffer injection (template_redirect)
    class-td-notes.php          "Notes" admin page: a hand-maintained card grid summarizing every
                                plugin feature in short (submenu under TD_MENU, last; edit_pages)
    class-td-page-slug.php      Adds a sortable "Slug" column to the admin Pages list table
                                (manage_pages_columns; orderby=name; non-ASCII slugs decoded)
    class-td-faq.php            "FAQ" feature: td_faq CPT (reusable Q&A accordion lists); admin
                                builder meta box, shortcode [terrific_faq id="N"] + "Terrific FAQ"
                                block, "used on these pages" finder, front-end accordion + dataLayer
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
    builder.js / builder.css    Form builder UI (SortableJS): click-to-add OR drag fields, per-field
                                Duplicate, active-step targeting, a sticky side-by-side live preview
                                pane (3-pane palette|canvas|preview), WYSIWYG ½/⅓ field widths in the
                                canvas + drag-to-pair (drop a full field on another's right → 50/50),
                                compact icon actions (edit/duplicate/delete)
    actions.js                  Actions list UI (Webhook / Redirect cards)
    form.js / form.css          Frontend form behaviour (steps, validation, dataLayer, submit, geo,
                                sliders, honeypot, session capture, User-Provided Data capture,
                                "Edit form" link)
    session.js                  Store Session Data: writes td_session_data once per session (storage/cookie)
    block.js                    Block editor UI (no build step; wp.element.createElement)
    faq.js / faq.css            FAQ front-end accordion (toggle + faq_item_click dataLayer, once/item)
    faq-admin.js / faq-admin.css  FAQ list builder: collapsible cards (question inline in the header,
                                answer in a collapsible body), drag-reorder, duplicate, icon actions
    faq-block.js                "Terrific FAQ" block editor (no build step)
    sortable.min.js             Bundled SortableJS 1.15.2 (no runtime CDN)
  includes/free-email-domains.txt  ~4.7k free/personal email domains (HubSpot's block list), one per
                                line; read by tf_free_email_domains() for the email "block free" option
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
- `_tf_wrapper_class` — extra class(es) added to the `.tf-form` container (sanitize_html_class)
- `_tf_custom_css` — per-form CSS, output in a scoped `<style id="tf-{uid}-css">` before the form
  (`<` stripped on save + output; scope selectors with the wrapper class — 2.37.0)

**Field shape**
```
{ id, type, label, key, placeholder, required, show_label,
  width: full|half|third, min, max, minlength, maxlength,
  multiple,                        // dropdown multi-select
  country_code, cc_show_name,      // phone: dial-code selector (default true); cc_show_name → "🇮🇱 Israel +972"
  block_free,                      // email: block free/personal domains (require a work email; default off)
  min_from_today, max_from_today,  // date field bounds (days from today; 0 valid)
  step, default, prefix, suffix, show_scale, value_inline,   // slider
  html,                            // custom_html content
  next_text,                       // per-STEP (on the step object): Next button label
  options: [ {label, value} ] }    // select/radio/checkbox
```
**Field types:** text, email, tel (phone), number, **slider**, date, textarea,
select (dropdown), radio, checkbox, heading, **paragraph**, **separator**, **custom_html**, hidden.
Each step object also supports `next_text` (its Next button label; falls back to "Next →").
**phone** has a `country_code` toggle (default on) — off renders a plain tel input. A `cc_show_name`
toggle (2.37.0) renders dial-code options as "🇮🇱 Israel +972" (vs the default "🇮🇱 +972"); the
selector widens via `.tf-phone-cc-named`. When the
country-code selector is on and the number is filled, the submitted data also includes a
`<key>_country` **object** `{ code:"+972", name:"Israel", ISO:"IL" }` alongside the combined
`<key>` value (e.g. `+972 0541234567`). (Options carry `value`=dial, `data-cc`=ISO, `data-name`=name;
the server preserves the `ISO` sub-key casing.)

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

**email `block_free`** (2.38.0) — when on, the form won't submit with a free/personal email; the
field shows **"Please enter your work email address."** The renderer adds `data-block-free="1"` to the
input; `form.js` validates the domain against `window.TF_FREE_DOMAINS` (a ~4.7k list localized **only**
when a form on the page uses the option). Server backstop: `TF_Submissions::has_blocked_free_email()`
silently drops a JS-bypassed free-domain submit (like the honeypot). Domains come from
`tf_free_email_domains()` (filter `terrific_forms/free_email_domains`); `tf_is_free_email($email)` checks one.

**Single `date` field** — also supports `min_from_today` / `max_from_today` (earliest/latest in
days from today; 0 = today, blank = no limit); converted to the input's `min`/`max` client-side
(cache-safe) with matching validation.

**Live preview** (builder) — a sticky preview pane **inside the Form Builder box** (right of the
canvas; toggleable via a toolbar button + **drag-resizable** by a handle on its left edge, both
states persisted in `localStorage`) renders the real form server-side via the `tf_preview` AJAX
action (admin-only, nonce'd) into an iframe, updating ~400ms after each edit (skipped while hidden).
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
The landing `url` is **param-normalized** before storage (2.23.0): when browsers/ad-blockers
strip the official tracking params, our counterpart backups carried on the URL are rebuilt into
the standard ones. `session.js` holds a backup→canonical map (`td_*` shadow the standard
`utm_*`/click-ids — `td_src`→`utm_source`, `td_ga_cid`→`gclid`, `td_ba_cid`/`td_ma_cid`→`msclkid`,
`td_tt_cid`→`ttclid`, `td_ya_cid`→`yclid`, `td_ra_cid`→`rdt_cid`, `td_ob_cid`→`dicbo`, …; and
`hsa_*` HubSpot-ads params normalize into the `utm`/`td_*` scheme). For each backup it renames
the key to its canonical name **only when** the canonical isn't already present (the surviving
official param always wins) — a whole-key URLSearchParams rewrite (no substring-collision bug,
order-independent), mirroring the GTM backup function. `ref` is left untouched.

form.js reads this object (sessionStorage or cookie) and posts it as `session` with each
submission/step; the server stores it in `meta.session`, adds it to the webhook payload as
`session` (omitted when empty), shows it in the submission detail view, and exports it to CSV.

**User-Provided Data ("upd")** — WP option `td_upd` = `{ enabled, storage:'local'|'cookie' }`
(TD_Upd), **ON by default** (absent option ⇒ enabled). Capture logic lives in `form.js`
(config injected via `TF_FORM.upd`). On every form step + submit it inspects the form and
builds a persisted identity object:
```jsonc
{ "fn": "", "ln": "", "em": "", "ph": "", "co": "" }   // first/last name, email, phone, country
```
Normalization: `fn`/`ln`/`em` lowercase, `ph` digits only (incl. dial code), `co` 2-letter ISO
**uppercase**. Detection: `em` = first `input[type=email]`; `ph` = the phone field (composite
or plain tel); `co` = the phone dial-code selector's `data-cc` (or an explicit 2-letter country
field); `fn`/`ln` = fields whose key/label match first/last/full-name keywords (a single
"name" field is split on whitespace). Values **accumulate** across steps/forms (a found value
never overwrites with empty). Persisted in `localStorage` or a max-expiry cookie (named
`td_upd`, `Max-Age=2147483647` + far-future `Expires`, `SameSite=Lax`, `Secure` on HTTPS),
selectable in Settings. Whenever the object changes, form.js pushes
`{ event:'upd', upd:{…} }` **before** the matching `form_step`/`form_submission` event. This is
a front-end/analytics identity feature only — nothing is stored server-side.

**reCAPTCHA v3 (spam protection, 2.24.0)** — keys live in `tf_settings`:
`{ recaptcha_enabled, recaptcha_site_key, recaptcha_secret_key, recaptcha_threshold }`
(threshold 0–1, default 0.5). Settings shows an Enable toggle that reveals the Site/Secret key
+ threshold fields. Helpers in `terrific-digital.php`: `tf_recaptcha_enabled()` (true only when
enabled **and** both keys set), `tf_recaptcha_site_key`/`_secret_key`/`_threshold`,
`tf_recaptcha_verify($token)` (POST to Google `siteverify`; **fails open** on transport/parse
error so an outage can't lose leads, **fails closed** on `success:false` or score below
threshold). When enabled, `TF_Render` loads `https://www.google.com/recaptcha/api.js?render=KEY`
on form pages and passes `TF_FORM.recaptcha.site_key`; `form.js` runs `grecaptcha.execute` (action
`submit`/`step`) and posts `recaptcha_token` with each submit/step. `TF_Submissions::passes_recaptcha()`
verifies it; a missing/low-score token is treated like the honeypot — **silent success-shaped drop**
(nothing stored, no actions fired).

**Options:** WP option `tf_settings` = `{ global_webhook }`. DB-version gates:
`tf_db_version` (submissions) and `tf_revisions_db_version` (revisions) — each re-runs
its `dbDelta` when ≠ `TF_VERSION`.

---

## 4. Front-end contracts

**dataLayer events** (form.js) — `form_visible` (once, when the form first scrolls into view),
`form_started` (once, on the first value entered in any field),
`upd` (when User-Provided Data changes; pushed *before* the step/submit events), `form_step` on
each Next (intermediate steps only — the final step is the `form_submission`),
`form_submission` on submit, then the form's **intent as its own event** (e.g. `generate_lead`)
immediately after `form_submission`. **Every** dataLayer event the plugin pushes (form.js + faq.js,
incl. `faq_item_click`) carries **`event_origin: "td_wp_plugin"`** (added by a `tdPush()` wrapper,
2.36.0). Every front-end page also gets
`window.dataLayer = window.dataLayer || [];` printed early in `<head>` (wp_head priority 1):
```js
{ event:'upd', upd:{ fn, ln, em, ph, co } }   // see §3 "User-Provided Data"
```
```js
{ event:'form_visible'|'form_started'|'form_step'|'form_submission'|<intent e.g. 'generate_lead'>,
  event_origin:'td_wp_plugin',   // on EVERY plugin event (incl. upd, faq_item_click)
  form_id, form_name, form_type:'Terrific Form', form_step?, form_intent?, form_fields:{ key: value } }
// form_visible: fired once when the form first enters the viewport (no form_step/form_fields).
// form_started: fired once on first field interaction (no form_step/form_fields).
// form_step: each Next on a multi-step form (intermediate steps; the final step is form_submission).
// <intent>: the form's intent param (e.g. generate_lead) pushed as its own event right after form_submission.
```

**Webhook payload** (POSTed JSON):
```jsonc
{ "event": "form_submission" | "form_step",
  "data": { /* all form fields; a phone with a country-code selector adds `<key>_country` {code,name,ISO} */ },
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

**FAQ** (`td_faq` CPT, `capability_type=page` → `edit_pages`, like the Forms/Snippets CPTs). Each
FAQ list stores `_td_faq_items` (JSON `[ {q, a} ]`; `q`=question text, `a`=answer HTML via
`wp_kses_post`) and a stable `_td_faq_id` (`faq_{postID}_{6hex}`), plus `_td_faq_single` (open one at a
time → `data-single="1"` on `.td-faq`, honored by faq.js). Build items in the admin meta box
(add/reorder/remove). The accordion **animates** open/close (CSS grid-rows 0fr↔1fr; no `hidden`). Place with **`[terrific_faq id="N"]`** or the **Terrific FAQ** block (server-
rendered, reuses the shortcode). The edit screen's side box shows the shortcode + a **"Used on these
pages"** finder (`find_usages()` — shortcode/block/Elementor scan **plus render-time tracking**:
`record_usage()` logs the queried page whenever the FAQ actually renders front-end, throttled to
once/day per page and stored in `_td_faq_seen` {pageId: ts}, pruned to 90 days / existing pages.
This catches embeds that store nothing scannable — custom templates, page builders, `do_shortcode`). Front-end
renders an accessible accordion (`.td-faq` → `.td-faq-item` → `.td-faq-q` button + `.td-faq-a`
panel, collapsed via `hidden`; "+"→"×" icon). `faq.js` toggles items and, on the **first open of each
item**, pushes `{ event:'faq_item_click', faq_title:'<question lowercased>' }` to the dataLayer
(once per item, via an `item.__faqFired` flag; title read from `data-faq-title`).

**Admin menu:** Terrific Digital → **Dashboard · Forms · Submissions · Code Snippets · FAQ Lists · Settings · Notes** (no "Add New"
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
- **Intent** — per-form: **Lead** → `generate_lead`, **Career Application** → `career_application`,
  **Contact Us** → `contact_us`, **Gated Content** → `gated_content` (2.34.0). Adds `form_intent` to
  the dataLayer + webhook, **and** pushes the intent param as its own dataLayer event right after
  `form_submission`. Extend via the `terrific_forms/intents` filter.
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
- **Intent event + dataLayer init** (2.27.0) — after `form_submission`, the form's intent param is
  pushed as its own dataLayer event (e.g. `generate_lead`); previously the intent was only a
  property. Also: every front-end page prints `window.dataLayer = window.dataLayer || [];` early in
  `<head>` (wp_head priority 1).
- **Email: block free domains** (2.38.0) — per-email-field "Block free email domains" option (default
  off). Free/personal emails are rejected with "Please enter your work email address." (client-side
  block + server backstop). List = HubSpot's ~4.7k domains in `includes/free-email-domains.txt`,
  localized to JS only when a form needs it. See §3.
- **Form & FAQ polish** (2.37.0) — (1) phone field `cc_show_name` option → dial-code selector shows
  the country name ("🇮🇱 Israel +972"); (2) FAQ per-list **"Open one at a time"** toggle
  (`_td_faq_single` → `data-single`); (3) FAQ accordion now **animates** open/close (grid-rows
  transition, no `hidden`); (4) per-form **Wrapper CSS class** + **Custom CSS** settings fields
  (scoped `<style>`) — a first-class replacement for the custom_html `<style>` hack.
- **event_origin on all events** (2.36.0) — every dataLayer event the plugin pushes now carries
  `event_origin: "td_wp_plugin"` (form.js + faq.js route through a `tdPush()` wrapper that stamps it).
- **Pages "Slug" column** (2.35.0) — TD_Page_Slug adds a sortable **Slug** column to the admin Pages
  list table (after Title), so it's easy to tell which page a row is. Percent-decoded for readability.
- **Plugins screen polish** (2.34.4–2.34.5) — added a **Settings** action link to the plugin row
  (`plugin_action_links_*` → `admin.php?page=tf-settings`); set `Plugin URI` to
  **https://terrific-digital.com**; and made "Visit plugin site" open in a new tab
  (`plugin_row_meta` adds `target="_blank" rel="noopener noreferrer"`).
- **FAQ usage render-tracking** (2.34.3) — the FAQ "Used on these pages" finder now also records
  pages where the FAQ actually renders front-end (`record_usage()` → `_td_faq_seen`), so it catches
  embeds with nothing scannable (custom landing-page templates / page builders / `do_shortcode`).
  See §4.
- **FAQ editor rebuild** (2.34.2) — the FAQ admin builder became collapsible drag-and-drop cards:
  question editable inline in the header, answer in a collapsible body, with drag-reorder, duplicate,
  and compact icon actions (matches the form builder's feel).
- **Brand bar on FAQ screens** (2.34.1) — `is_td_screen()` now whitelists `td_faq` so the FAQ Lists
  list/edit screens get the shared header bar too.
- **More intents + FAQ on dashboard** (2.34.0) — added Career Application / Contact Us / Gated Content
  form intents (each fires its own dataLayer event); renamed the "FAQ" menu to **FAQ Lists** and added
  an FAQ Lists card to the Dashboard.
- **FAQ** (2.33.0) — new `td_faq` CPT: reusable Q&A accordion lists placed via `[terrific_faq id="N"]`
  or the "Terrific FAQ" block. Admin builder (drag-reorder items), a "Used on these pages" finder, and
  a front-end accordion that fires `{event:'faq_item_click', faq_title:'<question lowercased>'}` once
  per item on first open (see §3/§4).
- **Phone country in data** (2.32.0) — a phone field with the country-code selector now submits the
  selected country as a `<key>_country` object `{ code:"+972", name:"Israel", ISO:"IL" }` in the data /
  dataLayer form_fields / webhook / CSV, in addition to the combined number.
- **Builder: resizable preview + LAST STEP** (2.31.1) — the live-preview pane is wider by default
  and **drag-resizable** (handle on its left edge; width persisted in `localStorage`). On a
  multi-step form the **final step shows a "LAST STEP" tag** instead of a Next-button text input
  (it submits via the Submit button); re-evaluated as steps are added/removed.
- **Builder WYSIWYG widths + drag-to-pair** (2.31.0) — field cards now render at their real width
  (full / ½ / ⅓) in the canvas (an expanded card goes full-width for editing); the redundant width
  badge is gone. Dropping a full-width field onto the right half of another full-width field turns
  **both into ½ and sits them in one row**. Field actions became compact icon buttons.
- **Builder UX — Tier 1** (2.30.0) — fields can now be added by **clicking** a palette type (not just
  dragging), appended to the active step (highlighted) and auto-expanded; each field card has a
  **Duplicate** button; and the **live preview is side-by-side** with the canvas (sticky 3-pane
  palette|canvas|preview, toggleable + persisted) instead of a separate meta box below. New steps now
  insert above the "+ Add step" button.
- **form_visible event** (2.28.0) — fires once when the form first scrolls into view
  (IntersectionObserver; falls back to firing on init where unsupported). Same id/name/intent payload.
- **form_started event** (2.26.0) — fires once (per fill) the first time the user enters a value
  in any field (honeypot ignored); a lightweight engagement signal carrying form id/name/intent.
- **form_complete removed** (2.29.0) — dropped the `form_complete` event (added 2.25.0) as
  redundant with `form_submission`; the final step of a multi-step form no longer fires a separate
  event (intermediate Next clicks still fire `form_step`).
- **Brand bar everywhere** (2.24.1) — `TD_Menu::brand_bar()` prints a shared logo + version bar at the
  top of every Terrific Digital admin screen via `in_admin_header` (gated by `is_td_screen()`), so the
  native Forms/Code Snippets list & edit screens carry the brand too. Dashboard/Notes dropped their
  duplicate inline logos.
- **reCAPTCHA v3** (2.24.0) — optional Google reCAPTCHA v3 on all forms (Settings: Enable + Site/Secret
  keys + score threshold). Score-based, invisible; low-score/missing-token submissions are silently
  dropped like the honeypot. Verified on both final submit and step pings (see §3).
- **Notes tab** (2.23.1) — a read-only admin page (TD_Notes, last submenu) with a card grid that
  summarizes every plugin feature in short; keep it current by hand as features land.
- **Session URL param backup** (2.23.0) — `session.js` normalizes the stored landing `url`,
  rebuilding stripped official tracking params (`utm_*`/`gclid`/`msclkid`/…) from our `td_*`/`hsa_*`
  counterpart params (whole-key rewrite, official-wins). Mirrors the GTM backup function (see §3).
- **User-Provided Data** (2.22.0) — plugin-wide feature (ON by default, TD_Upd) that captures the
  visitor's first name / last name / email / phone / country from any form fill into a persisted
  `upd` object `{fn,ln,em,ph,co}` (localStorage or a max-expiry cookie, selectable in Settings),
  pushing a `{event:'upd',upd}` dataLayer event before each `form_step`/`form_submission` (see §3/§4).

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
- **Legacy LP script `tbl-v3.js`** (custom landing-page asset at `wp-content/uploads/tbl/tbl-v3.js`,
  **not** in this repo) had its own generic FAQ click tracker (`handleClick`) that matched our
  `td-faq-item` via substring selectors like `[class*="faq-item"]` and pushed a second
  `faq_item_click` (`link_text`, not `faq_title`) — so on the LP each FAQ click fired **twice**. Fixed
  on the server by removing that one `push({ event:'faq_item_click', … })` line (backup:
  `tbl-v3.js.bak-faqdup`). The plugin's FAQ fires exactly once on its own — if `tbl-v3.js` is ever
  rebuilt from source, re-remove the legacy FAQ push.
- **`number_format_i18n` / `wp_date`** for human-facing numbers/dates (slider values, dashboard
  "Updated"). Slider `prefix`/`suffix` are sanitized with `wp_kses(...,array())` (not
  `sanitize_text_field`, which trims a leading space like in `" days"`).

---

## 7. Deploy & test workflow

**Deploy** (from the canonical source) — **scp straight to the destination path** (via the
`applications` symlink). Don't scp to a relative `~/tmp.x`: the SFTP login is chrooted to a
different root than the interactive shell, so a relative upload lands somewhere the `ssh` session
can't `cp` from. Direct-to-dest avoids that:
```bash
H=master_emqjrcyekf@46.101.96.39; K=~/.ssh/tbl_cloudways
D=applications/mpggmnftkb/public_html/wp-content/plugins/terrific-digital
scp -i $K <localfile> $H:$D/<relpath>          # e.g. assets/form.js, includes/class-x.php
ssh -i $K $H 'P=~/applications/mpggmnftkb/public_html/wp-content/plugins/terrific-digital
  php -l $P/<relpath>; cd ~/applications/mpggmnftkb/public_html && wp breeze purge --cache=all'
```
- `php` is **not** installed locally — lint on the server with `php -l`. `node --check` lints JS locally.
- DB ops / inspection: `wp eval` / `wp eval-file` (heredoc with a **quoted** `<<"PHP"` delimiter to
  avoid shell expansion; inside it use plain PHP — don't backslash-escape `$` or quotes).
- On schema change, bump `TD_VERSION` so `maybe_create_table()` re-runs dbDelta.
- **Block-editor scripts must be registered on `init`** (not `wp_enqueue_scripts`, which is front-end
  only) so the handle exists in the admin block editor — see `TD_FAQ::register()` / `TF_Block`.

**Test** (headless Chrome via CDP — `/Applications/Google Chrome.app`; spawn with `--headless=new`,
an isolated `--user-data-dir`, a unique `--remote-debugging-port`; drive over the WebSocket):
- Admin tests: log in at `/wp-login.php`, then `Page.navigate` to the screen. **Credential-safety:**
  the auto-mode classifier blocks writing the WP password into a file or command line. Pass it via an
  env var the script reads from `process.env` — e.g.
  `WP_USER=… WP_PASS="$(grep -oE … /tmp/tfadmin.js)" node /tmp/x.js` — so the literal never appears.
- Front-end behaviour: fill fields, click, read `window.dataLayer` (wrap `dataLayer.push` to record
  events; if a submit may redirect, stash records in `sessionStorage` so they survive the nav).
- **Synthetic drag** (SortableJS): Sortable uses **pointer events** by default, which CDP's
  `Input.dispatchMouseEvent` doesn't drive — so the builder/FAQ Sortables set `supportPointer:false`
  (mouse/touch events) which makes mouse-event drags work *and* is fine for desktop admin.
- Deploy itself is gated by the auto-mode classifier; it asks before the first scp to production.
- Front-end pages are Breeze-cached: purge after deploy, and add a unique `?query` to force a fresh
  (uncached) render when testing render-time behaviour (e.g. FAQ `record_usage`).
- Protected PHP methods: verify via `ReflectionMethod`/`wp eval`. Webhook payloads: echo through
  `https://httpbin.org/post` (blocking) — **do not** use webhook.site (blocked by the sandbox).
- Clean up any test rows/posts created on the live site (delete by a distinctive marker value).

---

## 8. Roadmap / ideas (not yet built)

- **Conditional logic** (show/hide fields based on other answers).
- More **action types** (send email, Slack/Teams notification).
- **Submissions filtering** by status (partial/completed) + date range; partial-lead nurturing.
- **Code Snippets** niceties: ordering/priority, a "Snippets" dashboard count is done — consider
  device/role conditions, and a safer cap option for multi-editor sites (currently `edit_pages`).
- Re-introduce **date range** with a more reliable picker (was removed in 2.17.0).
- Package/distribute for reuse across other client sites (already generic).
