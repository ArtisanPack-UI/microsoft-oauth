---
title: Contributing
---

# Contributing

The authoritative contribution guide lives at [`CONTRIBUTING.md`](https://github.com/ArtisanPack-UI/microsoft-oauth/blob/main/CONTRIBUTING.md) in the package root. This page summarizes what a code contributor to `artisanpack-ui/microsoft-oauth` specifically needs to know.

## Development setup

Fork and clone the package repository, then pull it into an ArtisanPack UI dev app via a Composer path repository:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../artisanpack-ui-microsoft-oauth",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "artisanpack-ui/microsoft-oauth": "@dev"
    }
}
```

The `artisanpack-ui-dev` app in the ArtisanPack UI ecosystem is already wired up this way — symlinks live under `packages/microsoft-oauth/`.

Install package dependencies:

```bash
cd packages/microsoft-oauth
composer install
```

## Running tests

```bash
composer test          # runs Pest
```

Filter to a single file or test:

```bash
./vendor/bin/pest tests/Feature/OAuth/OAuthManagerTest.php
./vendor/bin/pest --filter="handles the callback"
```

The test suite uses Orchestra Testbench with an in-memory SQLite database.

## Code style

```bash
composer lint          # php-cs-fixer --dry-run + phpcs
composer fix           # php-cs-fixer fix
composer cs            # phpcs only
composer cs:fix        # phpcbf (auto-fix what phpcs can)
```

**Do not run `vendor/bin/pint` directly.** The package uses WordPress-style spacing (spaces inside parentheses / brackets: `foo( $bar )`, `[ 'k' => $v ]`) and plain Pint strips those, reformatting the entire file and creating massive unrelated churn. The formatter to run is `php-cs-fixer` via `composer fix`, driven by the repo's `.php-cs-fixer.dist.php`.

If Pint has already been run by mistake, `composer fix` restores the correct spacing — but delete the stale `.php-cs-fixer.cache` first, or the fixer skips files and leaves asymmetric parens like `( $x)`.

The package follows the ArtisanPack UI code style — Yoda conditions, aligned operators, trailing commas in multiline, real-tab indentation. `composer fix` handles ~70% of it; `composer cs` catches the rest.

Configuration:

- `.php-cs-fixer.dist.php` — PHP-CS-Fixer rules, including custom `spaces_inside_parenthesis` / `spaces_inside_brackets` fixers.
- `phpcs.xml` — PHPCS rules for what PHP-CS-Fixer can't enforce (disallowed functions, PHP tag placement).

Run both before opening a pull request.

## Branch strategy

- `main` — release-ready code. Never commit directly.
- `feature/*`, `fix/*`, `chore/*`, `docs/*` — branch prefixes for the type of change.
- Release branches: `release/x.y`.

Pre-1.0, in-progress work targets `release/1.0`; that branch merges to `main` when 1.0 is cut.

Pull requests target the current release branch. Squash-merge is the default so the release branch stays linear.

## Commit messages

Conventional commits, matching the ArtisanPack UI convention:

```
feat: add reauthorize endpoint
fix: preserve refresh_token on incremental consent
docs: clarify APP_KEY rotation behavior
chore: bump orchestra/testbench to ^11
```

Types: `feat`, `fix`, `docs`, `chore`, `refactor`, `test`, `ci`.

## PRs must include

1. **A test** — every behavior change gets a Pest test (unit or feature). Bug fixes get a regression test.
2. **Docs updates** — if you're touching public API, changing config, or shifting behavior in a way callers can observe, update the corresponding page under `/docs`.
3. **Changelog entry** — add a line to `CHANGELOG.md` under `## Unreleased`.
4. **Passing CI** — `composer lint` and `composer test` must be green.

## What to work on

Good first issues:

- Improving test coverage for edge cases in the drivers.
- Documenting fields or behaviors you had to figure out from source.
- Adding more scope validation (e.g., warning when a registered scope doesn't look like a Microsoft scope URL or a short-form Graph scope).

Bigger scoped work:

- Additional configuration drivers (Vault, AWS Parameter Store, HashiCorp Consul).
- Multi-connection-per-user support (right now `user_id` is `unique`; users can only connect one Microsoft account).
- First-class multi-tenant helpers instead of the manual driver rebind pattern.
- A Livewire / React / Vue connection-management UI (currently there is none — the sibling `artisanpack-ui/google` has these and this package deliberately doesn't).

## Getting help

- GitHub issues on the [`artisanpack-ui/microsoft-oauth` repo](https://github.com/ArtisanPack-UI/microsoft-oauth).
- The maintainer email address is in `composer.json`.

## Code of conduct

See the top-level `CONTRIBUTING.md` for the code of conduct that covers every ArtisanPack UI project. In short: don't be a jerk.
