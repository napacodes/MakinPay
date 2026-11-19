# MakinPay — Homepage Media Recovery Report

**Branch:** `fix/homepage-language-switcher` (unchanged; nothing committed, pushed or merged)
**Scope:** the ~40 image `404`s reported on the homepage in Chrome DevTools
**Databases touched:** `makinpay_qa` — **read only**. No row was written, no schema altered, no reference edited.
**Environment:** Laravel 11.51.0 · PHP 8.3.33 · MySQL 8.4.3 · Windows / Laragon

---

## 0. Verdict

> ### PARTIALLY RECOVERED — ASSETS MISSING
> **Only in the sense that nothing had to be recovered: every file was already present.**
>
> All 40 reported images exist on the public disk, byte for byte, with their original
> filenames, folders, formats and dimensions. **Zero assets are missing.** The `404`s came
> entirely from URL generation (`/images/...` instead of `/storage/images/...`), which is now
> fixed. A home-page load went from **25/65 images served** to **65/65**, with no unresolved
> references left in the database.

Read this report in that light: §2 and §7 are short because there is nothing to recover and
nothing outstanding. The substantive findings are §1 (root cause), §3 (a test harness defect that
had begun **overwriting the real media**, found and fixed here) and §5 (the fix).

---

## 1. Root-cause analysis

### 1.1 What the browser asked for, and what was on disk

```
requested : http://127.0.0.1:8000/images/2025/04/10/20250410_183859_01_6Eac.jpg   → 404
on disk   : storage/app/public/images/2025/04/10/20250410_183859_01_6Eac.jpg      → 57,556 bytes
exposed at: http://127.0.0.1:8000/storage/images/2025/04/10/20250410_183859_01_6Eac.jpg → 200
```

`public/images/` **does not exist** and never did in this project. Uploads go to the `public`
disk (`storage/app/public`), which `php artisan storage:link` exposes at `/storage`.

### 1.2 Why the URL was built wrong

Content rows store **disk-relative** paths, exactly as the uploader writes them and exactly as the
DigiKash export contains them:

```json
"hero_main_image": "images/2025/04/10/20250410_055802_hero_1_Q0SS.png"
```

`resources/views/frontend/pages/index.blade.php` handed `content_data` straight to the section
views, and every section view wraps those values in `asset()`:

```blade
<img src="{{ asset($data['hero_main_image']) }}" ...>
```

`asset()` maps a relative path onto the **web root**, so `images/...` became `/images/...` —
a path with no directory behind it. The public disk lives one level deeper, under `/storage`.

### 1.3 This was an inconsistency, not a design decision

The codebase **already** resolves stored paths correctly in two places:

| Place | Behaviour |
| --- | --- |
| `Setting::normalizeStoredAssetPath()` (`app/Models/Setting.php:438`) | not in `public/`, but on the public disk → return `storage/<path>` |
| `Img::imageUrl()` (`app/View/Components/Img.php:47`) | same rule, with a placeholder fallback |

That is why the site logo, favicons, payment-gateway logos and other `setting()`-driven assets
were fine while page-builder content images were broken. The page sections simply did not use
either mechanism.

### 1.4 Classification against the requested list

| Candidate cause | Verdict | Evidence |
| --- | --- | --- |
| 1. Missing physical files | **No** | 40/40 present on the public disk; `getimagesize()` valid; sizes 157 B – 3.8 MB |
| 2. Incorrect image URL generation | **Yes — the only cause** | 40/40 broken URLs are `/images/...`; 25/25 working URLs are `/storage/...` or `public/general/static/...` |
| 3. Filesystem disk misconfiguration | **No** | `public` disk root `storage/app/public`, `url` `<APP_URL>/storage` — correct |
| 4. Missing / incorrect storage symlink | **No** | `public/storage → storage/app/public` present and serving 200 |
| 5. Incomplete DigiKash media migration | **No** | `digikash_app` and `makinpay_qa` hold identical paths; all files copied |
| 6. Incorrect database references | **No** | References match the DigiKash source and match the files on disk |

### 1.5 It was not limited to the migrated database

`database/seeders/SiteBuilderSeeder.php` seeds the very same `images/2025/...` paths. A **fresh
CLI install** therefore produced the same 40 `404`s — this was a defect in the shipped code, not
residue from the old dump.

---

## 2. Missing asset inventory

Measured on the running app **before** the fix: 65 distinct image URLs referenced, 25 returned
`200`, **40 returned `404`**.

| Component | Broken refs |
| --- | --- |
| `about` | 5 |
| `banner` | 5 |
| `blog` (title bar) | 1 |
| `contact` | 2 |
| `feature` | 1 |
| `offer` (CTA background) | 1 |
| repeated content (service icons, team photos, testimonial clients, feature icons, work-process steps) | 16 |
| `service` | 1 |
| `special_feature` | 2 |
| `subscribed` | 2 |
| `team` | 1 |
| `testimonial` | 1 |
| `work_process` | 2 |
| **Total** | **40** |

By upload date, all inside `storage/app/public/images/`: `2025/04/10` ×16, `2025/04/11` ×20,
`2025/04/13` ×2, `2025/04/14` ×2 — 4.98 MB in total.

Every one of the 40 paths is referenced from `page_components` / `page_component_repeated_contents`
(no reference resolved to a file elsewhere, and no file had zero references).

---

## 3. Recovered assets and their locations

**Nothing was recovered because nothing was lost.** For the record, the eight files named in the
task, verified in place:

| File | Path | Bytes |
| --- | --- | --- |
| `20250410_055802_hero_1_Q0SS.png` | `storage/app/public/images/2025/04/10/` | 221,089 |
| `20250410_060504_frame_1_YXLr.png` | `storage/app/public/images/2025/04/10/` | 3,974 |
| `20250410_060504_frame_2_P1Qe.png` | `storage/app/public/images/2025/04/10/` | present |
| `20250410_183859_01_6Eac.jpg` | `storage/app/public/images/2025/04/10/` | 57,556 |
| `20250411_070408_cta_offer_bg_BJfW.jpg` | `storage/app/public/images/2025/04/11/` | 91,229 |
| `20250411_133154_client1_ubBg.jpg` | `storage/app/public/images/2025/04/11/` | 6,333 |
| `20250411_134907_title_bar_wEBm.png` | `storage/app/public/images/2025/04/11/` | 157 |
| `20250411_134922_team1_Ma8U.jpg` | `storage/app/public/images/2025/04/11/` | 95,439 |

Integrity sweep of the whole media tree:

```
storage/app/public/images/**   234 image files
  valid raster/SVG              233
  flagged                       1  → images/2026/05/19/20260519_195121_flag_GgkO.png
```

The flagged file is an **SVG with a `.png` extension** (starts `<svg xmlns=…`, 23,024 B, tracked
and unmodified in git). Browsers render it; `getimagesize()` simply refuses to read it. It is a
pre-existing quirk of the language-flag asset, not a broken image, and it is left untouched.

No file was renamed, converted, resized or replaced. No stock or generated image was introduced.

### 3.1 ⚠ The real media was briefly overwritten — by the test suite, not by this fix

While adding regression tests, 48 tracked files under `storage/app/public/images/` were replaced
with the 6-byte string `binary`. Cause found and fixed in §3.2; all 48 files were restored from
git (`git checkout -- storage/app/public`) and re-verified by size and `getimagesize()`.

This is reported in full because it is the most consequential finding of the task: the harness,
not the application, was damaging the asset library, and it would have done so again on the next
run.

### 3.2 Defect fixed: tests wrote into the repository's real media

`tests/TestCase.php::redirectStorage()` redirected `storage_path()` to a per-process temporary
tree but left `config('filesystems.disks.*.root')` alone. Those roots are baked at config-load
time from the **real** `storage_path()`, so `Storage::disk('public')` still resolved to
`C:\laragon\www\MakinPay\storage\app\public` — the tracked, committed media directory. Any test
that seeded a file through that disk destroyed the corresponding real image.

The redirect now also rewrites every disk root that sits under the real storage tree, and forgets
any disk instance already resolved against the old root:

```php
$realStorage = rtrim(str_replace('\\', '/', storage_path()), '/');
app()->useStoragePath($root);

foreach ((array) config('filesystems.disks', []) as $name => $disk) {
    // …if the root starts with $realStorage, re-root it under $root…
    Storage::forgetDisk($name);
}
config(['filesystems.disks' => $disks]);
```

After the change the same tests pass and `git status storage/app` reports **0 modified files**,
where before it reported **48**.

---

## 4. Filesystem configuration findings

`config/filesystems.php` is correct and was **not** changed:

| Disk | Root | URL | Verdict |
| --- | --- | --- | --- |
| `local` | `storage/app` | — | fine |
| `public` (default for uploads) | `storage/app/public` | `<APP_URL>/storage` | correct |
| `kyc` | `storage/app/private` | intentionally none | correct — private by design |
| `s3` | — | env | unused here |

- `public/storage` is a symlink to `storage/app/public`, present and serving `200`.
- **No** symlink was added, and **no** duplicate storage directory was created.
- `FILESYSTEM_DISK` is not overridden; the `public` disk is referenced explicitly where it matters.
- The seven media-bearing tables all store disk-relative paths without a `storage/` prefix, which
  is the convention the codebase already resolves for settings and `<x-img>`.

`public/images/` does not exist, and creating it was rejected as the fix: it would have exposed
every uploaded file twice (at `/images/...` and `/storage/...`), contradicted the `public` disk's
declared `url`, and left new uploads broken in whichever directory the uploader did not pick.

---

## 5. Source code changes

Three files changed, four added. The fix is a single resolution step at the boundary where stored
data becomes a rendered page — one place, every theme, no template churn.

### 5.1 `app/Support/PublicMedia.php` *(new, 144 lines)*

The resolver, mirroring the rule already used by `Setting` and `<x-img>`:

| Input | Output |
| --- | --- |
| `images/a.png` — on the public disk only | `storage/images/a.png` |
| `general/static/x.png` — already in `public/` | unchanged |
| `https://cdn/...` | unchanged |
| `storage/images/a.png` | unchanged |
| `Manage Your Money…`, `/register`, `contact` | unchanged |
| `images/nowhere.png` — resolves nowhere | placeholder + **one** `Log::warning` per path |

`normalize()` walks nested JSON arrays; `normalizePath()` handles a single value; `exists()` is
used by the audit command. Resolution results are memoised for the request, so a path on the page
five times costs one `stat`.

### 5.2 `app/Models/PageComponent.php` — `prepareForDisplay()`

```php
public function prepareForDisplay(): static
{
    $this->content_data = PublicMedia::normalize($this->content_data ?? []);
    $this->syncOriginalAttribute('content_data');

    foreach ($this->repeatedContents as $repeatedContent) {
        $repeatedContent->content_data = PublicMedia::normalize($repeatedContent->content_data ?? []);
        $repeatedContent->syncOriginalAttribute('content_data');
    }

    return $this;
}
```

`syncOriginalAttribute()` is load-bearing: it marks the rewritten value as *not dirty*, so the
prefixed path can never be persisted. The stored references stay exactly as the uploader — or the
DigiKash export — wrote them. Scoping the rewrite to a display method (rather than an Eloquent
accessor) also keeps it out of the admin page builder, so saving a component from the panel
cannot rewrite the database either.

### 5.3 `resources/views/frontend/pages/index.blade.php` — 5 lines

```blade
// Stored media paths are disk-relative but the public disk is served
// from /storage, so resolve them once here rather than in every
// section view. In-memory only: the stored references are untouched.
$component->prepareForDisplay();
```

Because every theme (classic, golden, emerald, aurora) renders through this one file, all of them
are fixed at once. No section view, layout, stylesheet or asset was modified.

### 5.4 `app/Console/Commands/AuditMedia.php` *(new)* — `makinpay:media:audit`

Diagnostics for the class of defect that hides from code review and tests: a row that holds a path
whose file is gone renders fine and `404`s silently. The command walks `page_components`,
`page_component_repeated_contents`, `pages.breadcrumb`, `blogs.thumbnail`, `payment_gateways.logo`,
`wallet_earn_plans.icon` and `settings.val`, and reports each reference with no file.

```
$ php artisan makinpay:media:audit
Checked 69 distinct media reference(s).
Every referenced image resolves to a file.          # exit 0; exit 1 + table when something is missing
```

Read-only by design: it never rewrites a reference, because a path that resolves nowhere may be an
asset that has not been copied onto this machine yet, and the row is the only record of it.

### 5.5 Tests *(new)* — 16 tests, 75 assertions

`tests/Unit/Media/PublicMediaTest.php` (12) — prefixing, pass-through for `public/`, absolute URLs,
already-prefixed paths, non-media strings, nested JSON, placeholder + single warning, `exists()`.

`tests/Feature/Media/HomepageMediaTest.php` (4, `@group serial`) —
1. homepage emits no `/images/` URL and does emit `/storage/images/`;
2. `page_components` and `page_component_repeated_contents` are **byte-identical** before and after
   a render (the "never write it back" guarantee);
3. `prepareForDisplay()` leaves the model `isDirty() === false`;
4. the audit command exits 0 on a clean install, and 1 + names the path once one reference is broken
   (row restored afterwards).

### 5.6 Safety properties honoured

- No database row written. Verified by test 2 and by `makinpay_qa` being untouched **outside tests**.
- No file deleted, renamed or overwritten; `git status storage/app` → clean.
- No `migrate:fresh`, no `db:wipe`, no truncation, no `digikash.sql` import.
- No symlink created, no duplicate storage directory.
- User-uploaded content unaffected: paths are resolved, never moved.
- No credentials printed.

---

## 6. Browser verification results

Verified over HTTP against `php artisan serve` on `127.0.0.1:8331` with the real `makinpay_qa`
database. Every distinct image URL on the page was fetched and its status recorded.

| Check | Before | After |
| --- | --- | --- |
| Homepage HTTP status | 200 | 200 |
| Distinct image URLs on the page | 65 | 65 |
| **`200` responses** | **25** | **65** |
| **`404` responses** | **40** | **0** |
| URLs under `/images/` | 40 | **0** |
| URLs under `/storage/images/` | 0 | 40 |
| Placeholder fallbacks rendered | — | 0 |
| `local.WARNING` "media reference does not resolve" | — | **0** |

Representative assets, one per category named in the task:

| Category | File | Code | Content-type | Bytes |
| --- | --- | --- | --- | --- |
| Hero | `20250410_055802_hero_1_Q0SS.png` | 200 | `image/png` | 221,089 |
| Hero background | `20250410_055802_hero_bg_SfUo.png` | 200 | `image/png` | 3,810,864 |
| Feature illustration | `20250410_060504_frame_1_YXLr.png` | 200 | `image/png` | 3,974 |
| Content / about | `20250410_183859_01_6Eac.jpg` | 200 | `image/jpeg` | 57,556 |
| Testimonial client | `20250411_133154_client1_ubBg.jpg` | 200 | `image/jpeg` | 6,333 |
| Section title bar | `20250411_134907_title_bar_wEBm.png` | 200 | `image/png` | 157 |
| Team member | `20250411_134922_team1_Ma8U.jpg` | 200 | `image/jpeg` | 95,439 |
| CTA offer background | `20250411_070408_cta_offer_bg_BJfW.jpg` | 200 | `image/jpeg` | 91,229 |

- **Secondary page:** `/about` (same page builder, different page record) — 6/6 content images `200`.
- **Logs:** no exception, no `local.ERROR`, no unresolved-media warning from live requests.
  (40 `testing.WARNING` lines from an early test run remain in `storage/logs/laravel.log`; they are
  harness output, not application behaviour.)
- The only `local.ERROR` entries in the log predate this work (the `settings.value` column and the
  language-switcher null dereference, both fixed in the previous commit).

### 6.1 What I could not verify

No headless browser or Playwright/Puppeteer is installed in this project, so I could **not**
reproduce the DevTools Network panel, take screenshots, or emulate a mobile viewport. Verification
is at the HTTP level, which is exactly the layer the defect lived in (40 broken URLs, 0 after).
Responsive behaviour cannot have changed: the fix alters `src`/`url()` attribute **values** only —
no markup, class, style or CSS file was touched.

Re-confirming the Arabic layout was also inconclusive: `/locale-set/ar` returns `302` and the
following homepage returns `200` with all 65 images resolving, but the session locale did not flip
in my `curl` cookie workflow, so `<html lang="ar" dir="rtl">` was not re-observed here. The RTL
path shares the same section views, and the fix is direction-agnostic.

---

## 7. Remaining missing assets

**None.** After the fix:

- `php artisan makinpay:media:audit` → *"Checked 69 distinct media reference(s). Every referenced
  image resolves to a file."*
- 65/65 image URLs on the homepage return `200`.
- Nothing on the page falls back to the placeholder.

Two notes for completeness:

1. `storage/app/public/images/2026/05/19/20260519_195121_flag_GgkO.png` is an SVG stored under a
   `.png` name. It renders correctly in a browser and is tracked unmodified. Not a defect; left alone.
2. Assets referenced by **empty tables** could not be checked because there is nothing to check —
   `blogs` has 0 rows and no `navigations` / `footer_sections` / `socials` rows are seeded (D6 in
   the previous report). If those tables are populated later with `images/...` paths, the same
   resolution applies and `makinpay:media:audit` will cover them.

---

## 8. Files changed

```
 M app/Models/PageComponent.php                                   +25   prepareForDisplay()
 M resources/views/frontend/pages/index.blade.php                  +5   resolve before include
 M tests/TestCase.php                                             +39   redirect filesystem disk roots
?? app/Support/PublicMedia.php                                          resolver
?? app/Console/Commands/AuditMedia.php                                  makinpay:media:audit
?? tests/Unit/Media/PublicMediaTest.php                                 12 tests
?? tests/Feature/Media/HomepageMediaTest.php                             4 tests
```

Existing commits untouched (`95ea190f`, `94887b12`, `d15c6d07`). The five pre-existing untracked
`MAKINPAY_*.md` reports are untouched. Nothing staged, committed, pushed or merged. `storage/app`
is clean — the 48 files damaged during test development were restored from git and verified.

---

## 9. Remaining risks

1. **Other renderers still call `asset()` on raw stored paths.** This fix covers the page builder
   (`index.blade.php`), which is the homepage and every page built from components. Any view that
   renders a stored path directly — `_breadcrumb.blade.php` (`$page->breadcrumb`), blog thumbnails,
   custom pages — would repeat the defect if its table is populated with `images/...` values. They
   are empty today. **Mitigation:** `makinpay:media:audit` finds them; migrate each to
   `PublicMedia::normalize()` when its table starts carrying media.
2. **Placeholder fallback hides a genuinely missing file** from a casual look at the page. It is
   logged once per path and surfaced by the audit command, which is deliberate — but an operator
   who never reads logs will see a tidy page rather than a broken image.
3. **The audit command is not scheduled.** It is a manual diagnostic. Adding it to a monitoring
   cron (exit code 1 on missing media) would make silent asset loss visible.
4. **`storage/app/public` is tracked in git**, which is unusual: media and code share a history, so
   a stray write — as happened here — is recoverable but also silently committed by a careless
   `git add -A`. Consider untracking media or moving it to an artifact store before more uploads
   accumulate.
5. **The `.png`-named SVG** will confuse any tool that trusts extensions (thumbnailers, CDNs with
   strict content sniffing). Cosmetic today.
6. **MySQL path not exercised.** The suite runs on SQLite; `PageComponent::prepareForDisplay()`
   and the audit command are driver-agnostic, but `composer test:mysql` against a disposable schema
   remains the honest gap before merge (also carries over from the previous report).
