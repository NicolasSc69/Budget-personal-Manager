# Compta — Personal Budget Manager

A self-hosted personal/family budget management app built with Symfony. Track accounts, transactions, recurring payments and tags, with a SQLite database, database backup/import/export tooling, and a full translation system (English by default, with per-locale overrides).

## Features

- **Accounts** — checking, savings (with interest rate simulation), and target-amount tracking, optionally linked to a person.
- **Transactions** — income/expense entries, transfers between accounts, cheque tracking (the cheque number is shown under the title in the transaction list and the dashboard's recent transactions), deferred debit card payments (see Deferred debit below), recurring transactions (monthly, quarterly, semi-annual, yearly) with an optional end date.
  - **Item counter** — the paginated lists (transactions, tags, accounts) show an `x / y` counter above the pagination (`_pagination.html.twig`, optional `shownCount`/`totalCount` parameters): `x` is the cumulative number of items shown up to and including the current page (`(page - 1) × items per page + rows on this page`, e.g. 30 / 183 on page 1 and 60 / 183 on page 2 with 30 items per page), `y` the total number of items matching the current filters. It is shown even when everything fits on a single page.
  - **Pager** — Drupal-style (`_pagination.html.twig`): `« First ‹ Previous … 3 4 [5] 6 7 … Next › Last »`. At most 5 page links are shown, centered on the current page (shifted at the edges so 5 links stay visible), with `…` when pages are hidden on either side. First/Previous are hidden on page 1 and Next/Last on the last page, as in Drupal.
  - **Transaction list filters** — title search, account, **person** (`person` query parameter: every transaction of the accounts owned by that person, combinable with the other filters), tag, date range, amount range, plus checkboxes for current month, upcoming only, **deferred debit** (`deferred_debit` query parameter) and **cheque** (`cheque`) — each checkbox submits the filter form immediately.
  - **Default sort** — without any filter, the list is sorted by id, newest first. As soon as a filter is active (any of the above), it is sorted by **transaction date, oldest first** (`TransactionController::index()`). A sort chosen by clicking a column header (`sort`/`direction` in the URL) always wins; the filter form doesn't carry the sort, so submitting new filters goes back to the date sort.
  - **New transaction date** — the creation form is pre-filled with today's date; a duplicated transaction keeps its source's date.
  - **Creation popin** — besides the "New transaction" button in the page header, the transaction list has a floating round blue **+** button fixed at the bottom right of the screen that opens the same creation popin (`#transactionFormModal`, pre-selecting the filtered account if any). That popin is 70% of the viewport width (from the `sm` breakpoint up) and, like the generic `#ajaxModal`, 95% of the viewport height with a scrolling body (`.modal-dialog-tall` in `templates/base.html.twig`).
- **Modal action buttons** — in every form / detail popin, the action buttons (Save, Cancel, Delete, Edit, Close…) sit on a single row (`d-flex flex-wrap flex-sm-nowrap gap-2`: wrapping only on phones, below the `sm` breakpoint). In the transaction edit form the Delete button has its own `<form>`, which can't be nested in the transaction form: the row is placed after the form and the Save button targets it through its `form="transaction-form-{id}"` attribute.
  - **Autocomplete** — the title suggestions in the transaction form and the tag/person/account type pickers match by prefix (case-insensitive "starts with", like SQL `LIKE 'text%'`), not by substring. Hierarchical tags (`Parent › Child`) match if any level starts with the query. The transaction list's title search is unchanged (`LIKE '%text%'`).
  - **PDF export** — the transaction list (`/transaction`) can be exported to PDF (`TransactionController::exportPdf()`, via `dompdf/dompdf`) with the current filters applied (account, person, tag, dates, amount range, search, upcoming/current-month, deferred debit, cheque), and always includes every matching row, ignoring the on-screen pagination. The export is always sorted by tag, then alphabetically by title, then by date (`TransactionRepository::findFilteredForExport()`) — a fixed report order independent of whichever column sort is active on screen — and repeats the same Current/Upcoming/Projected balance figures shown on the page (see Balance calculations below) just above the table.
- **Tags (categories)** — hierarchical parent/child tags (e.g. `TRANSFER` → `SALARY`) with colors, used to classify transactions. Bulk-creatable from **Tags → Import tags** (`/category/import`), which reads a strict 2-column CSV (`name,parent1|parent2|...`, comma-separated columns, pipe-separated parents) — see that page for the exact format and an example. Tags are matched by name + parent to avoid creating duplicates, and every newly created tag gets an automatic color that doesn't collide with any color already in use.
- **Dashboard** — per-account and global balance overview with charts (the global accounts table is grouped into Current accounts / Savings tabs, with per-person sub-tabs), including a daily balance forecast and, for savings accounts, an interest-based projection.
- **Admin**
  - Database stats, cache clearing (all environments at once, or one environment with the cross on its row), the **calculation caches** table (see Calculation cache below), items-per-page setting, **theme** (light/dark/automatic — follows the device's OS preference when set to automatic), and the **recurring transactions check day** (day of the month from which recurring transactions are proposed for duplication to next month, default `1`).
  - Automatic database backup to a configurable directory, manual backup, and one-click download.
  - Full database export/import (SQLite file).
  - **Translate interface** — a Drupal-style translation management page (`/admin/translations`), split into two tabs:
    - **Languages** — lists enabled languages with translation progress, lets you set the site-wide default language, add a new language (picked from a dropdown of all ICU-known languages, localized in the site's default language and grouped by continent), remove a language (except the English source and the current default), and set the app's **displayed currency** (see below).
    - **Edit translations** — lists every translatable string in the app and lets you edit the translation for any enabled, non-source language.
    Overrides are stored as standard gettext `.po` files.

## Balance calculations

The dashboard and transaction list show the same balance figures per account (or aggregated across all accounts, except Projected balance — see below). They are computed independently in `DashboardController` and `TransactionController` (same formulas, not yet centralized in a shared service). The account list (`/account`) only shows the plain balance; the other two are specific to a single account and don't apply there.

All date-based balance figures and charts use the transaction's **effective date** — its actual debit date for a deferred payment, its own date otherwise (`Transaction::getEffectiveDate()`, DQL `COALESCE(t.debitDate, t.date)` in `TransactionRepository`) — see Deferred debit below.

- **Current balance** — sum of every transaction whose effective date is on or before today, plus the account's initial balance (`TransactionRepository::sumUntil()`).
- **Upcoming balance** — sum of *all* transactions regardless of date (past and future), plus the initial balance (`TransactionRepository::sumAll()`). It already includes future-dated transactions and already-generated recurring occurrences, with no "pending/unpaid" distinction.
- **Projected balance** ("Solde prévisionnel approximatif" in French) — shows two figures, both excluding transactions whose tag has **Excluded from forecast** checked (`Category::$excludedFromForecast`, `TransactionRepository::sumFiltered(..., excludeForecastExcluded: true)`) so the account's declared average salary isn't double-counted alongside the real salary transaction. Only shown when a single, non-savings account is selected — it's hidden in the aggregate "all accounts" view and for savings accounts, where it isn't meaningful.
  - **Main figure** — `current balance + upcoming transactions still due this month` (`TransactionRepository::sumUntil()` plus `TransactionRepository::sumFiltered(currentMonthOnly: true, upcomingOnly: true, excludeForecastExcluded: true, byEffectiveDate: true)`). Deliberately excludes transactions already dated this month but before today — those are already included in "current balance" — to avoid double-counting them.
  - **Theoretical figure** (shown in parentheses, labelled "Théorique") — `Account::$averageSalary` (or the sum across all accounts in the aggregate view) plus the *whole* current month's balance, past and future (`TransactionRepository::sumFiltered(currentMonthOnly: true, excludeForecastExcluded: true, byEffectiveDate: true)`). An older, coarser estimate based on the declared average salary rather than the account's actual current balance; kept alongside the main figure as a sanity check.
  - The **Excluded from forecast** flag on a tag is checked against the transaction's own tag only, not inherited by child tags (unlike most other tag filters in the app, which include descendants via `Category::getSelfAndDescendantIds()`) — tagging real salary/bonus transactions with an unflagged sub-tag of an otherwise-excluded parent tag will still double-count them here.

For savings accounts with an interest rate set, the dashboard additionally computes a simple-interest projection (`BudgetForecastService::projectDailyBalancesWithInterest()`): average monthly interest, projected year-end interest, and a day-by-day forecasted balance curve shown on the daily balance chart.

The daily balance chart's period (`DashboardController::resolveDailyBalancePeriod()`) depends on the account type. For a savings account, it is always the whole current calendar year (January 1st to December 31st), so the interest-based forecast curve runs up to year end. Otherwise it is not a fixed calendar year — it adapts to the account's actual transaction history (globally across all accounts when none is selected), via `TransactionRepository::findEarliestDate()` / `findLatestDate()`: if the account's whole history spans less than 365 days, the chart shows it in full (first operation to last operation, without padding into an empty future); otherwise it shows a 365-day window centered on today, shifted if needed so it stays between the first and last operation. Bounds use the effective date (debit date for deferred-debit transactions), like the balances themselves. The chart's caption above it (`dailyBalanceChartPeriodLabel`, translation key `Balance trend from %start% to %end%`) is built from that same resolved period, so it always matches what's actually plotted.

The daily balance chart (`#dailyBalanceChart`, `templates/dashboard/index.html.twig`) uses Chart.js's `interaction: { mode: 'index', intersect: false }` rather than the default point-hit-testing mode, so hovering anywhere over a given day shows a tooltip with that day's balance — the line itself is drawn with `pointRadius: 0` (no visible dots), which would otherwise make the default `nearest`/`intersect: true` tooltip nearly impossible to trigger. The tooltip value is formatted with `toLocaleString('fr-FR', …)` plus the configured currency symbol (`currency_symbol()`), matching the `money` filter's formatting.

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

## Deferred debit

A transaction can be flagged **Deferred payment** (`Transaction::$isDeferredDebit`) for a deferred debit card: its amount only hits the balance on its **actual debit date** (`Transaction::$debitDate`), computed by `Transaction::computeDeferredDebitDate()`:

- transaction made from the 1st to the 20th → debited on the **5th of the next month** (20/01 → 05/02);
- transaction made from the 21st to the end of the month → debited on the **5th of the month after next** (21/01 → 05/03).

In the transaction form, ticking the checkbox shows the debit date field, pre-filled from the transaction date and recomputed whenever the transaction date changes (`computeDebitDate()` in `templates/transaction/_form.html.twig`, kept in sync with the PHP method). The field stays editable; if left empty, `TransactionType` computes it on submit, and unticking the checkbox clears it. Occurrences generated from a recurring template inherit the flag and get their own debit date recomputed from their date. Moving a recurring Sunday transaction to Monday (`RecurringTransactionGenerator::shiftSundayOccurrencesToMonday()`) also recomputes its debit date, which can move it one month later when the shift crosses the 20th.

The current balance, projected balance and balance charts use the debit date (see Balance calculations). The transaction list's own filters, sort, "upcoming" styling, filtered total, the per-tag breakdown charts and the PDF export still use the transaction date. In the transaction list and the dashboard's recent transactions, the debit date is shown under the transaction date, and a deferred payment not debited yet gets the "upcoming" yellow background (without the italic).

## Recurring transactions

Recurring transactions (`Transaction::$recurrence`: monthly, quarterly, semi-annual, or yearly, with an optional end date) are handled by two independent mechanisms in `App\Service\RecurringTransactionGenerator`:

- **Automatic catch-up** (`generateDueOccurrences()`) — runs on every Dashboard and transaction list page load. For each recurring template (a transaction with `recurrence != NONE` and no `recurrenceParent`), it generates every occurrence due up to today, one interval at a time from the last generated occurrence (or the template's own date if none exists yet), catching up any backlog in a single pass (capped at 240 occurrences per template to guard against a runaway loop). It does not check `recurrenceEndDate` — a template past its end date that was never switched back to `NONE` keeps generating occurrences.
- **Manual monthly duplication** (`/transaction/recurring`, `findMonthlyTemplatesPendingNextMonth()`) — only applies to **monthly** recurrences (the "next calendar month" check has no clean equivalent for quarterly/semi-annual/yearly cadences). The reference is today's date: a template is listed as pending when none of its occurrences (template included) falls within the **next calendar month** (1st of next month inclusive to 1st of the month after exclusive — e.g. on 2 Nov 2026, between 1 Dec and 31 Dec 2026), its last occurrence isn't already beyond that window, and its `recurrenceEndDate`, if set, isn't exceeded by the proposed date. The user then explicitly ticks the templates to duplicate (optionally editing the title/tag first) rather than having them created automatically.
  - The proposed date (`computeNextMonthOccurrence()`, pure date logic with no database access) uses the **template's own day of month**, clamped to the month's last day (a 31st becomes the 30th, or the 28th/29th in February). It is anchored on the template rather than on the last occurrence so that a shifted occurrence doesn't make the following ones drift.
  - **Sunday → Monday**: when the proposed date falls on a Sunday, the duplicated occurrence is moved to the following Monday. If that crosses a month boundary (e.g. Sunday 31 Jan 2027 → Monday 1 Feb 2027), the check window is adjusted: this month's occurrence pushed onto the 1st of next month doesn't count as next month's, and next month's occurrence pushed onto the 1st of the month after does.
  - **Check day**: nothing is listed as pending before the day of the month configured in **Admin → Display** (`app_setting.recurring_check_day`, 1–31, default `1` — i.e. always checked, the previous behavior). From that day on, the pending list, the duplication action and the pending counters on the dashboard/accounts pages work as described above. A day beyond the month's length is clamped to its last day (e.g. `31` triggers on 28 February). The comparison is `RecurringTransactionGenerator::isCheckDayReached()`, pure date logic with no database access.
  - The automatic catch-up above does **not** apply the Sunday shift.

## Global dashboard accounts table

On the global dashboard (no account selected), the accounts table next to the accounts chart is split into two tabs, **Current accounts** and **Savings**, based on each account's `isSavings` flag (not its account type). Each tab holds sub-tabs: **All** (every account of that tab, with the person's name in parentheses) followed by one sub-tab per person, plus a **No person** sub-tab for accounts without a person. Each tab and sub-tab shows its account count, and each table has its own **Total** row. Tabs with no account are hidden. The grouping is done in Twig (`templates/dashboard/index.html.twig`) from the `accountBalances` passed by `DashboardController`. The accounts doughnut chart (`#accountsPolarChart`) follows the active tab and sub-tab: each sub-tab pane carries the ids of its accounts (`data-account-ids`), and a `shown.bs.tab` listener on `#accountsTabs` filters the chart's labels, balances and click URLs (`chartData.accountIds` maps chart slices to accounts). Each account keeps the color assigned from the full list, so it looks the same in every tab. The table column is aligned to the top (`align-self-start`) so the tabs stay in place when switching between tables of different heights.

## Page loading screen

Pages can take a moment to load (the dashboard computes several reports over the whole history), so a full-page loading screen (`templates/_account_nav_overlay.html.twig`, included at the very top of `<body>` in `base.html.twig`) shows a spinner, a message and a **progress bar up to 100 %** while the browser waits for the next page:

- **Any regular navigation** — a click on an internal link or a non-ajax form submit shows "Loading in progress" ("Chargement en cours" in French). It only appears after 250 ms, so fast pages never flash it. Links and forms handled in JavaScript (ajax modals, confirmation popin… which call `preventDefault()`), anchors, external links, links opened in a new tab (`target`, Ctrl/Cmd/Shift click), `mailto:`/`tel:` and links with a `download` attribute are ignored. File downloads that don't leave the page must opt out with `data-no-page-loader` (PDF export, backup download and database export already do), otherwise the screen would stay displayed.
- **Account navigation** — any link to an account's dashboard (`[data-account-nav]`: navbar, account list, dashboard account chart, transaction rows, account detail page), the `#account-switcher` dropdown and the dashboard's accounts doughnut chart show "Redirecting to `<account>` (`<person>`)" instead, built server-side by `AppExtension::getAccountNavMessage()` (Twig function `account_nav_message(account)`).
- **After saving a modal form** (transactions and the generic `#ajaxModal`) and on dashboard chart clicks, the screen is shown before reloading / navigating.

The server reports no real progress, so the bar creeps asymptotically towards 90 %. The reached value is handed over to the next page through `sessionStorage` (`pageLoaderProgress`): the new page shows the screen again at that value as soon as `<body>` starts, then completes it to 100 % on `window.load` (charts included) and fades it out. A `pageshow` listener checking `event.persisted` hides it when the browser restores a page from the back/forward cache. JavaScript API: `window.showPageLoader(message?)` (delayed), `window.showAccountNavOverlay(message)` (immediate), `window.hidePageLoader()`.

## Performance

Measured on a ~23,000-transaction database (8 accounts, 60 monthly series), the global dashboard went from ~1.35 s to ~140 ms and the account list from ~500 ms to ~40 ms. Points to keep in mind when changing the code:

- **No entity hydration over the whole history.** `BudgetForecastService` builds the 3 yearly reports and the daily balance chart from `TransactionRepository::findBalanceMovements()` (scalar rows: effective date, amount, tag id), cached per account by the calculation cache (see below), so it is read from the database once and then served from the cache. Loading every transaction as an entity (the former `findAllOrderedByEffectiveDate()`) took ~400 ms per call.
- **Sunday recurring counters.** The badges use `TransactionRepository::countRecurringOnSunday()`, which only reads ids and dates; `findRecurringOnSunday()` then loads just the matching entities.
- **Extra lazy collections.** `Account::$transactions` and `Category::$transactions` are `EXTRA_LAZY`, so "has transactions?" checks (delete buttons, `is not empty` in Twig) run a SQL `COUNT` instead of loading every transaction of the account/tag.

## Calculation cache

Balances, sums and dashboard reports are cached (`App\Service\CalculationCache`, pool `cache.calculations` in `config/packages/cache.yaml`: tag-aware filesystem cache, 1-day lifetime), in **one cache per entity**: Transactions, Accounts, Tags, People.

- **Each calculation declares the entities it depends on** (`$calculationCache->get(__METHOD__, [CalculationCache::TRANSACTION, CalculationCache::ACCOUNT], $arguments, fn () => …)`) and belongs to each of their caches:

  | Calculation | Transactions | Accounts | Tags | People |
  |---|:-:|:-:|:-:|:-:|
  | `TransactionRepository::sumAll()`, `sumUntil()`, `findBalanceMovements()`, `findEarliestDate()`, `findLatestDate()`, `countRecurringOnSunday()`, `BudgetForecastService::getMonthlyReport()`, `getDailyBalances()` | ✓ | ✓ | | |
  | `BudgetForecastService::getCategoryBreakdown()` | ✓ | ✓ | ✓ | |
  | `TransactionRepository::sumFiltered()`, `countFiltered()` | ✓ | ✓ | ✓ | ✓ |

  Entity lists (`findFiltered()`, `findRecent()`…) are not cached: they return managed entities.
- **Invalidation** — `App\EventListener\CalculationCacheInvalidator` (Doctrine `onFlush` / `postFlush`) collects the entities a flush creates, updates or deletes (including collection changes) and clears the cache of each of them only: renaming a person drops the filtered sums but keeps the balances. Account types and settings invalidate nothing (no calculation depends on them). A flush without changes (e.g. the recurring catch-up with nothing to generate) keeps the cache. The database import (**Admin → Import**) replaces the SQLite file without Doctrine and clears every cache (`invalidateAll()`).
- **Writes outside Doctrine are not detected**: a raw SQL `UPDATE`/`DELETE` must call `CalculationCache::invalidate()` / `invalidateAll()` itself.
- **Keys** — method name + arguments (entities by class and id, dates by day, enums by value) + **today's date**, since many figures depend on it (until today, current month, upcoming), so nothing computed yesterday is served today. The monthly report's key also includes the locale (month labels are translated).
- **In memory** — results are also kept for the current request (the dashboard reads the same history several times); this copy is dropped along with the persistent one, and between requests (`ResetInterface`).
- **Admin → Cache → Calculation caches** (`#calculation-caches`) — one row per entity cache: a dropdown listing its cached calculations with their number of entries, the number of entries, size and last computation time, and a cross to clear that cache only (`POST /admin/calculation-cache/{entity}/clear`, CSRF-protected). A tag-aware cache can't list its items, so each entity cache keeps an index (`index.{entity}` items, entries dropped with another entity's cache are skipped).
- **Tests** — the `test` environment uses an in-memory pool (`cache.adapter.array`): tests roll their database changes back, a persistent cache would serve figures computed from rows that no longer exist.

## Double-submit protection

Every form in the app gets a loader on submit (`templates/base.html.twig`): as soon as a form is submitted, its submit buttons are disabled and show a spinner, and any further submit of the same form is swallowed, so a double click can't create the same entry twice. The listener runs in the capture phase, after the confirmation popin (`data-confirm`, see `templates/_confirm_modal.html.twig`) and before the ajax modal submit handlers. A form can opt out with `data-no-submit-loader`. Ajax handlers that keep the form on screen after the request (quick-create popins, network errors) call `window.resetSubmitLoader(form)` to re-enable it, and a `pageshow` listener restores the buttons when the page comes back from the back/forward cache.

## Deletion safety net

Every "delete" action (accounts, account types, tags, people) that can be blocked by related data (e.g. a tag still used by transactions) disables its own Delete button and shows a tooltip explaining why — but a disabled button is only a client-side hint, not a real guard: editing the page's HTML and resubmitting would otherwise hit the database's foreign key constraint and crash with a 500. `App\EventListener\ForeignKeyConstraintExceptionListener` catches `Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException` for the whole app (not just these four resources) and turns it into the same kind of flash message + redirect instead, so this stays safe even for actions that don't have their own guard.

## Testing

- `tests/Service/PoTranslationManagerTest.php` — real unit tests (no database) for the `.po`-backed translation manager: adding/removing a locale, persisting translations, continent grouping.
- `tests/Service/CurrencyResolverTest.php` — real unit tests (no database) for currency symbol resolution, the per-locale currency guess, the common-currency list, and ISO code validation (including the `XXX` "no currency" edge case).
- `tests/Twig/AppExtensionTest.php` — unit tests (repositories mocked with `createStub()`, no database) for the `money` filter and `currency_symbol()` function.
- `tests/Service/CalculationCacheTest.php` — real unit tests (no database, in-memory adapter) for the calculation cache: computed once then served, one entry per name/arguments, clearing an entity cache only drops the calculations depending on it, `invalidateAll()`, the in-memory copy and `reset()`, entity class → cache mapping (Doctrine proxies included, none for account types / settings), the admin description (entries, calculations, size, last computation), and the key rules (entities by id, dates by day, enums, invalid objects rejected).
- `tests/EventListener/CalculationCacheInvalidatorTest.php` — real database tests (rolled back): each test fills the cache, changes amounts with a raw SQL statement Doctrine doesn't see (proving the cached value is served), then flushes a change; creating, updating or deleting a transaction, or changing an account / tag / person, recomputes exactly the calculations depending on it, while account types, settings and a flush without changes keep the cache.
- `tests/Controller/AdminCalculationCacheTest.php` — functional tests for **Admin → Calculation caches**: one row per entity with its calculations dropdown and entry count, disabled cross on an empty cache, clearing one cache (success message), invalid CSRF token and unknown cache rejected, and the per-environment cross on the cache directories table.
- `tests/Controller/PageSmokeTest.php` — functional smoke tests: every top-level page (dashboard, accounts, account types, tags, import tags, people, transactions, recurring transactions, admin, translate interface) loads with a successful response, plus a check that **Admin → Display** saves the recurring check day and rejects an out-of-range value (original settings restored afterwards), and dedicated checks that the transaction PDF export returns an `application/pdf` attachment and still succeeds with filters applied.
- `tests/Service/RecurringNextMonthDateTest.php` — real unit tests (no database) for `RecurringTransactionGenerator::computeNextMonthOccurrence()`: day-of-month clamping, Sunday → Monday shift, check window adjustment when the shift crosses a month boundary, year rollover, `isCheckDayReached()` (before/on/after the check day, clamping of day 31 in shorter months), plus an exhaustive check over 24 months × 31 days that the proposed date is never a Sunday and always lies within its window.
- `tests/Service/RecurringTransactionGeneratorTest.php` — real database tests (rolled back in `tearDown()`) for recurring generation: a deleted occurrence isn't generated again, deleting a template promotes its oldest occurrence without duplicates, and duplicating to next month moves a Sunday occurrence to Monday and then stops listing the template as pending, a template is only listed as pending (and duplicable) once the configured check day is reached, occurrences of a **deferred debit** template get their own debit date (none for a non-deferred one), and moving a deferred Sunday occurrence to Monday recomputes its debit date (Sunday 20th → Monday 21st moves the debit one month later) (the check day is reset to `1` in `setUp()` so the other tests don't depend on the test database's settings).
- `tests/Entity/TransactionTest.php` — real unit tests (no database) for the **deferred debit** rule `Transaction::computeDeferredDebitDate()`: the 20th/21st boundary, month ends (30/31-day months, February in leap and non-leap years), year rollover, plus an exhaustive check over 3 years that the debit date is always the 5th, 1 or 2 months later; and `getEffectiveDate()`.
- `tests/Service/BudgetForecastServiceTest.php` — real database tests (rolled back) for the dashboard reports: monthly income/expense with refunds netted per tag, a deferred payment counted on its debit month, balance carry-over, daily balances starting from the previous balance, reports served from the calculation cache until an entity changes, separate history per account.
- `tests/Repository/TransactionRepositoryTest.php` — real database tests (each test runs inside a transaction rolled back in `tearDown()`, so it never leaves data behind): `findFilteredForExport()`'s fixed sort order (tag, then title, then date), forecast-excluded tags and current month/upcoming sums, the **person** filter (list, count, sum, export, combined with an account), the deferred debit filter, balances counting a deferred payment on its debit date (`sumUntil()`/`sumAll()`), `findBalanceMovements()` (effective date, order, account), the Sunday recurring list/counter, and the extra lazy account/tag collections (counted without being loaded).
- `tests/Controller/TransactionControllerTest.php` — functional tests (rolled back, `disableReboot()` so requests share the test's connection): the person filter (position after Account, results, kept in sort and PDF links, unknown id ignored, PDF export), the deferred debit filter, the transaction modal form as the browser uses it (create, edit, duplicate prefill, delete, bulk delete, invalid submission → 422 without saving), the **deferred debit** form handling (computed when left empty, kept when typed, cleared when unticked), the loading screen (progress bar present, PDF export link opted out, French text), the **default sort** (ascending date as soon as a filter is active, explicit sort kept, newest created first without filter), today's date pre-filled on creation (source date kept on duplicate), and the edit popin's action buttons on a single row (the Save button outside the form still submits it).
- The test database (`var/data_test.db`) is not migrated automatically: after adding a migration, run `php bin/console doctrine:migrations:migrate --env=test --no-interaction`, otherwise every page smoke test fails with a 500 (`no such column …`).
- Twig parses `{{` anywhere in a template, including inside inline `<script>` comments: a JSDoc type like `{{variant?: string}}` in a `.html.twig` file breaks rendering of every page that includes it. Use `{Object}` + `[options.variant]` style JSDoc instead.
- Run locally with `./bin/run-tests.sh` (wraps `bin/phpunit`, writes a JUnit report to `var/test-report/junit.xml` and a testdox report to `var/test-report/testdox.txt`).
- **Admin → Unit tests** (`/admin/tests`) runs the same suite from the running app (via `Symfony\Component\Process\Process`, using `PhpExecutableFinder` to invoke the exact PHP binary serving the request rather than relying on `env php`'s `PATH` resolution) and shows a pass/fail report per test, plus the raw process output if the run couldn't even produce a report. `App\Service\TestReportReader` parses the JUnit XML for both this page and the admin index's compact summary. Before each run it deletes `var/cache/test`: when the tests run without debug (`APP_DEBUG=0`, as on a server or in the Docker image, whose `var/` is a persisted volume), Symfony never checks whether the compiled test container is still up to date, so one compiled before a code update would keep being reused and the suite would fail on services that no longer match the code ("non-existent service", "Too few arguments to function …::__construct()"). The run is therefore a few seconds slower (cold compile).
- The test environment forces `APP_ENV=test` in `tests/bootstrap.php` (`putenv()` + `$_ENV`/`$_SERVER`) rather than relying solely on `phpunit.dist.xml`'s `<server force="true">`: the Docker image sets `APP_ENV=prod` as a real container environment variable, which otherwise wins and makes `WebTestCase::createClient()` fail with "framework.test is not set to true".

## Project structure

- `src/Controller` — one controller per resource (Account, AccountType, Category, Person, Transaction, Dashboard, Admin, Translation).
- `src/Entity` / `migrations` — Doctrine ORM entities and SQLite migrations.
- `src/Form` — Symfony form types.
- `src/Service/DatabaseBackupService.php` — automatic/manual SQLite backup logic.
- `src/Service/CalculationCache.php` — per-entity cache of the balance / sum / report calculations (see Calculation cache above).
- `src/Service/BudgetForecastService.php` — dashboard reports (monthly income/expense, daily balances, per-tag breakdown, savings interest projection), see Performance above.
- `src/Service/RecurringTransactionGenerator.php` — recurring transaction logic: automatic catch-up generation and the manual monthly duplication page (see Recurring transactions above).
- `src/Service/PoTranslationManager.php` — reads/writes the `.po` translation catalogues used by the Translate interface page.
- `src/Service/CurrencyResolver.php` — resolves ISO 4217 currency codes to display symbols and guesses a default currency for a locale (see Currency above).
- `src/Twig/AppExtension.php` — Twig extension exposing, among others, the `money` filter and `currency_symbol()` function.
- `src/EventListener/` — cross-cutting request/exception hooks: site-wide locale (`LocaleSubscriber`), the foreign-key deletion safety net (`ForeignKeyConstraintExceptionListener`), the automatic backup after each flush (`DatabaseBackupListener`) and the calculation cache invalidation (`CalculationCacheInvalidator`).
- `src/Service/TestReportReader.php` — parses the JUnit report used by the admin "Unit tests" page.
- `tests/` — PHPUnit test suite (see Testing below).
- `templates/` — Twig templates (Bootstrap 5 UI).

## License

Proprietary — see `composer.json`.
