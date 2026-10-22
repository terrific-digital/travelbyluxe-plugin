# Travel by Luxe — WordPress plugin

The site toolkit for travelbyluxe.com: **TBL Forms** (a drag-and-drop form builder with
multi-step forms, dataLayer events, submissions and **lead routing** to the Travel by Luxe
leads app), FAQ lists, code snippets, a Calendly listener and link tracking.

It is a fork of the [Terrific Digital plugin](https://github.com/terrific-digital/wordpress-plugin)
(v2.51.0) merged with the [TBL Lead Routing add-on](https://github.com/Travel-by-Luxe/wp-lead-routing-plugin)
(v1.9.1), plus the field types the site's inquiry form needs. One plugin, one install, no
dependency between the two.

## Install

Upload `travelbyluxe.zip` from the [latest release](https://github.com/terrific-digital/travelbyluxe-plugin/releases/latest)
in **Plugins → Add New → Upload Plugin**, then activate. Updates arrive through the normal
WordPress "Update available" flow (the plugin checks this repo's releases; no token needed).

If the Terrific Digital or TBL Lead Routing plugins are installed on the same site,
**deactivate them first** — this plugin contains both and declares the same classes.
Their forms, submissions, settings and routing options carry over untouched.

## Using it

- **Forms** — Travel by Luxe → Forms. Place one with `[tbl_form id="N"]` (or the "TBL Form"
  block). `[terrific_form id="N"]` still works.
  - `country="Italy"` or `countries="Italy,France"` on the shortcode pre-ticks the page's
    countries for the Countries / Destinations / Budget fields and fills `{country}` in labels
    and step titles.
  - `class="…"` adds wrapper classes. The Form Settings "Wrapper CSS class" `tbl-lux` applies
    the site's petrol/gold theme.
- **Field types** beyond the usual: **Consent** (tick box, links allowed), **Countries** (multi
  picker with ISO codes), **Destinations** (region pills that follow the countries, ending
  with "Not sure yet" and "Somewhere else"), **Budget** (slider whose floor follows adults,
  children, trip length and country). Radio/checkbox groups can be lists, pills or cards;
  sliders can label their top ("10+"); textareas can hide behind a button; phone fields can
  use the site's 33-country dial-code list.
- **Actions** — Webhook, Redirect, DataLayer Event, and **Thank-you summary** (keeps the
  answers in `sessionStorage.tblThankYou` for the thank-you page).
- **Lead Routing** — Travel by Luxe → Lead Routing for the leads app address, API key and
  calendar settings; the **Lead Routing** box on each form switches routing on (redirect /
  popup / just send). Admin REST API under `/wp-json/tbl-lead-routing/v1/`.
- **FAQ lists** — `[tbl_faq id="N"]` (alias `[terrific_faq]`).
- **Import / Export** — every form round-trips as JSON (Forms list → Export JSON;
  Travel by Luxe → Import Form). The site repo keeps its form definitions as files.

Everything else — Code Snippets, Calendly listener, link tracking, session data,
User-Provided Data, reCAPTCHA, revisions — works as in Terrific Digital; the **Notes**
screen in wp-admin summarises each feature.

## Developing

Read [PROJECT.md](PROJECT.md) (architecture, contracts, gotchas) and [CLAUDE.md](CLAUDE.md)
(working rules) first. Releases: bump `TD_VERSION` and the `Version:` header in
`travelbyluxe.php`, commit, then tag `vX.Y.Z` or run the **Release** workflow — see
[INSTALL.md](INSTALL.md).

## License

GPL-2.0-or-later. Includes code from the Terrific Digital plugin (Terrific Digital, GPL-2.0+),
TBL Lead Routing (Travel by Luxe, GPL-2.0-or-later) and
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT).
