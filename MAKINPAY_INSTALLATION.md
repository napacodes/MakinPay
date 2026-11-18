# Installing MakinPay

MakinPay is installed from the command line. There is no public web installer:
nothing in `public/` accepts an install request, so an unconfigured site cannot
be set up by whoever reaches it first.

You need shell access to the server, PHP 8.3 or newer, and a database that
already exists. MakinPay never creates the database and never drops or recreates
a table.

---

## 1. Before you start

Check the server first. This is read-only — it changes nothing and is safe to run
at any time, including on a live site.

```bash
php artisan makinpay:install:check
```

Add `--json` for machine-readable output.

It reports, for each requirement, whether the server satisfies it, and gives a
remedy when it does not:

| Check | What it means |
| --- | --- |
| PHP version | 8.3 or newer is required. |
| PHP extensions | Required extensions (`bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `json`, `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `zip`, and others) must be enabled. |
| `.env` | The file must exist and be readable. If it is missing, copy `.env.example` to `.env`. |
| `APP_KEY` | Must be a valid key. If it is missing, run `php artisan key:generate` — MakinPay does **not** generate it for you during install, so that an existing key is never silently replaced (which would make existing encrypted data unreadable). |
| Database | The connection must succeed and the configured user must hold the privileges MakinPay needs. |
| Writable paths | `storage/`, `storage/logs/`, `storage/framework/*`, `bootstrap/cache/` and `public/` must be writable. |
| Existing installation | Whether this site is already installed. |

If anything reports `fail`, fix it before continuing. An install started against
a server with failing checks stops before touching the database.

---

## 2. Configure the environment

```bash
cp .env.example .env
php artisan key:generate
```

Then edit `.env`:

- `APP_URL` — the public address of the site. Absolute URLs and links depend on it.
- `APP_ENV=production`, `APP_DEBUG=false` — required for a live site.
- `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` — the database you created.
- Mail settings, so the application can send notification and reset mail.

`php artisan makinpay:install:check` re-confirms the environment is coherent.

---

## 3. Install

```bash
php artisan makinpay:install
```

With no options this is the normal, interactive run. It:

1. re-runs the preflight checks and stops on any failure;
2. refuses to continue if the site is already installed;
3. applies pending migrations (it never runs `migrate:fresh` and never drops data);
4. seeds reference data — permissions, roles, currencies, gateways, the site's languages, and the public settings (site title, copyright, admin prefix, support contact), plus the other baseline tables;
5. asks for the first administrator;
6. writes the installation marker.

### Options

| Option | Purpose |
| --- | --- |
| `--seed-baseline` | Seed the baseline reference data. **Required for a working public site**: without it the admin panel has no permissions or gateways, the homepage has no languages and the footer has no site title or copyright text. |
| `--demo` | Seed demonstration content **after** the baseline. Never use on production. |
| `--force` | Skip the confirmation prompt. |
| `--allow-existing` | Continue even though the site is already installed. Required to re-run. |
| `--skip-admin` | Do not create an administrator. The marker is then **not** written, because an installation with no administrator is not usable. |
| `--optimize` | Run `config:cache` and `route:cache` at the end. Opt-in — see the warning below. |
| `--dry-run` | Show what would happen without changing anything. |

> **On `--optimize`**: caching config resolves `.env` at cache time. If you later
> change database credentials or other environment values, the cached copy still
> applies until you run `php artisan config:clear`. That is why it is opt-in. The
> same applies to `APP_KEY`.

### Non-interactive install

For automation, supply the administrator through the environment rather than a
command-line option, so the password never appears in `ps` output or shell
history:

```bash
MAKINPAY_ADMIN_EMAIL="admin@example.com" \
MAKINPAY_ADMIN_PASSWORD='a-strong-password' \
php artisan makinpay:install --seed-baseline --no-interaction
```

If `MAKINPAY_ADMIN_PASSWORD` is not set, a strong random password is generated
and printed **once**. If the run is non-interactive and no email can be
determined, no administrator is created and the install reports an error telling
you to run `php artisan makinpay:admin:create`.

---

## 4. The first administrator

In an interactive run you are asked for a name, an email address and a password.

- The password is read **without echo** and must be confirmed.
- Minimum 8 characters, with upper case, lower case and a digit.
- It is hashed with bcrypt. It is never logged, never written to disk in plain
  text, and never passed as a command-line argument.
- The account is given the `super-admin` role.
- Duplicate accounts are refused: an existing email is reported, not overwritten.

To create an administrator later, or to deliberately reset one:

```bash
# interactive
php artisan makinpay:admin:create

# scripted
MAKINPAY_ADMIN_EMAIL="admin@example.com" \
MAKINPAY_ADMIN_PASSWORD='a-strong-password' \
php artisan makinpay:admin:create --name "Site Owner"
```

`--overwrite` resets the password of an existing administrator and is the only
way to do so from this command. Without it, an existing account is left alone.

---

## 5. After installing

1. **Scheduler.** MakinPay relies on scheduled tasks. Add to cron:

   ```
   * * * * * cd /path/to/makinpay && php artisan schedule:run >> /dev/null 2>&1
   ```

2. **Queue worker.** If `QUEUE_CONNECTION` is not `sync`, run and supervise:

   ```bash
   php artisan queue:work --tries=3
   ```

3. **Sign in** at `https://your-domain/admin`.

4. **Review `.env`.** `APP_DEBUG=false`, correct `APP_URL`, working mail settings.

5. **Re-run the health check:** `php artisan makinpay:install:check`.

---

## Re-running the installer

A second run is refused:

```
This site already reports as installed. Nothing was changed.
```

This protects a live site from a partially applied migration or a reset
administrator. If you genuinely intend to run it again — after restoring a
backup, for example — pass `--allow-existing`. Migrations already applied are
skipped, so the run is idempotent: it will not duplicate the administrator or
re-seed data that is already present.

Re-running with `--allow-existing` still never drops a table and never runs
`migrate:fresh`.

---

## How "installed" is detected

Two signals are honoured:

- `storage/app/makinpay-installed.json` — written by the CLI installer on success.
- `storage/app/installed` — the lock file written by the legacy web installer, so
  sites installed before this change are still recognised.

A site is considered installed only when the marker exists **and** an
administrator account actually exists. A marker left behind by a failed run will
not strand the site behind the maintenance page.

---

## Troubleshooting

**The maintenance page appears.** The installation marker is absent or no
administrator exists. Run `php artisan makinpay:install:check` to see which.

**`makinpay:install` refuses to run.** The site is installed. Use
`--allow-existing` only if that is intended.

**Database privilege errors.** The user in `.env` needs `SELECT, INSERT, UPDATE,
DELETE, CREATE, ALTER, INDEX, REFERENCES` on the target schema. Grant them and
re-run the check.

**Changes to `.env` seem ignored.** A cached config is in effect. Run
`php artisan config:clear`.

**Lost the administrator password.** Use
`php artisan makinpay:admin:create --email you@example.com --overwrite`. You must
have shell access; there is deliberately no unauthenticated reset endpoint.
