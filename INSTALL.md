# Installing Terrific Digital on a new WordPress site

The plugin lives in a **private** GitHub repo
(`terrific-digital/wordpress-plugin`) and updates itself on each WordPress
site via the standard WP "Update available" flow. Setting up a new site is a
two-step job — install the plugin once, then add a GitHub token so the site
can pull future updates.

## 1. Install the plugin

Grab the latest ZIP from the [Releases page](https://github.com/terrific-digital/wordpress-plugin/releases)
(`terrific-digital.zip`) and upload it on the site:

**Plugins → Add New → Upload Plugin → Activate.**

That's it for the first install. The plugin works on its own from here.

## 2. Wire up auto-updates (one line in `wp-config.php`)

To let the site see future versions in its WP "Updates" screen, generate a
GitHub token and drop it into `wp-config.php`.

### a) Create a fine-grained token

1. Go to https://github.com/settings/personal-access-tokens/new (signed in
   as the agency machine user, or as yourself if there isn't one).
2. **Resource owner:** `terrific-digital`.
3. **Repository access:** *Only select repositories* → `terrific-digital/wordpress-plugin`.
4. **Permissions → Repository permissions:**
   - **Contents:** Read-only
   - **Metadata:** Read-only (auto-selected)
5. **Expiration:** 1 year (rotate then).
6. Generate the token and copy it. It starts with `github_pat_…`.

> One token can be reused across every site, or you can mint one per site —
> per-site is more isolated but means rotating N places. For ~10 sites,
> shared is fine.

### b) Add the constant to `wp-config.php`

SSH into the site and edit `wp-config.php`. Above the
`/* That's all, stop editing! */` line, add:

```php
define( 'TERRIFIC_DIGITAL_GH_TOKEN', 'github_pat_…' );
```

That's it — no other configuration. WP will start showing "Update available
for Terrific Digital" within ~12 hours of every release (or instantly if you
hit **Dashboard → Updates → Check again** in WP admin). Sites with
auto-updates enabled for the plugin will update themselves overnight.

If the constant is **not** defined, the updater stays inactive — useful for
dev/staging sites that shouldn't pull updates.

## Cutting a release (for maintainers)

1. Bump `TD_VERSION` in `terrific-digital.php` and commit.
2. Tag and push:
   ```bash
   git tag v2.42.0
   git push origin main --tags
   ```
3. The `release.yml` workflow builds `terrific-digital.zip` and attaches it
   to a GitHub release. Every site sees the update within hours.

The workflow verifies that the tag matches `TD_VERSION` — if they disagree
the release fails, so you can't ship a mismatched version by accident.

## Rotating the token

When the PAT is close to expiring (GitHub will email you), regenerate it
with the same scope and update `TERRIFIC_DIGITAL_GH_TOKEN` in every site's
`wp-config.php`. You can list sites quickly by keeping a private inventory
(e.g., a row per site in a shared sheet with the WP-admin URL and the SSH
host) — there's no central registry on our side, and that's intentional:
sites are loosely coupled to the GitHub side via this one constant.
