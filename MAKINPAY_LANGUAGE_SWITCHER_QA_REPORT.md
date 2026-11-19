# MakinPay — Language Switcher Visibility QA Report

**Branch:** `fix/homepage-language-switcher` (unchanged; nothing committed, pushed or merged)
**Scope:** the language switcher missing from the frontend header
**Databases used:** `makinpay_qa` — **additive writes only** (two language rows via the shipped seeder).
No schema change, no reset, no truncation, no deletes.
**Environment:** Laravel 11.51.0 · PHP 8.3.33 · MySQL 8.4.3 · Windows / Laragon

---

## 0. Verdict

**RESOLVED.** The switcher is visible and working on desktop and mobile.

Root cause was **not** CSS, not JavaScript, and not the layout wiring — the include chain was
correct all along. Two things combined:

1. **`makinpay_qa` had zero rows in `languages`.** With no rows the partial's `@if($currentLanguage)`
   guard evaluates false and the entire control renders nothing. The page still returned **200**,
   so nothing looked broken except the absence itself.
2. **A genuine code defect made "current language" misleading** even once rows existed: the
   resolution `firstWhere(locale) ?? first()` labelled the *first row* as active whenever the
   active locale had no row.

Both are fixed; verification and remaining limitations are in §5 and §6.

---

## 1. Root cause

### 1.1 What was rendered

```
GET /  →  200 OK, 117,834 bytes
grep 'language-selector'  →  0 matches
grep 'data-bs-toggle="dropdown"'  →  0 matches
```

Zero switcher markup in the response — the control was never emitted, so no CSS or JS could have
been involved. That immediately excludes the usual suspects (opacity/overflow/`display:none`/
breakpoint rules), which were all checked anyway in §1.5.

### 1.2 The data chain, step by step

| Step | State |
| --- | --- |
| `app.blade.php` includes `_header_top` (desktop) and `_offcanvas` (mobile) | ✅ correct |
| both include `frontend.layouts.partials._language_switcher` | ✅ correct |
| `ViewComposerServiceProvider::composeLanguages()` registered on that exact view name | ✅ correct |
| `Language::activeCached()` → `Cache::rememberForever('languages', …)` | ✅ correct |
| **`languages` table row count** | ❌ **0** |
| `$currentLanguage = … ?? null` | `null` |
| `@if($currentLanguage)` | **false → nothing rendered** |

Confirmed directly:

```
$ php artisan tinker --execute="echo \App\Models\Language::activeCached()->count();"
0
$ mysql -e "SELECT * FROM makinpay_qa.languages;"
Empty set
```

### 1.3 Why the table was empty

The baseline-seeding fix (commit `95ea190f`, D1 in the previous report) added `LanguageSeeder` to
`installer.core_seeders` **and** `installer.reconcile_seeders`. That fixes fresh installs and gives
existing installs a repair path — but `makinpay_qa` was installed *before* that commit and
`install:reconcile` had never been run against it. So the database was simply stale relative to the
code, which is exactly the state `install:reconcile` exists to correct.

### 1.4 A second, real defect: the cache pinned the empty result

`activeCached()` uses `rememberForever`, so the empty collection was written to the cache with no
expiry:

```
Cache::get('languages')  →  Illuminate\Database\Eloquent\Collection count=0
```

The consequence is subtle and worth stating plainly: **inserting rows alone would not have brought
the switcher back.** It only recovered because `Language` flushes the cache from its
`saved`/`deleted` model hooks, and `LanguageSeeder::flushCache()` runs at the end of the seeder.
An operator who added languages by any path that bypassed Eloquent events — a raw SQL insert, a
restored dump, a direct admin tool — would have kept an invisible switcher indefinitely with the
rows sitting right there in the table.

This is now documented in §6 as a residual risk rather than changed: `rememberForever` is a
deliberate performance choice used consistently across the model (`default_language`,
`languagesPluck`, `languageRtlCodes`), and the fix belongs at the write path, not in this partial.

### 1.5 Everything that was checked and cleared

| Candidate | Finding |
| --- | --- |
| Layout / header wiring | Correct in all four layouts |
| Composer registration | Correct — registered on the partial's own view name |
| Active language records | **Empty — the cause** |
| Application locale | `en`, correct |
| CSS visibility (`display`, `opacity`, `overflow`, `z-index`) | `.language-selector { display:inline-block }`; `.top-right { display:flex }`; no hiding rule |
| Responsive breakpoints | `.header-top-section { display:none }` below 992 px — **by design**, `.header__hamburger`/`.sidebar-options` take over and the offcanvas carries its own switcher |
| Bootstrap dropdown JS | `bootstrap.bundle.min.js` present in `_script.blade.php` and in the rendered page |
| Flag assets | `public/general/static/flags/us.svg` and `ar.svg` both exist |
| `displayName()` | Exists on the model, native-name-then-name fallback |

### 1.6 This also explains the earlier "RTL never flipped" observation

`LocaleController::setLocale()` resolves `Language::where('code', $locale)->first()` **before**
touching the session. With an empty table that returned `null`, it redirected back with an error
notification and never stored the locale. After seeding, the same request works:

```
GET /locale-set/ar  →  302
GET /               →  200  <html lang="ar" dir="rtl">
```

So the RTL failure reported in the media-recovery round and the invisible switcher had a single
cause: an empty `languages` table.

---

## 2. Files changed

**New (2)**

| File | Purpose |
| --- | --- |
| `app/Support/LanguageSwitcher.php` | Shared current-language resolution, render decision, flag fallback label, one-shot diagnostics |
| `tests/Unit/Frontend/LanguageSwitcherTest.php` | 8 unit tests |
| `tests/Feature/Frontend/LanguageSwitcherTest.php` | 8 feature tests |

**Modified (4)**

| File | Change |
| --- | --- |
| `resources/views/frontend/layouts/partials/_language_switcher.blade.php` | Classic switcher — uses shared resolver, flag fallback, a11y attributes |
| `resources/views/frontend/layouts/golden/partials/_language_switcher.blade.php` | Same resolver + flag fallback |
| `resources/views/frontend/layouts/user/partials/_language_switcher.blade.php` | Same resolver + `displayName()` |
| `public/frontend/css/main.css` | +18 lines: `.language-selector .flag-icon-fallback` |

**Preserved untouched** — the whole media-recovery change set: `app/Support/PublicMedia.php`,
`app/Console/Commands/AuditMedia.php`, `app/Models/PageComponent.php`,
`resources/views/frontend/pages/index.blade.php`, `tests/TestCase.php` (storage isolation),
`tests/Feature/Media/`, `tests/Unit/Media/`. `git status storage/app` → **0 modified files**.

**Data repaired in `makinpay_qa` (additive, non-destructive):**

```
$ php artisan db:seed --class=LanguageSeeder --force
code  name      flag                            is_rtl  is_default  status
en    English   general/static/flags/us.svg     0       1           1
ar    Arabic    general/static/flags/ar.svg     1       0           1
```

`LanguageSeeder` uses `firstOrCreate`, so it can never overwrite an administrator's languages,
flags or default. `install:reconcile` would have produced the identical result; the narrower
command was used because it touches one table.

---

## 3. Implementation details

### 3.1 One resolver, three switchers — no new duplication

The three switchers had drifted: three copies of the same "which language is current" expression,
written twice correctly and once misleadingly. `LanguageSwitcher` now owns that decision and all
three partials call it. Markup, classes and layout stay exactly as each theme designed them.

```php
// Order of preference: the active locale, then the site's default, then first row.
public static function current(?Collection $languages, ?string $locale = null): ?Language
public static function shouldRender(?Collection $languages): bool
public static function flagFallback(?Language $language, ?string $fallbackCode = null): string
```

### 3.2 Correct current language

The old rule — `firstWhere('code', locale) ?? first()` — **claimed the first row was active**
whenever the active locale was not in the table. A site running in `fr` with `en`/`ar` rows
displayed "English" as though English were selected. The new order is:

1. the row matching `app()->getLocale()` — the only genuinely correct answer;
2. otherwise the row marked `is_default` — honest about the site's configured intent;
3. otherwise the first row.

The dropdown's `active` marker still compares codes directly, so it never lies about which row the
session is actually on.

### 3.3 Flag fallback

`flag` is `NOT NULL` in the schema, so "no flag" arrives as an **empty string**, not `null`. Both
are falsy, and both previously produced either nothing (classic, golden) or a differently-sized
span (user). The partials now render a code badge in exactly the flag's box, so the toggle and menu
rows keep their alignment:

```blade
@if($currentLanguage->flag)
    <img src="{{ asset($currentLanguage->flag) }}" … class="flag-icon">
@else
    <span class="flag-icon flag-icon-fallback" aria-hidden="true">EN</span>
@endif
```

The badge reuses existing design tokens (`--front-palette-f0f4f8`, `--front-palette-333`,
`--front-font-semibold`) and takes its width/height from `.flag-icon` / `.flag-icon-small`, so no
new dimensions were invented.

### 3.4 Empty and single-language collections

- **Empty** → renders nothing. No exception, no broken control, homepage stays `200`. Unchanged
  behaviour, now covered by a test.
- **One language** → still renders. Deliberately *not* hidden: the switcher is also the only place
  a visitor can see which language they are reading, and hiding it would contradict "make the
  switcher visible". This preserves existing behaviour rather than changing it.

### 3.5 Diagnostics

Silent invisibility is what made this expensive to diagnose, so `LanguageSwitcher` logs a warning
once per request when the collection is empty, and once per locale when the active locale has no
row. Memoised in a static array so a page rendering two switchers logs once, not twice.

### 3.6 Accessibility

Added without altering appearance: `aria-label="{{ __('Select language') }}"` on the toggle,
`hreflang` on each option, and `aria-current="true"` on the active one. The label is translated —
under `ar` it renders `اختر اللغة`.

### 3.7 RTL

No directional CSS, no `left`/`right`, no float was added. The badge is a centred inline-flex box
and inherits direction from the document. Verified rendering under `dir="rtl"` (§5).

---

## 4. Test results

**New 16 tests / 62 assertions**

`tests/Unit/Frontend/LanguageSwitcherTest.php` (8):
picks the matching row · falls back to default when locale unlisted · falls back to first when no
default · returns null + warns once for empty/null · warns once per locale · `shouldRender` · code
badge construction · `displayName()` native-name preference.

`tests/Feature/Frontend/LanguageSwitcherTest.php` (8, `@group serial`):
1. **switcher included in the active homepage layout** — asserts `class="language-selector"`
   appears **exactly twice** (header strip + offcanvas; losing either breaks one viewport only);
2. labels itself with active language, flag and dropdown attributes;
3. every language links to `route('locale-set', code)`, active row marked;
4. **locale switching works and reverses** — `ar` gives `<html lang="ar" dir="rtl">`, `en` gives
   `<html lang="en" dir="ltr">`;
5. **locale fallback** — locale `fr` with no row presents the default language;
6. **empty collection** — homepage still `200`, no switcher markup;
7. single active language still renders;
8. missing flag renders the `AR` code badge.

**Full suite**

| | Before this task | After |
| --- | --- | --- |
| Tests | 140 passed | **156 passed** |
| Skipped | 5 | 5 (opt-in MySQL group) |
| Assertions | 511 | **573** |
| Duration | 234.90 s | 500.61 s |
| Failures | 0 | **0** |

`156 = 140 + 16`. Media-recovery tests continue to pass, confirming the storage-isolation fix from
the previous round still holds (`git status storage/app` → 0 modified files after a full run).

---

## 5. Desktop / mobile verification

Verified over HTTP against `php artisan serve` on `127.0.0.1:8341` with the real `makinpay_qa`.

| Check | Result |
| --- | --- |
| Homepage HTTP status | **200** |
| Switcher instances in HTML | **2** (desktop header + offcanvas) |
| Active language displayed | **English** (with `us.svg` flag) |
| Locale links | `/locale-set/en`, `/locale-set/ar` |
| Active row marked | `dropdown-item … active` |
| Dropdown wiring | `data-bs-toggle="dropdown"`, `aria-expanded="false"`; `bootstrap.bundle.min.js` loaded |
| Locale change | `GET /locale-set/ar` → **302** → page renders `<html lang="ar" dir="rtl">` |
| Reverse switch | `/locale-set/en` → **302** → `<html lang="en" dir="ltr">` |
| RTL switcher | current = **Arabic** (`ar.svg`), label `اختر اللغة`, English listed as alternative |
| Missing flag | code badge `AR` rendered via `.flag-icon-fallback` |
| New CSS served | `.language-selector .flag-icon-fallback` present in `/frontend/css/main.css` |
| Blade compilation | `php artisan view:cache` → all templates cached |
| Logs | no new `ERROR`; 0 unresolved-language warnings from live requests |

**Desktop** — `.header-top-section` is `position:absolute; z-index:99999`, `.top-right` is
`display:flex` with `gap:30px`; the switcher is the first child alongside social icons.

**Mobile** — below 992 px `.header-top-section` is `display:none` by design and
`.header__hamburger`/`.sidebar-options` switch to `display:block`; the offcanvas drawer carries its
own switcher instance (`text-dark` variant for the white panel), confirmed present in the HTML:

```html
<div class="offcanvas__top …">
  <div class="offcanvas__logo">…</div>
  <div class="language-selector"> … <span class="language-name text-dark">English</span> … </div>
```

### 5.1 What remains unverified

No headless browser, Playwright or Puppeteer is installed in this project, so:

- **I did not visually confirm the dropdown opening.** The evidence is structural: Bootstrap's
  bundle is loaded, and the toggle carries the `data-bs-toggle="dropdown"` /
  `aria-expanded="false"` contract Bootstrap's Dropdown plugin binds to. That is strong but not the
  same as a click.
- **No screenshots** were taken, so pixel alignment of the new code badge next to real flags was
  not eyeballed. Its box is copied from `.flag-icon`/`.flag-icon-small`, so it should align, but
  this is asserted by construction, not observation.
- **No viewport emulation.** Desktop/mobile were verified by reading the CSS breakpoints and the
  rendered markup, not by resizing a browser.

Server-side behaviour (inclusion, current language, links, locale switching, RTL, empty state) is
verified directly and is where the defect lived.

---

## 6. Remaining limitations

1. **Stale empty collection in `rememberForever('languages')`.** If languages are ever inserted by a
   path that does not fire Eloquent's `saved`/`deleted` events (raw SQL, restored dump, an external
   admin tool), the switcher stays invisible until the cache is cleared. Mitigations exist today
   (`LanguageSeeder::flushCache()`, model hooks) but no defence covers the raw-write case. A
   `makinpay:language:sync` or a TTL on that key would close it.
2. **Stale `makinpay_qa` is not self-diagnosing.** The switcher vanished with no operator-visible
   signal beyond the new log warning. Consider running `install:reconcile` as a documented upgrade
   step (already recommended in the previous report) so databases follow the code automatically.
3. **`install:reconcile` was not run in full.** Only `LanguageSeeder` was applied to `makinpay_qa`.
   Other reconcilers may still be outstanding on that database; they are additive and safe to run
   when convenient.
4. **One warning per request, not per site.** The empty-locale warning repeats on every request
   (memoised only within one). A busy site with a misconfigured table would produce a lot of log
   lines — deliberately chosen over silence, but worth revisiting if it becomes noisy.
5. **Single-language sites still show a one-item dropdown.** Preserved deliberately (see §3.4); if
   the product prefers hiding it, that is a one-line change in `shouldRender`.
6. **The Golden and user-panel switchers were updated but not visually verified.** They share the
   resolver and compile cleanly, but only the classic theme is rendered by the homepage under
   `makinpay_qa`'s active theme.
7. **`composer test:mysql` still outstanding.** The suite runs on SQLite; nothing here is
   driver-specific, but the MySQL path remains unexercised (carried over from the previous two
   reports).
