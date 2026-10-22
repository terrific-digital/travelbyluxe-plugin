# Travel by Luxe — WordPress plugin (developer notes)

The site toolkit for travelbyluxe.com. Forked from the **Terrific Digital** plugin
(`terrific-digital/wordpress-plugin`, v2.51.0, 12 Oct 2026) and merged with the
**TBL Lead Routing** add-on (`Travel-by-Luxe/wp-lead-routing-plugin`, v1.9.1), then extended
with the field types the site's inquiry form needs. The user-facing guide is README.md; the
working rules are CLAUDE.md. This file is left out of the release ZIP.

> 🔒 **This repo is public: never commit hostnames, IPs, usernames, paths to credential
> files, or credentials. Keep them in `PROJECT.local.md`** (gitignored).

**Current version:** 3.0.0

For the long-form history of every upstream feature (2.x), read the upstream
[PROJECT.md](https://github.com/terrific-digital/wordpress-plugin/blob/main/PROJECT.md) —
it still describes this code base accurately wherever this file does not say otherwise.

---

## 1. What was kept, what changed

**Kept on purpose — do not rename:** every internal symbol of Terrific Digital. PHP classes
`TD_*` / `TF_*`, functions `tf_*`, constants `TD_VERSION` / `TD_MENU` (`'terrific-digital'`) /
`TF_CPT` (`'terrific_form'`), post types `td_faq` / `td_snippet`, tables `{prefix}td_forms`,
`td_form_submissions`, `td_form_revisions`, `td_faqs`, `td_faq_lists`, `td_snippets`,
`td_code_snippets`, `td_settings`, options `tf_settings` / `td_*` / `tbl_lr_*`, AJAX actions
`tf_submit` / `tf_step` / `tf_geo` / `tf_nonce` / `tf_preview` / `tf_flag`, hooks
`terrific_forms/*` and `terrific_digital/settings_fields`, the JS global `TF_FORM`, the `tf-*`
CSS classes, block names `terrific-digital/form|faq`, the REST namespace `tbl-lead-routing/v1`,
and the dataLayer value `form_type: 'Terrific Form'`. The lead-routing module, the leads app's
payload mapping and the GTM container key on them. A site that had Terrific Digital installed
upgrades in place — same tables, same options.

**Changed:** plugin folder/file `travelbyluxe/travelbyluxe.php`, header, text domain
`travelbyluxe`, update source (this repo's releases, `travelbyluxe.zip`), admin label
"Travel by Luxe" + `assets/tbl-logo.svg` + an italic "L" menu mark, shortcodes
**`[tbl_form]` / `[tbl_faq]`** (old names stay as aliases; the usage finders match both),
the Dashboard / Notes copy, and `Requires at least 6.5` / `Requires PHP 7.4` (the routing
module's floor).

**Added:**
- `includes/lead-routing/` — the add-on's seven classes, unchanged apart from: no
  "is Terrific Digital active?" checks or tested-version banner (`tbl_lr_forms_available()`
  returns true, `tbl_lr_td_version()` returns `TD_VERSION`), the menu/box label "Lead
  Routing", REST `/status` without `tested_td_version`, `$GLOBALS['tbl_lr_updater']` pointing at
  this plugin's updater so `POST /update` still works. Bootstrapped from `travelbyluxe.php`
  after `TF_Submissions::init()`.
- Travel by Luxe field types and options (§4) — `includes/tbl-fields.php`,
  `assets/tbl-fields.js`, `assets/tbl-theme.css`, plus small changes in
  `class-tf-builder.php::sanitize_def()`, `class-tf-render.php::field()`, `assets/builder.js`,
  `assets/form.js`.
- The **Thank-you summary** action (`type: 'handoff'`) — see §4.
- Shortcode attributes `country`, `countries`, `class`.

---

## 2. File map

```
travelbyluxe/
  travelbyluxe.php              Bootstrap: constants (TD_*, TF_*, TBL_LR_*), requires, init(), tf_* helpers,
                                tbl_lr_forms_available() / tbl_lr_td_version()
  includes/
    class-td-menu.php           "Travel by Luxe" admin menu, brand bar, Dashboard (incl. Lead Routing card)
    class-td-notes.php          Notes screen — hand-maintained feature summary; keep it current
    class-td-{session,session-duration,upd,snippets,faq,faq-store,snippet-store,settings-store,
              calendly,link-tracking,page-slug}.php   — as upstream
    class-tf-{settings,cpt,builder,render,submissions,list-table,revisions,block,import-export,forms}.php
                                Forms engine — as upstream plus the TBL additions marked "Travel by Luxe"
    tbl-fields.php              TBL data + helpers: tbl_destinations(), open pills, curated dial codes,
                                consent HTML allowlist, budget defaults/settings, tf_form_has_handoff()
    lead-routing/class-tbl-lr-{settings,forms,client,submit,frontend,rest,lead-links}.php
    free-email-domains.txt
  assets/
    builder.js / builder.css    Admin builder (+ TBL palette entries, option table with description /
                                Excl. / Text columns, per-type settings via .tf-b-prop[data-prop])
    actions.js                  Actions UI (+ Thank-you summary)
    form.js / form.css          Front-end engine (+ consent/max-label reading, tf:step event, handoff())
    tbl-fields.js               Countries picker, destination pills, budget floor, pill/card rules,
                                collapsed textarea, {country} templates
    tbl-theme.css               The site's petrol/gold form theme, scoped to .tf-form.tbl-lux
    tbl-lead-routing.js / .css  Lead routing: "Please wait" overlay, popup, thank-you calendar
    faq*.js/css, block.js, calendly.js, link-tracking.js, session*.js, submissions-admin.*, sortable.min.js
    tbl-logo.svg, td-logo.svg
  vendor/plugin-update-checker  PUC v5 (MIT)
```

---

## 3. Data model

Unchanged from upstream (see its PROJECT.md §3): forms in `{prefix}td_forms` (definition JSON,
intent, submit text, success message, wrapper class, custom CSS, actions JSON), submissions in
`td_form_submissions`, revisions, FAQ / snippet / settings stores, options for session / UPD /
reCAPTCHA. Lead routing adds post meta `_tbl_lr` per form and options `tbl_lr_settings`,
`tbl_lr_last`, `tbl_lr_log`, `tbl_lr_lead_links`.

**Field shape — TBL additions** (all sanitized in `TF_Builder::sanitize_def()`, blank = default):

| Prop | Types | Meaning |
|---|---|---|
| `style` | radio, checkbox | `list` \| `pills` \| `cards` |
| option `description`, `exclusive`, `reveals` | radio, checkbox | cards text; "Not sure yet" clears the rest; "Somewhere else" reveals a text box submitted as `<key>_other` |
| `collapsed`, `trigger_text` | textarea | hidden behind a button until clicked |
| `max_label` | slider | shown **and submitted** at the maximum ("10+") |
| `countries` | tel | `all` \| `curated` (the site's 33 dial codes) |
| `max_countries` | countries | 0/blank = no limit |
| `countries_key` | destinations | which field holds the countries (default `trip_country`) |
| `currency`, `rate_adult`, `rate_child`, `rate_adult_day`, `rate_child_day`, `included_days`, `range_mult`, `step`, `country_rates`, `adults_key`, `children_key`, `days_key`, `country_key` | budget | see `tbl_budget_defaults()` |

**New field types:** `consent` (one tick box, label HTML through `tbl_consent_allowed_html()`,
submits `yes`), `countries` (submits names under its key and ISO codes under `<key>_code`),
`destinations` (array of regions + `<key>_other`), `budget` (number + `<key>_label`, `<key>_min`,
`<key>_at_min`).

**Actions:** `webhook`, `redirect`, `datalayer` (repeatable), `handoff` (singleton, no config).

---

## 4. Front-end contracts

**dataLayer events, webhook payload, AJAX endpoints** — exactly as upstream §4
(`form_visible`, `form_started`, `upd`, `form_step`, `form_submission` + the intent event,
custom DataLayer Event actions; payload `{ event, data, form, submission, device, cookies,
session }`; `tf_submit` / `tf_step` / `tf_geo` / `tf_nonce`).

**Lead routing** (unchanged from the add-on's PROJECT.md): on a routed form
`terrific_forms/step` forwards each step to the leads app without waiting, and
`terrific_forms/submission` sends the final payload, waits up to "Longest wait", stores the
lead id, and rewrites the JSON reply through an output buffer (`data.tbl_routing`, `tbl_cal`
/ `tbl_td` on the redirect). `tbl-lead-routing.js` wraps `fetch` to catch the `tf_submit`
reply, shows the overlay/popup and, on a thank-you page, mounts the designer's Calendly into
`#calendly_widget` or after the `thanks_after` selector. Only `https://calendly.com` links
reach visitors.

**Field keys the leads app reads** (`Travel-by-Luxe/leads`, `src/lib/terrific-forms.ts`):
`full_name, email, phone, preferred_start_date, trip_length, adults, children, destinations,
travel_style, pace, accommodation, budget`. The booking rule reads `budget`, `adults`,
`children`. Additive keys from the site's form: `trip_country`, `trip_country_code`,
`destinations_other`, `notes`, `consent`, `budget_label`, `budget_min`, `budget_at_min`.

**Thank-you summary** (`handoff` action): right before the redirect, `form.js` writes
`sessionStorage.tblThankYou = { type: 'inquiry' | 'partner', form_id, data: <all fields>, ts }`
(`partner` when the intent is `partnership_request`). The site's thank-you pages read it.

**Events between the two scripts:** `tf:step` (form.js → detail.step), `tf:countries`
(tbl-fields.js → detail.countries). `{country}` in a step title or label is rendered server-side
for the shortcode preset and rewritten client-side (`data-template`) when the countries change.

**Shortcode:** `[tbl_form id="N" country="Italy" countries="Italy,France" class="…"]`. The preset
is validated against `tbl_destinations()` and lands on `data-preset-countries`.

---

## 5. Conventions & gotchas

- **`wp_slash(wp_json_encode(...))`** when storing JSON in post meta; `$wpdb` writes need no slashing.
- **Bump `TD_VERSION` + the `Version:` header together** on every change (asset cache-buster;
  the release workflow refuses a mismatch). After deploy: `wp breeze purge --cache=all`.
- **Capability — `edit_pages`, never `manage_options`** (the LP site's admin account resolves
  `manage_options` to NO at the user level).
- **Geo:** `tf_geo_lookup()` → ipwho.is, transient-cached a week, honours `CF-IPCountry`.
- **Cookies:** `tf_collect_cookies()` never forwards `wordpress_*`, `wp-*`, `wp_*`, `comment_*`.
- **Partial capture** keyed by the client token `tfs_…`; `store_submission()` upserts by token.
- **Form data is not in postmeta** (since 2.48): `TF_Forms` table; import/export matches on the
  stable `form_id` (`tf_<post>_<hash>` or any string a JSON file sets).
- **The destinations map lives in two places by design** — `tbl_destinations()` (PHP, server
  render) is localized to `TF_TBL.destinations` for the client rebuild; `pillsHtml()` in
  tbl-fields.js mirrors `TF_Render::destination_pills_html()`. Change markup in both.
- **Pills/cards hide the native input** and style `.is-selected` / `:has(input:checked)`;
  `form.js` still collects from the real inputs, so the rules in tbl-fields.js only tick/untick.
- **Code Snippets inject through an output buffer** on `template_redirect`; the lead-routing
  reply rewrite also uses one (started inside the submission hook, flushed by `wp_die`).
- Never keep a webhook action on a routed form — the lead would reach Make and the leads app.

---

## 6. Release & test

- Release: see INSTALL.md (tag `vX.Y.Z` or run the workflow; it lints PHP + JS and builds
  `travelbyluxe.zip`). PHP is not installed locally: `node --check assets/*.js`, then rely on
  the workflow's `php -l`, or lint on the staging server.
- Install on the site's **staging** app from the site repo:
  `node cloudways-staging/install-travelbyluxe-plugin.mjs` (never production).
- Test end to end on staging: the inquiry form on a country page (preset country ticked,
  pills follow the countries, budget floor moves with adults/children/length/France),
  consent blocks step 1, `form_submission` + `generate_lead` fire at step 1
  (`submission_step`), a submission appears as Partial then Completed, the routed lead reaches
  the leads app (`GET /wp-json/tbl-lead-routing/v1/log`), `/thank-you/` shows the summary and
  the designer's calendar; the partner form on `/travel-agents/` routes in `send` mode.
- Upstream: `git fetch upstream` and cherry-pick by hand; never push there.
