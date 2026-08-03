# Installing Terrific Digital on a new WordPress site

The plugin lives in a **public** GitHub repo
(`terrific-digital/wordpress-plugin`) and updates itself on each WordPress
site via the standard WP "Update available" flow — **no token, no
configuration required.**

## Install the plugin

Grab the latest ZIP from the [Releases page](https://github.com/terrific-digital/wordpress-plugin/releases)
(`terrific-digital.zip`) and upload it on the site:

**Plugins → Add New → Upload Plugin → Activate.**

That's it. From this point the site will show "Update available for Terrific
Digital" within ~12 hours of every new release (or instantly if you hit
**Dashboard → Updates → Check again** in WP admin). Turn on **Enable
auto-updates** on the Plugins row and the site upgrades itself overnight.

## Optional: a GitHub token (rate-limit only)

You do **not** need a token — the repo is public and the updater runs
anonymously. GitHub allows 60 unauthenticated API requests per hour per
outbound IP; the updater checks about twice a day, so this is essentially
never a factor. Only if you host many sites behind one shared IP and hit the
limit would you add:

```php
define( 'TERRIFIC_DIGITAL_GH_TOKEN', 'github_pat_…' );
```

above the `/* That's all, stop editing! */` line in `wp-config.php`, using
any fine-grained PAT with public-repo read access. It just raises the rate
limit; nothing else depends on it.

> **Migrating from the private-repo era:** older sites carry a
> `TERRIFIC_DIGITAL_GH_TOKEN` constant that was mandatory when the repo was
> private. It's now harmless but unnecessary — you can leave it or delete it.
> If the token was ever leaked, delete it; the plugin updates fine without it.

## Cutting a release (for maintainers)

1. Bump `TD_VERSION` in `terrific-digital.php` and commit.
2. Tag and push:
   ```bash
   git tag v2.50.0
   git push origin main --tags
   ```
3. The `release.yml` workflow builds `terrific-digital.zip` and attaches it
   to a GitHub release. Every site sees the update within hours.

The workflow verifies that the tag matches `TD_VERSION` — if they disagree
the release fails, so you can't ship a mismatched version by accident.
