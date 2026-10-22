# Travel by Luxe plugin — Claude / Cursor project instructions

Read [PROJECT.md](PROJECT.md) before changing anything. This file is the working policy.

## Work policy

Do the work yourself with `Read`, `Edit`, `Write`, `Grep`, `Glob`, `Bash`. No sub-agents
unless the user asks. There is no automated test suite and PHP is usually not installed
locally: `node --check assets/*.js` for JavaScript; PHP is linted by the Release workflow
(`php -l`), so keep PHP edits small and re-read them.

## Hard rules

1. **Keep every internal symbol from Terrific Digital.** Class names `TD_*` / `TF_*`,
   functions `tf_*`, constants `TD_VERSION` / `TD_MENU` / `TF_CPT`, post types
   `terrific_form` / `td_faq` / `td_snippet`, tables `{prefix}td_*`, options `tf_settings` /
   `td_*`, AJAX actions `tf_submit` / `tf_step` / `tf_geo` / `tf_nonce`, hooks
   `terrific_forms/*`, the JS global `TF_FORM`, the `tf-*` CSS classes, and the dataLayer
   value `form_type: 'Terrific Form'`. The lead-routing module, the leads app's payload
   mapping and the GTM container all depend on them. Rename user-facing labels only.
2. **Field keys the leads app reads are fixed:** `full_name, email, phone,
   preferred_start_date, trip_length, adults, children, destinations, travel_style, pace,
   accommodation, budget` (plus `trip_country`, `trip_country_code`, `destinations_other`,
   `notes`, `consent`, `budget_label`). Changing one breaks ticket creation.
3. **Never push to `upstream`** (`terrific-digital/wordpress-plugin`) or to
   `Travel-by-Luxe/wp-lead-routing-plugin`. Cherry-pick from upstream by hand.
4. **Bump `TD_VERSION` and the `Version:` header together** on every release; the workflow
   refuses a mismatch and `TD_VERSION` busts the asset cache.
5. **No secrets in the repo.** It is public. Hostnames, keys and paths go in
   `PROJECT.local.md` (gitignored).
6. **Capabilities use `edit_pages`, not `manage_options`** (see PROJECT.md gotchas).

## Where things are

| Area | Files |
|---|---|
| Bootstrap, constants, helpers | `travelbyluxe.php` |
| Forms: builder / renderer / submissions / store | `includes/class-tf-*.php`, `assets/builder.js`, `assets/form.js`, `assets/form.css` |
| Travel by Luxe field types | `includes/tbl-fields.php` (data + helpers), `assets/tbl-fields.js` (behaviour), `assets/tbl-theme.css` (theme, wrapper class `tbl-lux`) |
| Lead routing | `includes/lead-routing/class-tbl-lr-*.php`, `assets/tbl-lead-routing.{js,css}` |
| FAQ, snippets, Calendly, tracking, session, UPD | `includes/class-td-*.php` |
| Admin menu, dashboard, Notes | `includes/class-td-menu.php`, `includes/class-td-notes.php` (keep Notes current by hand) |
| Release | `.github/workflows/release.yml`, [INSTALL.md](INSTALL.md) |

The site that embeds these forms lives in the `travel-by-luxe` repo (`TBL-SEO/`), which keeps
the form definitions as JSON under `TBL-SEO/site/forms/` and deploys to the Cloudways
**staging** app only.
