# Installing and releasing the Travel by Luxe plugin

## Install on a WordPress site

1. Download `travelbyluxe.zip` from the
   [Releases page](https://github.com/terrific-digital/travelbyluxe-plugin/releases).
2. **Plugins → Add New → Upload Plugin → Activate.**
3. If **Terrific Digital** or **TBL Lead Routing** are active on the site, deactivate them
   (this plugin replaces both; forms, submissions, settings and routing options are kept
   because the data lives in the same tables and options). Do not delete them until the
   new plugin has been checked.

From then on the site shows "Update available for Travel by Luxe" within ~12 hours of each
release (or straight away after **Dashboard → Updates → Check again**). The repo is public,
so the updater runs anonymously — no `wp-config.php` token.

From the Travel by Luxe site repo, `node cloudways-staging/install-travelbyluxe-plugin.mjs`
does the same against the **staging** app (never production).

## Cutting a release

1. Bump the version in **both** places in `travelbyluxe.php`: the `Version:` header and
   `define( 'TD_VERSION', … )`. `TD_VERSION` is also the asset cache-buster, so bump it on
   every change that touches `assets/`.
2. Commit and push to `main`.
3. Either push a tag:
   ```bash
   git tag v3.0.1
   git push origin main --tags
   ```
   or open **Actions → Release → Run workflow** and enter the version.

The `release.yml` workflow checks the version matches the plugin file, lints every PHP and
JS file, builds `travelbyluxe.zip` (top-level folder `travelbyluxe/`) and attaches it to
the GitHub release. A mismatched version fails the release.

## Keeping up with the upstream plugin

The `upstream` remote points at `terrific-digital/wordpress-plugin`. Cherry-pick fixes from
there by hand (`git fetch upstream && git cherry-pick <sha>`); never merge wholesale —
the file names, menu labels and shortcodes differ, and this plugin carries modules the
upstream does not have.
