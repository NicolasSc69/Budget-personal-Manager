# Compta — Personal Budget Manager

A self-hosted personal/family budget management app built with Symfony. Track accounts, transactions, recurring payments and tags, with a SQLite database, database backup/import/export tooling, and a full translation system (English by default, with per-locale overrides).

## Features

- **Accounts** — checking, savings (with interest rate simulation), and target-amount tracking, optionally linked to a person.
- **Transactions** — income/expense entries, transfers between accounts, cheque tracking, recurring transactions (monthly, quarterly, semi-annual, yearly) with an optional end date.
  - **PDF export** — the transaction list (`/transaction`) can be exported to PDF (`TransactionController::exportPdf()`, via `dompdf/dompdf`) with the current filters applied (account, tag, dates, amount range, search, upcoming/current-month), and always includes every matching row, ignoring the on-screen pagination. The export is always sorted by tag, then alphabetically by title, then by date (`TransactionRepository::findFilteredForExport()`) — a fixed report order independent of whichever column sort is active on screen — and repeats the same Current/Upcoming/Projected balance figures shown on the page (see Balance calculations below) just above the table.
- **Tags (categories)** — hierarchical parent/child tags (e.g. `TRANSFER` → `SALARY`) with colors, used to classify transactions. Bulk-creatable from **Tags → Import tags** (`/category/import`), which reads a strict 2-column CSV (`name,parent1|parent2|...`, comma-separated columns, pipe-separated parents) — see that page for the exact format and an example. Tags are matched by name + parent to avoid creating duplicates, and every newly created tag gets an automatic color that doesn't collide with any color already in use.
- **Dashboard** — per-account and global balance overview with charts, including a daily balance forecast and, for savings accounts, an interest-based projection.
- **Admin**
  - Database stats, cache clearing, items-per-page setting, and **theme** (light/dark/automatic — follows the device's OS preference when set to automatic).
  - Automatic database backup to a configurable directory, manual backup, and one-click download.
  - Full database export/import (SQLite file).
  - **Translate interface** — a Drupal-style translation management page (`/admin/translations`), split into two tabs:
    - **Languages** — lists enabled languages with translation progress, lets you set the site-wide default language, add a new language (picked from a dropdown of all ICU-known languages, localized in the site's default language and grouped by continent), remove a language (except the English source and the current default), and set the app's **displayed currency** (see below).
    - **Edit translations** — lists every translatable string in the app and lets you edit the translation for any enabled, non-source language.
    Overrides are stored as standard gettext `.po` files.

## Balance calculations

The dashboard and transaction list show the same balance figures per account (or aggregated across all accounts, except Projected balance — see below). They are computed independently in `DashboardController` and `TransactionController` (same formulas, not yet centralized in a shared service). The account list (`/account`) only shows the plain balance; the other two are specific to a single account and don't apply there.

- **Current balance** — sum of every transaction dated on or before today, plus the account's initial balance (`TransactionRepository::sumUntil()`).
- **Upcoming balance** — sum of *all* transactions regardless of date (past and future), plus the initial balance (`TransactionRepository::sumAll()`). It already includes future-dated transactions and already-generated recurring occurrences, with no "pending/unpaid" distinction.
- **Projected balance** — `average salary − current balance − upcoming transactions still due this month` (`Account::$averageSalary` minus `TransactionRepository::sumUntil()` minus `TransactionRepository::sumFiltered(upcomingOnly: true, currentMonthOnly: true)`). Only shown when a single, non-savings account is selected — it's hidden in the aggregate "all accounts" view and for savings accounts, where it isn't meaningful.

For savings accounts with an interest rate set, the dashboard additionally computes a simple-interest projection (`BudgetForecastService::projectDailyBalancesWithInterest()`): average monthly interest, projected year-end interest, and a day-by-day forecasted balance curve shown on the daily balance chart.

## Requirements

- PHP >= 8.4, with `pdo_sqlite`, `intl`, `ctype`, `iconv` extensions.
- Composer 2.
- (Optional) Docker, for a containerized deployment.
- `var/`, `translations/`, and `config/packages/translation.yaml` must be writable by the user running PHP (database, backups, and the Translate interface all write to disk at runtime).

## Getting started

```bash
composer install
cp .env .env.local   # adjust APP_SECRET / DATABASE_URL if needed
php bin/console doctrine:migrations:migrate --no-interaction
symfony server:start   # or: php -S localhost:8000 -t public
```

## Docker

```bash
APP_SECRET=$(php -r 'echo bin2hex(random_bytes(16));') docker compose up --build
```

The app is served on `http://localhost:8080`. Data is persisted in the `budget_data` named volume; the container runs migrations automatically on startup (see `docker/entrypoint.sh`).

The image intentionally installs dev dependencies too (no `--no-dev`): PHPUnit needs to be present at runtime for the admin "Unit tests" page (below) to be able to run the suite from inside the deployed container.

Only `var/` is a named volume; `translations/` and `config/packages/translation.yaml` come from the image itself. Both the build (`Dockerfile`) and the entrypoint `chown` Apache's `www-data` user as the owner of `var`, `translations`, and `config/packages/translation.yaml`, so the Translate interface can add/edit/remove languages at runtime the same way it can write to the database.

## Internationalization

- The source language of the codebase is **English** — all UI strings live in Twig templates, form field labels, and flash/exception messages, using Symfony's Translation component (`messages` domain).
- Translations are stored as `.po` files in `translations/`:
  - `translations/messages.en.po` — the reference English source strings.
  - `translations/messages.fr.po` — French overrides.
- The site's default display language is configurable at runtime from **Admin → Translate interface → Languages**, without touching code: pick a language and click "Set as default". The choice is persisted in the `app_setting` table and applied to every request via `App\EventListener\LocaleSubscriber`.
- Languages can be added or removed at runtime from the same tab, no code change or cache clear needed: adding a language generates the matching `translations/messages.<locale>.po` file (same keys as English, empty translations); removing one deletes the `.po` file (blocked for the English source and for the current default language). Both also keep `enabled_locales` in `config/packages/translation.yaml` in sync, but the list of available languages shown in the UI is always derived from the `.po` files actually present on disk, not from that config file or from the compiled `kernel.enabled_locales` container parameter — in prod that parameter is frozen until the next `cache:clear`, so relying on it would make just-added/removed languages disappear or linger until then. See `App\Service\PoTranslationManager::getAvailableLocales()` / `addLocale()` / `removeLocale()`.
- **Filesystem permissions**: `translations/` and `config/packages/translation.yaml` must be writable by the user the web server runs as (e.g. `www-data`), the same way `var/` already is — otherwise adding, removing, or editing a language silently fails to persist. `PoTranslationManager` now checks every write and raises a clear error (surfaced as a flash message) instead of reporting success when it isn't the case.
- The "Add a language" dropdown groups its ~230 ICU-known languages by continent (`<optgroup>`, non-selectable by design — only the languages inside are actual `<option>`s). The continent for each language comes from a precomputed `PoTranslationManager::CONTINENT_BY_LOCALE` lookup table rather than being resolved at runtime via `Locale::addLikelySubtags()`: that API only exists from PHP 8.5 onwards and fatally errors ("call to undefined method") on PHP 8.4, which this app otherwise supports — always check a CLDR/ICU API's minimum PHP version before relying on it in a service that must run on the floor of the supported range.

## Currency

- The currency shown throughout the app (account balances, transaction amounts, form labels like "Amount (€)") is a runtime setting, not hardcoded — stored in `app_setting.currency` as an ISO 4217 code (default `EUR`), configurable from **Admin → Translate interface → Languages**.
- It cannot be reliably auto-detected from the site language: app locales are bare language codes (e.g. `fr`, `en` — see Internationalization above), and ICU can't derive a currency from those alone (e.g. "English" could mean USD, GBP or AUD). `App\Service\CurrencyResolver::guessCurrencyForLocale()` only offers a best-effort suggestion, pre-selected in the currency picker; the actual value is always an explicit, user-confirmed/overridable choice — a free-text ISO code input is available for any currency not in the curated common-currency dropdown.
- `CurrencyResolver::getSymbol()` resolves a currency code to its display symbol via PHP's `intl` extension (`NumberFormatter`). It also rejects `XXX` (ISO 4217's reserved "no currency" code) even though ICU otherwise treats it as valid, since it has no real symbol.
- Templates use the `money` Twig filter (`{{ amount|money }}`) to format an amount with the configured currency instead of a hardcoded `€`, and the `currency_symbol()` Twig function where only the symbol is needed (both in `App\Twig\AppExtension`). Form labels that mention the currency (e.g. `TransactionType::amount`, `AccountFormType::initialBalance`) use Symfony's `label_translation_parameters` to inject the symbol into a `%currency%` placeholder rather than hardcoding it.

## Theme

- The UI theme is a runtime setting, stored in `app_setting.theme` (`light`, `dark`, or `auto`, default `auto`), configurable from **Admin → Display**. There is no per-user/per-browser override (e.g. via `localStorage`) — the same theme applies to every visitor.
- `App\Twig\AppExtension::getDefaultTheme()` (Twig function `default_theme()`) exposes the setting to `templates/base.html.twig`, which sets it as `data-bs-theme` on `<html>` in an inline `<script>` in `<head>` (before any CSS loads, to avoid a flash of the wrong theme) — Bootstrap 5.3's theming reads that attribute for its built-in dark-mode styles, and a few custom CSS rules in the same template key off it too (e.g. input border colors).
- When set to `auto`, the actual light/dark value is resolved client-side from `window.matchMedia('(prefers-color-scheme: dark)')` at page load, so it follows the device's OS-level preference; it does not react live to an OS theme change without a page reload.

## Recurring transactions

Recurring transactions (`Transaction::$recurrence`: monthly, quarterly, semi-annual, or yearly, with an optional end date) are handled by two independent mechanisms in `App\Service\RecurringTransactionGenerator`:

- **Automatic catch-up** (`generateDueOccurrences()`) — runs on every Dashboard and transaction list page load. For each recurring template (a transaction with `recurrence != NONE` and no `recurrenceParent`), it generates every occurrence due up to today, one interval at a time from the last generated occurrence (or the template's own date if none exists yet), catching up any backlog in a single pass (capped at 240 occurrences per template to guard against a runaway loop). It does not check `recurrenceEndDate` — a template past its end date that was never switched back to `NONE` keeps generating occurrences.
- **Manual monthly duplication** (`/transaction/recurring`, `findMonthlyTemplatesPendingNextMonth()`) — only applies to **monthly** recurrences (the "next calendar month" check has no clean equivalent for quarterly/semi-annual/yearly cadences). A template is listed as pending only when its computed next occurrence date actually falls within the next calendar month relative to today (not before it — a template whose last occurrence is several months behind gets its date pushed forward to that window instead of showing a stale date — and not after it either, so a template whose real next due date is further out, e.g. next-next month, doesn't show up early) and its `recurrenceEndDate`, if set, isn't already exceeded. The user then explicitly ticks the templates to duplicate (optionally editing the title/tag first) rather than having them created automatically.

## Account navigation loading overlay

Clicking any link that navigates to an account's dashboard (navbar, account list, dashboard account chart, transaction rows, account detail page) or switching accounts via the navbar dropdown shows a full-page overlay (spinner + "Redirecting to `<account>` (`<person>`)" message) until the new page loads, via `templates/_account_nav_overlay.html.twig` (delegated click listener on `[data-account-nav]`, plus a dedicated handler for the `#account-switcher` `<select>` and the dashboard's accounts doughnut chart). It's a real full-page navigation (not fetched via `data-ajax-modal`), so a `pageshow` listener checking `event.persisted` hides the overlay immediately when the browser restores a page from the back/forward cache — otherwise navigating back (mouse button or keyboard) would show the previous page still stuck behind the overlay. The account label + person shown in the message is built server-side by `AppExtension::getAccountNavMessage()` (Twig function `account_nav_message(account)`).

## Deletion safety net

Every "delete" action (accounts, account types, tags, people) that can be blocked by related data (e.g. a tag still used by transactions) disables its own Delete button and shows a tooltip explaining why — but a disabled button is only a client-side hint, not a real guard: editing the page's HTML and resubmitting would otherwise hit the database's foreign key constraint and crash with a 500. `App\EventListener\ForeignKeyConstraintExceptionListener` catches `Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException` for the whole app (not just these four resources) and turns it into the same kind of flash message + redirect instead, so this stays safe even for actions that don't have their own guard.

## Testing

- `tests/Service/PoTranslationManagerTest.php` — real unit tests (no database) for the `.po`-backed translation manager: adding/removing a locale, persisting translations, continent grouping.
- `tests/Service/CurrencyResolverTest.php` — real unit tests (no database) for currency symbol resolution, the per-locale currency guess, the common-currency list, and ISO code validation (including the `XXX` "no currency" edge case).
- `tests/Twig/AppExtensionTest.php` — unit tests (repositories mocked with `createStub()`, no database) for the `money` filter and `currency_symbol()` function.
- `tests/Controller/PageSmokeTest.php` — functional smoke tests: every top-level page (dashboard, accounts, account types, tags, import tags, people, transactions, recurring transactions, admin, translate interface) loads with a successful response, plus dedicated checks that the transaction PDF export returns an `application/pdf` attachment and still succeeds with filters applied.
- `tests/Repository/TransactionRepositoryTest.php` — real database test (each test runs inside a transaction rolled back in `tearDown()`, so it never leaves data behind) asserting `TransactionRepository::findFilteredForExport()`'s fixed sort order: tag, then title, then date.
- Run locally with `./bin/run-tests.sh` (wraps `bin/phpunit`, writes a JUnit report to `var/test-report/junit.xml` and a testdox report to `var/test-report/testdox.txt`).
- **Admin → Unit tests** (`/admin/tests`) runs the same suite from the running app (via `Symfony\Component\Process\Process`, using `PhpExecutableFinder` to invoke the exact PHP binary serving the request rather than relying on `env php`'s `PATH` resolution) and shows a pass/fail report per test, plus the raw process output if the run couldn't even produce a report. `App\Service\TestReportReader` parses the JUnit XML for both this page and the admin index's compact summary.
- The test environment forces `APP_ENV=test` in `tests/bootstrap.php` (`putenv()` + `$_ENV`/`$_SERVER`) rather than relying solely on `phpunit.dist.xml`'s `<server force="true">`: the Docker image sets `APP_ENV=prod` as a real container environment variable, which otherwise wins and makes `WebTestCase::createClient()` fail with "framework.test is not set to true".

## Project structure

- `src/Controller` — one controller per resource (Account, AccountType, Category, Person, Transaction, Dashboard, Admin, Translation).
- `src/Entity` / `migrations` — Doctrine ORM entities and SQLite migrations.
- `src/Form` — Symfony form types.
- `src/Service/DatabaseBackupService.php` — automatic/manual SQLite backup logic.
- `src/Service/RecurringTransactionGenerator.php` — recurring transaction logic: automatic catch-up generation and the manual monthly duplication page (see Recurring transactions above).
- `src/Service/PoTranslationManager.php` — reads/writes the `.po` translation catalogues used by the Translate interface page.
- `src/Service/CurrencyResolver.php` — resolves ISO 4217 currency codes to display symbols and guesses a default currency for a locale (see Currency above).
- `src/Twig/AppExtension.php` — Twig extension exposing, among others, the `money` filter and `currency_symbol()` function.
- `src/EventListener/` — cross-cutting request/exception hooks: site-wide locale (`LocaleSubscriber`) and the foreign-key deletion safety net (`ForeignKeyConstraintExceptionListener`).
- `src/Service/TestReportReader.php` — parses the JUnit report used by the admin "Unit tests" page.
- `tests/` — PHPUnit test suite (see Testing below).
- `templates/` — Twig templates (Bootstrap 5 UI).

## License

Proprietary — see `composer.json`.
