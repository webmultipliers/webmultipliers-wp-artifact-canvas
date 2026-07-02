# Development

## Commands

```bash
composer install       # dev dependencies (phpcs/wpcs, phpstan, phpunit + brain/monkey)
composer lint          # PHPCS (WordPress-Extra)
composer lint:fix      # PHPCBF auto-fix
composer analyse       # PHPStan level 6 over src/
composer test          # PHPUnit unit suite (no WordPress install required)
composer build         # switch vendor to prod-only deps and build the distributable ZIP
composer vendor:dev    # switch vendor back to dev dependencies (run after a build to restore tooling)
```

`composer build` (alias of `build:prod`) leaves the local `vendor/` in production mode — `phpunit`, `phpcs`, and `phpstan` disappear from `vendor/bin` until you run `composer vendor:dev` again.

## Local WordPress

A [`.wp-env.json`](../.wp-env.json) is included — run `npx @wordpress/env start` for a disposable WordPress with the plugin active at `http://localhost:8888` (admin / password). Useful companions:

```bash
npx @wordpress/env run cli wp <command>   # WP-CLI inside the environment
npx @wordpress/env stop                   # stop containers
```

The plugin directory is mounted live, so PHP changes apply on the next request without reinstalling.

## Tests

The unit suite runs against Brain Monkey mocks — no WordPress install needed. Shared HTML fixtures live in `tests/Artifacts/` and are loaded through the `artifact_fixture()` helpers on the base `TestCase`.

## CI & releases

Every push and PR runs lint + analyse + test on PHP 8.1/8.2/8.3 (`qa.yml`). Pushing a `v*` tag that matches the plugin header version builds and attaches the installable ZIP to a GitHub release (`release.yml`).
