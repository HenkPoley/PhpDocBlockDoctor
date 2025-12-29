# Repository Guidelines

## Project Structure & Module Organization
- `bin/doc-block-doctor` is the CLI entry point.
- `src/` contains the core library classes (PSR-4 namespace `HenkPoley\DocBlockDoctor`).
- `tests/Unit` and `tests/NewIntegration` hold PHPUnit suites; fixtures live in `tests/fixtures` and `tests/unit_fixtures`.
- Tooling configs live at repo root: `composer.json`, `phpunit.xml`, `psalm.xml`, `rector.php`, `phpstan.neon`.
- Generated artifacts live in `vendor/`, `node_modules/`, and `logs/`.

## Build, Test, and Development Commands
- `composer install`: install PHP dependencies.
- `php ./bin/doc-block-doctor [options] <path>`: run the analyzer on a codebase (see `README.md` for options).
- `composer doctor-heal-thyself`: run the tool against this repo with read/write scopes.
- `vendor/bin/phpunit`: run unit + integration tests (`--testsuite Unit|Integration` for subsets).
- `vendor/bin/psalm`: run static analysis as configured in `psalm.xml`.
- `vendor/bin/rector process`: apply automated refactors using `rector.php`.

## Coding Style & Naming Conventions
- Use PHP 7.4+ syntax with `declare(strict_types=1)` and PSR-4 namespaces.
- Indent with 4 spaces; follow standard PHP brace placement in existing files.
- Class files are `PascalCase.php`; methods and variables use `camelCase`.
- Test files end in `*Test.php` and mirror source names where possible.

## Testing Guidelines
- PHPUnit is the test framework; suites are defined in `phpunit.xml`.
- Add unit tests under `tests/Unit` and integration tests under `tests/NewIntegration`.
- Keep fixtures in the existing fixture directories and re-use them when possible.

## Commit & Pull Request Guidelines
- Follow the existing commit style: short, imperative summaries (e.g., "Add tests for DocBlockUpdater").
- PRs should include a concise description, reasoning for changes, and the test command(s) run.
- Link relevant issues and update docs/fixtures when behavior changes.
