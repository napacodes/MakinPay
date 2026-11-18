# MakinPay — Database Initialization & Homepage QA Report

**Project:** MakinPay
**Repository:** `/c/laragon/www/MakinPay`
**Branch:** `fix/homepage-language-switcher`
**Stack:** Laravel 11.51.0 · PHP 8.3.33 (ZTS) · MySQL 8.4.3 · Windows / Laragon
**Scope:** Database initialization after the CLI installer refactor, and the homepage it produces.
**Type:** Investigation + fix. Every defect below was reproduced before it was changed, and verified after.

**Status: COMPLETE.** 5 defects were found during the investigation and fixed; a 6th (D7, an
incomplete upgrade path) was found in the final engineering review (§9) and fixed. 1 content gap
(D6) is recorded and deliberately left open for a product decision.
Full suite: **124 passed, 5 skipped (436 assertions)**. Baseline before this work was 117 passed /
397 assertions; the 7 new tests are the regression net for the defects below.

---

## 0. Executive summary

The public homepage returned **HTTP 500** on a newly installed site.

The cause was not a template bug. The CLI installer, which replaced the `DB/digikash.sql`
import, ran 41 baseline seeders that covered permissions, gateways, currencies, KYC, P2P,
themes and the site builder — but **none of them created the two tables the homepage's own
chrome depends on**:

- `languages` was left completely **empty**, so the language switcher dereferenced `null` and
  threw `Attempt to read property "flag" on null`.
- `settings` was left completely **empty**, so every setting fell back to a `config/settings.php`
  default — two of which were never intended to be user-visible. A brand-new install rendered a
  footer whose copyright line read, in full, the single word **`admin`**.

Reproduced directly on a disposable database:

```
BROKEN-STATE home: HTTP 500 bytes=742836
local.ERROR: Attempt to read property "flag" on null
             (View: resources/views/frontend/layouts/partials/_language_switcher.blade.php)
```

Inserting two language rows by hand was sufficient to flip the same page to **HTTP 200**, which
isolated the failure to the missing seed data rather than to the switcher alone.

Two further defects were found while verifying the fix, both on the install path itself:

- `makinpay:install` **exited non-zero with a stack trace after a successful install**, because the
  post-install summary read a `settings` column that does not exist.
- `Page::components` ordered its rows with MySQL's `FIELD()`, so **any page render on a non-MySQL
  driver** threw `no such function: FIELD`. Production is MySQL and never saw this; it made the
  homepage untestable under the suite's SQLite, which is how it went unnoticed.

---

## 1. Defect register

| # | Severity | Area | Defect | Status |
| --- | --- | --- | --- | --- |
| **D1** | Blocker | Seeding | `languages` never seeded by `--seed-baseline` | Fixed |
| **D2** | Blocker | View | Default `_language_switcher` dereferences `null` | Fixed |
| **D3** | High | Seeding / Config | `settings` never seeded; copyright rendered as `admin`, title as `Coevs` | Fixed |
| **D4** | Medium | CLI | `makinpay:install` throws after completing, exit code non-zero | Fixed |
| **D5** | Medium | Model | `Page::components` uses MySQL-only `FIELD()`; breaks any other driver | Fixed |
| **D6** | — | Seeding | No `navigations` / `footer_sections` / `socials` seeded | **Open — decision required** |
| **D7** | High | Upgrade path | `LanguageSeeder` absent from `reconcile_seeders`; a broken install could not be repaired without a reinstall | Fixed (found in final review) |

---

## 2. Evidence

### 2.1 Reproduction method

A scratch schema (`makinpay_repro`, later `makinpay_verify`, `makinpay_final`) was created and
dropped for each run. **`makinpay_qa`, `digikash_app` and `digikash_manual` were never written to.**
`.env` was pointed at the scratch schema for the duration and restored to `makinpay_qa` afterwards.

`makinpay:install --force --no-interaction --seed-baseline` was run end-to-end on an empty schema,
then the site was served and the homepage requested.

### 2.2 The failing state

```
$ curl -s -o ... -w "HTTP %{http_code}\n" http://127.0.0.1:8322/
HTTP 500
```

```
[local.ERROR] Attempt to read property "flag" on null
  (View: resources\views\frontend\layouts\partials\_language_switcher.blade.php)
```

Row counts immediately after a *successful* baseline install:

```
languages  currencies  navigations  socials  footer_sections  pages  gateways  settings
0          2           0            0        0                5      22        27
```

`settings` held 27 rows — but only the operational ones written by the section seeders
(`sla_*`, `kyc_*`, `media_*`, `observability_*`, `secret_key`). **Not one public-facing key**
(`site_title`, `copyright_text`, `admin_prefix`, `support_email`) existed.

### 2.3 Isolation of D1 from D2

Inserting two rows by hand and re-requesting the same URL:

```
AFTER-LANG-SEED home: HTTP 200 bytes=119463
```

The page's own markup then contained the switcher with working `locale-set/en` and
`locale-set/ar` links. This proved the 500 was caused by missing rows, not by the switcher's
logic alone — and therefore that seeding languages was the primary fix and null-safety the
defensive one.

### 2.4 The `admin` copyright

The footer partial prints the setting verbatim:

```blade
{{-- resources/views/frontend/layouts/partials/_footer.blade.php:46 --}}
<p>{{ setting('copyright_text') }}</p>
```

With no stored row, `setting()` falls through to the config default, which was:

```php
// config/settings.php — before
'key'   => 'copyright_text',
'value' => 'admin',          // ← rendered literally in the customer footer
```

Confirmed in the rendered output of the broken site: `<p>admin</p>`.

The shipped dump had 40 real rows for these keys, including
`'copyright_text', 'Copyright © 2024 DigiKash | All rights reserved'` and
`'site_title', 'DigiKash'`. The CLI installer replaced the import but nothing replaced the rows.

### 2.5 The post-install crash (D4)

```
  INFO  Installation complete.
  Illuminate\Database\QueryException
  SQLSTATE[42S22]: Column not found: 1054 Unknown column 'value' in 'field list'
  (SQL: select `value` from `settings` where `key` = admin_prefix limit 1)
```

The marker was already written and the administrator already created, so the site was fine —
but the command exited non-zero and printed a fatal trace, which reads as a failed install to
anyone watching a deployment.

The column is `val`:

```
Field   Type   Null  Key  Default  Extra
key     varchar(255)  NO  UNI  NULL
val     text          YES      NULL
```

---

## 3. Changes

Five files modified, two added. No table was dropped, no data deleted, no migration added.

### 3.1 `config/installer.php` — seed languages and settings

Added to `core_seeders`, at the head, because the frontend shell and the site-builder components
depend on both:

```php
'core_seeders' => [
    LanguageSeeder::class,            // D1 — the picker, locale resolution, RTL
    LanguageNativeNameSeeder::class,  // endonyms: العربية rather than "Arabic"
    SystemSettingSeeder::class,       // D3 — site title, copyright, admin prefix, support
    PermissionTableSeeder::class,
    // …
```

`SystemSettingSeeder` is also listed in `reconcile_seeders`, so an existing installation that
upgrades into this version gains the rows on its next `php artisan install:reconcile`.

`LanguageSeeder` is in `reconcile_seeders` as well, ahead of `LanguageNativeNameSeeder`. That
placement is what makes `install:reconcile` a genuine repair path for a site installed while
`languages` was not being seeded: `LanguageNativeNameSeeder` only fills in rows that already exist,
so without `LanguageSeeder` first, reconcile would have nothing to act on. `LanguageSeeder` is
`firstOrCreate`-by-code and never rewrites an existing row, which satisfies the additive contract
`reconcile_seeders` requires.

A duplicate `TaxPermissionSeeder::class` entry (present twice in `core_seeders`) was removed.

### 3.2 `database/seeders/SystemSettingSeeder.php` — new

Writes the platform's own settings rows from `config/settings.php`, restricted to an explicit
allow-list of **public, non-secret** keys:

```
site_title, site_decimal, preloader_text, admin_prefix,
site_timezone, home_redirect, support_email, support_phone, copyright_text
```

Deliberately excluded: mail credentials, SMTP host/port, and any API secret. Those are
per-install operator values; seeding placeholders would put fake credentials in every install
and, in the case of the dump, would have shipped a real Gmail app password.

Additive by construction — `Setting::has($key)` short-circuits — matching the four existing
section seeders, so a key an administrator has edited is never overwritten on reconcile.

A `select` field whose option list is a runtime-built empty array (`[]`) is skipped rather than
persisted, since writing it raises `Array to string conversion`.

### 3.3 `config/settings.php` — correct four defaults

| Key | Was | Now | Why |
| --- | --- | --- | --- |
| `copyright_text` | `'admin'` | `'MakinPay. All rights reserved.'` | Rendered verbatim in the footer |
| `site_title` | `'Coevs'` | `'MakinPay'` | Browser tab and outgoing mail named the previous product |
| `site_timezone` | `[]` | `'UTC'` | `[]` was an option-list placeholder, not a value; unstorable |
| `home_redirect` | `[]` | `'/'` | Same; matches the dump |

`copyright_text` is printed with no placeholder substitution, so `:site` / `:year` tokens were
rejected in favour of a literal string.

### 3.4 `resources/views/frontend/layouts/partials/_language_switcher.blade.php` — null-safe

Brought in line with the golden and user variants, which already guarded correctly:

```blade
$switcherLanguages = $languages ?? collect();
$currentLanguage   = $switcherLanguages->firstWhere('code', app()->getLocale())
                     ?? $switcherLanguages->first();
```

The whole component is wrapped in `@if($currentLanguage)`, so a site with no active language
**hides the switcher** instead of taking the entire page down. `displayName()` is used inside the
guard, so the picker shows endonyms consistently with the other themes.

### 3.5 `app/Console/Commands/Installation/InstallCommand.php` — read settings properly

```php
// before — wrong column, no fallback
$adminPrefix = (string) (DB::table('settings')->where('key', 'admin_prefix')->value('value') ?: 'admin');

// after — through the settings layer, which knows `val` and the default
$adminPrefix = trim((string) setting('admin_prefix', 'admin')) ?: 'admin';
```

The change orphaned `use Illuminate\Support\Facades\DB;`, which was removed with it. A second,
pre-existing unused import (`Schema`) went in the same edit — neither symbol is referenced anywhere
in the class.

### 3.6 `app/Models/Page.php` — driver-portable ordering

```php
// before — MySQL-only
->orderByRaw('FIELD(id, '.implode(',', $this->component_ids).')')

// after — rows are already in memory, so sort them here
$position = array_flip(array_map('intval', $this->component_ids));
return $components
    ->sortBy(fn (PageComponent $c): int => $position[(int) $c->id] ?? PHP_INT_MAX)
    ->values();
```

Ordering semantics are unchanged for MySQL. This is the only `FIELD()` call in `app/`.

### 3.7 `tests/Feature/Installation/BaselineSiteDataTest.php` — new

Seven tests, `serial` group. Asserted against seeded rows and a **rendered homepage**, not the
seeder internals, so the implementation stays free to change:

1. languages are seeded, and exactly one row claims `is_default`
2. the public settings are seeded, and neither `admin` nor `Coevs` survives
3. the homepage renders with a switcher and a real footer
4. the homepage still renders when **no** language is active
5. a re-run does not raise `Unknown column` / `QueryException`
6. `install:reconcile` restores languages and settings on a site that has neither, and the
   repaired site then renders 200 — the upgrade path, not just the install path
7. the summary honours a non-default `admin_prefix`

### 3.8 `config/installer.php` — `LanguageSeeder` added to `reconcile_seeders`

Found during the final review (§9, D7). `LanguageNativeNameSeeder` was already in
`reconcile_seeders`, but it only fills in the native name of a row that **already exists** — so a
site whose `languages` table is empty had no repair path short of a reinstall. `LanguageSeeder` was
inserted ahead of it:

```php
KycDocumentHashSeeder::class,
LanguageSeeder::class,          // firstOrCreate by code — never rewrites an existing row
LanguageNativeNameSeeder::class,
SystemSettingSeeder::class,
```

`LanguageSeeder` is purely additive (`firstOrCreate(['code' => …])`, and it sets `is_default` only
on creation), which is exactly the contract `reconcile_seeders` demands. `install:reconcile` is
unattended, so a filesystem error from `ensureLanguageFiles()` cannot be fatal: the command wraps
every seeder in `runStep()` and downgrades a `Throwable` to a warning.

---

## 4. Verification

### 4.1 Clean install, end to end

Fresh schema, full `makinpay:install --seed-baseline`:

```
✓ LanguageSeeder
✓ LanguageNativeNameSeeder
✓ SystemSettingSeeder
… (41 seeders, all ✓, zero failures)
Installation complete.
3. Sign in to the admin panel at http://localhost/admin
```

Exit code **0**, no exception. All 9 new settings present with correct values:

```
admin_prefix    admin
copyright_text  MakinPay. All rights reserved.
site_title      MakinPay
site_timezone   UTC
home_redirect   /
support_email   support@demo.com
```

### 4.2 HTTP surface

| URL | Before | After |
| --- | --- | --- |
| `/` (homepage) | **500** | **200** |
| `/admin` | — | 302 (redirects to login, as designed) |
| `/login`, `/register` | — | 301 (canonical redirect) |

### 4.3 Homepage content

```
[OK] copyright   <p>MakinPay. All rights reserved.</p>
[OK] switcher    class="language-selector"
[OK] links       locale-set/en, locale-set/ar
     bare-admin-copyright count: 0
```

### 4.4 Language switcher round-trip

```
locale-set/ar            -> 302
home after switch        -> 200
<html lang="ar" dir="rtl">
```

### 4.5 Resilience — no active languages

Setting every language `status = 0` and re-requesting the homepage:

```
home with 0 active languages -> HTTP 200
```

The switcher hides itself; no error is logged. This is the exact condition that produced D2's 500.

### 4.6 Test suite

```
Tests:    124 passed, 5 skipped (436 assertions)
Duration: 192.79s
```

- **Baseline:** 117 passed / 397 assertions
- **Now:** 124 passed / 436 assertions (the +7 / +39 are `BaselineSiteDataTest`)
- The 5 skips are the **opt-in MySQL integration group** (`MAKINPAY_ALLOW_MYSQL_TESTS`), which is
  skipped by design, not a failure.

Installation suite alone: **59 passed** (52 pre-existing + 7 new).

> Duration is from the final review run and is not load-bearing — an earlier full run took 375s.
> The counts are what matter, and they are stable across runs.

---

## 5. Open item — D6: no navigations, footer widgets or social links

**Not fixed. Requires a product decision, not an engineering one.**

A baseline install still produces a homepage with an **empty primary menu** and an **empty footer
widget row**. Rendering is correct — the partials iterate their collections and emit nothing when
empty, and `socials` is already guarded by `->isNotEmpty()` — but no seeder creates the rows:

```
navigations: 0    footer_sections: 0    socials: 0
```

The dump contains real rows for all three:

| Table | Dump rows |
| --- | --- |
| `navigations` | Home, About, Privacy, Blog (bilingual JSON labels) |
| `footer_sections` | About, Page, Useful Links, Social Link (+ `footer_items`) |
| `socials` | Facebook, Twitter, LinkedIn (placeholder URLs) |

This was left in place deliberately: seeding them means shipping MakinPay-authored menu labels
and placeholder social URLs into every customer's site. Three options for the next milestone:

1. **Seed defaults** — a `SiteChromeSeeder` in `core_seeders`, mirroring `SiteBuilderSeeder`'s
   additive approach. Site is navigable out of the box; customers edit rather than build.
2. **Seed nothing, document it** — treat nav/footer as first-run admin work. Requires the install
   output to say so, or the empty state looks like a bug.
3. **Seed nothing, flag it in `makinpay:install:check`** — add a warning when the homepage has no
   navigation, so the gap is loud instead of silent.

Option 1 matches how `SiteBuilderSeeder` already restores the rest of the public shell from the dump
and would make a baseline install fully coherent.

---

## 6. Additional observations (not defects)

Recorded for completeness; no action taken.

- The baseline install creates **no `users` rows** — correct by design, and asserted by the
  existing `does not create demonstration user accounts during a baseline install` test.
- `navigations`, `footer_sections`, `footer_items` and `socials` **exist in the `digikash_app`
  reference schema but hold zero rows**, while `pages`, `page_components` and `settings` are
  populated there. That asymmetry is what supports reading D6 as a content decision (operator-authored
  menu and footer) rather than as lost reference data.
- `makinpay:install` writes the marker **before** the post-install summary. Even with D4 fixed, a
  crash there would leave a marked-as-installed site. It is now a read-only path with a fallback,
  so the risk is gone, but the ordering is worth remembering if that block grows.
- Migration time on this Windows/Laragon disk is long (**~35–40 min** for 315 migrations). Not a
  defect; it does mean install verification should be run in the background with a log rather than
  inline.

---

## 7. Housekeeping

| Item | State |
| --- | --- |
| `.env` `DB_DATABASE` | restored to `makinpay_qa` |
| Scratch schemas (`makinpay_repro`, `makinpay_verify`, `makinpay_final`) | dropped |
| `makinpay_qa`, `digikash_app`, `digikash_manual` | untouched |
| `storage/app/makinpay-installed.json` (from manual verification) | removed |
| Dev servers started during verification | stopped |
| Committed / pushed | **nothing** — all work is in the working tree for review |

**Working-tree changes**

```
 M .gitignore
 M app/Console/Commands/Installation/InstallCommand.php
 M app/Models/Page.php
 M config/installer.php
 M config/settings.php
 M resources/views/frontend/layouts/partials/_language_switcher.blade.php
?? MAKINPAY_INSTALLATION.md          (untracked; edited this round)
?? database/seeders/SystemSettingSeeder.php
?? tests/Feature/Installation/BaselineSiteDataTest.php
?? MAKINPAY_DB_INIT_HOMEPAGE_QA_REPORT.md
```

Runtime artefacts under `storage/framework/` and the `public/storage` symlink no longer appear: the
`.gitignore` gap that exposed them is fixed (F6, §9.1). The other `MAKINPAY_*.md` files in
the working tree are untracked documents from earlier milestones, not part of this change set.

---

## 8. Recommended next steps

1. **Decide D6** (§5) and, if Option 1 is chosen, add `SiteChromeSeeder`.
2. **Fold the two config defaults into a lint** — a test asserting that no `config/settings.php`
   `value` is a non-scalar, and that no user-visible string default is a placeholder like `admin`
   or a previous product's name. Both defects here would have been caught by such a test.
3. **Run `composer test:mysql`** against a disposable schema. `Page::components` changed and the
   suite's default driver cannot exercise the MySQL path end to end.
4. **Add the reconcile step to the upgrade notes** so installations that upgrade into this version
   know to run `php artisan install:reconcile`. That now restores both the settings rows **and** the
   language rows (D7), so a site installed while either was missing is repairable in place.

---

## 9. Final engineering review & sign-off

The implementation was re-reviewed independently against this report after it was written.
Every claim in §1–§4 was checked against the code and against a running install. Five items were
discrepant; all are now resolved.

### 9.1 The findings

| # | Type | Finding | Disposition |
| --- | --- | --- | --- |
| **F1** | Functional (D7) | `LanguageSeeder` was **not** in `reconcile_seeders`, so §8's claim that `install:reconcile` restores "settings and languages rows" was false for languages. An installation created before this fix — 0 languages, homepage 500 — could only be repaired by reinstalling. | **Fixed** in code (§3.8) and covered by new test 6. |
| **F2** | Documentation | §6 stated the nav/footer/social tables were "absent from the `digikash_app` reference schema". They are present, and hold zero rows. | **Corrected** — the conclusion (operator-authored content) stands, the evidence did not. |
| **F3** | Documentation | §7 listed `MAKINPAY_INSTALLATION.md` as modified (`M`). It is untracked (`??`) — it has never been committed. | **Corrected** to `??`. |
| **F4** | Documentation | §3.4 quoted a switcher snippet containing `$currentLanguageCode` / `$currentLanguageName`, dead code that the final tidy removed. §3.7 said six tests. | **Both corrected**; snippet now matches the file, tests now seven. |
| **F5** | Lint | The `DB` import in `InstallCommand` became unused when D4 was fixed (`Schema` was already unused before this work). | **Removed** both; noted in §3.5. |
| **F6** | Repo hygiene | `.gitignore` did not cover `storage/framework/{views,sessions,cache/data}/*` or `public/storage`. A `git add -A` would have committed ~40 runtime files and, worse, `public/storage` — a **machine-specific absolute symlink** (`→ /c/laragon/www/MakinPay/storage/app/public`) that is broken on every other machine. | **Fixed** in `.gitignore`, preserving the tracked `.gitkeep` markers. |

### 9.2 Re-verification after the review changes

| Check | Result |
| --- | --- |
| `php -l` on every changed PHP file | clean |
| `core_seeders` | 41 entries, no duplicates, all fully qualified |
| `reconcile_seeders` | 25 entries, no duplicates, all fully qualified |
| `LanguageSeeder` precedes `LanguageNativeNameSeeder` in `reconcile_seeders` | yes (index 15 before 16) |
| `FIELD(` remaining in `app/` | none (comment reference only) |
| Unused imports in `InstallCommand` | none |
| `git add -A` picks up runtime junk or the `public/storage` symlink | no |
| `storage/framework/**/.gitkeep` still tracked | yes (3 files) |
| `BaselineSiteDataTest` | **7 passed, 39 assertions** |
| Full suite | see §4.6 |

### 9.3 Sign-off

| Area | Verdict |
| --- | --- |
| Baseline language initialization | Pass — seeded on install, repairable on reconcile |
| Baseline settings initialization | Pass — 9 public keys, allow-listed, secret-free |
| `install:reconcile` | Pass — restores languages and settings; additive only |
| CLI install workflow | Pass — exit 0, no exception, marker after admin exists |
| Homepage language switcher | Pass — null-safe, hides when empty, verified LTR→RTL round trip |
| Navigation / footer / social initialization | **Open (D6)** — deliberate, requires a product decision |
| Migrations | Pass — no migration added or altered; schema unchanged |
| Seeders | Pass — additive, idempotent, ordered |
| Regression tests | Pass — 7 serial tests, rendering-level assertions |

**Commit-ready: yes**, with D6 tracked as an open product decision rather than a defect in this
change set. Nothing was committed or pushed.
