# Changelog

All notable changes to DigiKash are documented here. Versions follow the
release numbering used by the in-app updater (`config/project_updater.php`).

---

## 2.8.0

Three new money modules and a tax engine. Bill payments let customers,
merchants and agents pay utility, TV, internet and other bills from their
wallet, through billers the admin sets up by hand or syncs from Reloadly or
Flutterwave. Mass payments let a merchant pay many DigiKash wallets in one
batch, and mass payouts pay many bank or mobile-money accounts through one
withdraw method, with admin approval. Tax and levy rules charge tax on top
of deposits, withdrawals, send money, exchange, remittance and bill
payments, each tax shown as its own receipt line. Around them: optional
sign-in by SMS code and phone-first sign-in with a home country, money
flows that price and book in exact decimal strings, and a long round of
payment, on-chain and security fixes.

### Upgrade notes

- **No new Composer or npm packages.** Nothing is removed: no routes,
  columns or translation keys.

- **Thirty-five guarded migrations run, all additive.** New tables for bill
  payments, saved bills, mass payment batches, rows and events, tax rules,
  SMS sign-in codes and merchant webhook deliveries; new tax columns on
  transactions, remittances and bill payments.

- **`transactions.trx_id` becomes unique.** The migration stops and lists
  the ids if an installation already holds duplicates; resolve those rows
  and run `php artisan migrate` again. A clean installation is not affected.

- **Every new module is off until the admin switches it on.** `bill_payment`,
  `mass_payment` and `mass_payout` are added to Feature Management disabled.
  No tax rule exists until one is created, so no charge changes on upgrade.
  SMS-code sign-in (`otp_login_enabled`) is off, the sign-in field stays
  `email`, and the home country is empty.

- **New permissions are seeded for super-admin only** by the reconcile
  seeders that run on upgrade: bill payments, mass payments (including
  payout approval) and tax rules. Other roles get them from Roles &
  permissions.

- **New scheduled commands** need the Laravel scheduler running, as before:
  `bill-payment:reconcile` (every 5 minutes), `bill-payment:auto-sync-billers`
  and `bill-payment:remind-saved` (daily), `mass-payments:resume` and
  `mass-payouts:reconcile` (every minute), `mass-payments:prune` (daily).
  Merchant webhooks are now delivered by the queue worker, so a site sending
  merchant webhooks needs one running.

- **Add-ons.** Point of Sale 1.1.0 and Kids Card 1.2.0 ship as their own
  signed packages and require core 2.8.0. The core zip carries neither.

### New

- **Bill payments.** Providers, categories and billers in the admin;
  Manual, Reloadly Utility Payments and Flutterwave Bills drivers with
  credentials in the Integration Center, a connection test, and a pause
  when a provider's account fails. The customer's account is looked up
  before any debit. Submission is locked and idempotent, a payment is
  settled or refunded exactly once, and `bill-payment:reconcile` refunds
  payments never sent and asks the provider about stuck ones. Prepaid
  tokens reach the customer and can be sent again. Customers save bills to
  pay again in one tap, with an optional monthly reminder. Billers can be
  synced daily per provider or imported from a CSV, reviewed row by row.
  An admin queue with claim, mark paid, mark failed, a provider check and a
  refund for payments confirmed not charged; web, mobile API and dashboard
  SLA.

- **Mass payments.** A merchant pays many DigiKash wallets from one wallet
  by CSV or rows typed in, sees a preview before any money moves, and
  confirms; the total is reserved at once and each row is paid exactly
  once, with failures refunded. Stop, resume, cancel the rest and close
  after review; a batch can repeat on a schedule for payroll. Admin list,
  report and actions, mobile API, dashboard queue.

- **Mass payouts.** The same batches paid to external bank or mobile-money
  accounts through one withdraw method that opts in, with an approval rule
  (always, or above an amount). A row is dispatched once and an unknown
  provider outcome is never refunded blindly; `mass-payouts:reconcile`
  settles it. Manual, Bitnob and Stripe Connect drivers.

- **Tax and levy rules.** A rule names the operation, a fixed or
  percentage charge or amount brackets, and the customer's country.
  Deposits, withdrawals, send money, exchange (paying side), remittance
  (locked into the quote) and bill payments charge their tax on top, as
  separate lines on receipts and transaction details. Mass payments and
  payouts are never taxed. The deposit API returns its tax lines.

- **Sign-in.** Optional passwordless sign-in by SMS code, with its own
  page; phone-first sign-in with a country picker on the customer, merchant
  and agent pages; a home country the operator picks so customers can type
  a local number, which sign-up forms also start on. One sign-in throttle
  covers every spelling of a number.

- **Merchants.** Webhooks are queued with a delivery log and a retry
  schedule. A shop in review can try counter charges in sandbox. Counter
  charges expire and can be called off, and their QR opens a short public
  page. A payment's e-receipt can be sent to the customer.

- **Mobile API.** Send money abroad from the app; agent cash in and cash
  out ask for the agent's wallet PIN and preview the commission; agent and
  merchant dashboards split today's figures; `GET /config` tells the app
  where to listen for live notifications.

- **Admin.** A Vaults tab on User Manage, and the treasury counts money in
  savings vaults. Notification channels redesigned, with one "Send test
  alert" for every connected channel. An admin can correct a customer's
  registered country, with an audit note. A clearer demo-mode notice.

- **Payments.** An "approve on your phone" page for MTN MoMo and Airtel
  Money; checkout asks for the payer's phone wherever a gateway needs it.

### Fixed

- **Money is exact.** Deposits, withdrawals, send and request money and
  exchange price and book in decimal strings at the currency's precision,
  every stored amount is exact, and a non-numeric amount is refused. A
  transaction is cancelled once and only while pending; remittance and
  recharge refund to the last digit and only once.

- **Gateway callbacks.** Nine unsigned gateway callbacks are now bound to
  the provider's own answer; Airtel settles only on Airtel's answer and no
  longer logs the body; MTN MoMo and Airtel charge the payer's number.
  ShuftiPro's refusal reason is shown and its legacy signature accepted.

- **On-chain deposits.** A BSC reorg can no longer credit one transfer
  twice; Bitcoin resumes an interrupted history read, accepts upper-case
  pool addresses and ignores sweep change; addresses are handed out only
  when asked for; TRON and BSC start reading when switched on.

- **Security and accounts.** A customer downloads only their own receipts
  and can no longer change their own country. Closing or deleting an
  account no longer loses money in its vaults and waits for open bill
  payments. Sandbox payments stay out of every total, dashboard, reward
  rank and plan allowance.

- **Phones.** The leading zero is kept for Côte d'Ivoire, Benin and Congo,
  a number with its dial code never gets a second one, and profile and
  admin edits store numbers the way sign-up does.

- **Updater.** A stored package link that has expired is fetched again, and
  a package the installation cannot take whole is refused.

---

## 2.7.1

Two things in one release. The one customers will feel: the withdrawal and
exchange fee can now be taken out of the amount typed instead of added on
top, and a Max button fills in exactly what a wallet can send. The one
operators will use: DigiKash takes add-ons — optional modules, sold and
licensed on their own, that plug features, admin screens and permissions
into an install without touching its code. Feature Management gains an
Add-ons tab to install, update, switch on and off and uninstall them, and
every add-on feature appears in the Features grid beside the core ones,
locked while its add-on is not running. Around both, the System
information page rebuilt around health checks, and a round of fixes.

### Upgrade notes

- **No new Composer or npm packages.** Nothing is removed: no routes,
  columns or translation keys. One admin route is added (`admin/app/report`,
  the System information text report). The core release zip no longer
  carries anything under `addons/`; add-ons ship as their own signed
  packages.

- **One guarded migration runs, additive:** the `addons` table that records
  what is installed. No existing column changes.

- **A new permission, `addon-manage`, is seeded for super-admin only** by the
  reconcile seeders that run on upgrade. Other roles get it from Roles &
  permissions when an operator chooses to. Turning an add-on's features on
  still needs `feature-manage`.

- **Fresh installs now require the PHP `gmp` extension.** The installer's
  server check lists it beside the others; a host without it can no longer
  run a fresh install of 2.7.1. An existing site that upgrades is not
  stopped: core does not use gmp, and an add-on that needs it says so in its
  own requirements before it can be switched on.

- **The `addons/` folder at the project root holds installed add-ons.** It
  must be writable by PHP for uploads from the admin, as must `storage/app`
  (an uploaded package waits there under a one-use token, and an update
  keeps the previous version there until it succeeds). Uploads are up to
  50 MB; `upload_max_filesize` and `post_max_size` must allow that.

- **Packages must be signed with the Coevs release key.** An unsigned or
  altered zip is refused before anything is unpacked. Installing, updating
  and uninstalling ask for the admin's password, and the authenticator code
  when the admin uses two-factor sign-in.

- **Two new settings, `withdraw_fee_mode` and `exchange_fee_mode`, under
  General → Fees.** Both default to `on_top`, which is exactly how every
  installation charged before this release, so an upgrade changes nothing
  until the administrator chooses otherwise. The rows are created by the
  reconcile seeders that run on upgrade; until then the code reads the
  default.

- **The mobile app reads the new `fee_mode` field in the withdraw and
  exchange quotes.** An app built before this release ignores it and keeps
  showing the fee on top, which is still correct for an installation that
  has not switched. Ship the app update (`digikash-app` 2026-10-08) when the
  setting is switched on, so the review screens label "You receive" and
  "Converts after fee" the right way round.

### New

- **Fee direction is the administrator's choice.** Under *Added on top* a
  customer who types 100 is debited 100 plus the fee and the payout is 100 —
  how a mobile-money cash-out counter works. Under *Deducted from the
  amount* the customer is debited exactly 100 and the payout is 100 less the
  fee — how every exchange and most payout rails work, and what a customer
  used to those expects. Withdrawals and exchanges each have their own
  switch. Deposits and send money keep the fee on top on purpose: "send
  them 100" means they get 100.

- **The minimum and maximum apply to the figure typed, in both modes.** A
  customer holding exactly the minimum, with a percentage fee, could not
  withdraw at all before: the full amount needed more than the balance, and
  the amount that fitted was below the minimum. Meeting the minimum is now
  enough. A deducted fee that would leave nothing to pay out is refused with
  a plain message rather than filing a payout of nothing.

- **A Max button on the withdraw and exchange forms**, web and app, fills in
  the most the wallet can send once the fee is charged — a bisection over
  whole currency units, so it lands exactly on the balance even when the fee
  is capped or floored. The summaries read *Amount → Charge → You receive →
  Wallet debited*; the "Total" line that repeated the debited figure is gone.
  The API quotes carry `fee_mode` so the app can label the pair.

- **System information rebuilt as one health-first page**: a score ring over
  the counted checks beside a "What needs you" list, most urgent first, each
  with what to do and a link to do it; a jump bar; the runtime stack checked
  against the floors in `composer.json`; cards for security posture, PHP
  limits, extensions, required functions, drivers, background services,
  host and maintenance. A Copy report button copies the same facts as plain
  text. The sidebar foot is now a System row — build and environment — with
  an update badge only when a stored check is ahead of the running build.
  Old `?section=` links still land on the matching card.

- **Demo mode keeps every sensitive fact off System information**: exact
  PHP and Laravel versions, debug and app-key state, queue driver and job
  counts, timezone, server details. A withheld check is neutral and out of
  the score; the page says what is hidden and why.

- **Add-ons tab** in Feature Management — every add-on on disk with its
  state, license and requirements; a detail drawer with what it adds, its
  requirements and how to fix each, and its license; a six-step installer
  (upload, verify, requirements, license, install, enable) that reports what
  the server found and never invents progress; update from a newer package,
  with the previous version restored if its migrations fail; uninstall that
  keeps the data by default and drops it only when the add-on's key is typed.

- **Add-on features in the Features grid** — each carries the name of its
  add-on. While the add-on is not running the card is locked, says why and
  links back to it; the server refuses to switch it, and the page counts it
  as off.

- **Per-add-on licensing** — an add-on sold as its own item takes its own
  purchase code, verified with the license server and re-checked by the
  twice-daily `license:heartbeat`. A missing code is tolerated for a grace
  period after install, with a warning; after it the add-on cannot be
  switched on until a code is activated.

- **`php artisan addon:package {slug} --key=<pem>`** builds a signed add-on
  zip, and refuses a key that does not match the release key.

### Fixed

- **Merchant screens open when the owner account is gone.** One merchant
  whose user row had been removed with foreign-key checks off took the whole
  Merchants page down with "Attempt to read property username on null". Such
  rows now say "Owner account deleted"; they can be rejected or paused, but
  approving or re-enabling them is refused, since there is no account to
  settle to.

- **A provider's refusal reason reaches the error page.** A logged warning
  is kept as a scrubbed breadcrumb with its context, so ShuftiPro's reason
  for refusing a hosted KYC session is on the Details tab instead of only in
  `laravel.log`.

- **Add-on hooks for background tasks and deposit entry points.**
  `BackgroundTaskRegistry` lets an installed add-on declare the scheduled
  tasks the health page should count, and `DepositEntryPoints` lets it add
  its own entry to the customer's deposit page. Core behaviour is unchanged
  when no add-on is installed.

- A failed add-on install rolls back the migrations it ran, so a retry
  starts clean.

## 2.7.0

The release that gives DigiKash its mobile app: a complete token API for
customers, agents and merchants, built over the same services the website
uses, so the app and the site can never disagree about money. Around it, a
premium setup wizard, a fourth storefront theme, the BSICARDS virtual card
provider, budgets, vaults and scheduled payments, and a round of security
fixes found while the money paths were being lifted out of their
controllers.

### Upgrade notes

- **No new Composer or npm packages.** Nothing is removed: no routes,
  permissions, columns or translation keys. One route changes method: the
  customer's support-ticket close (`close/{ticket}`, same name) is a POST
  now, so a crafted link can no longer close someone's ticket. A theme that
  closes tickets with a plain link needs a small form instead.

- **Twenty-one guarded migrations run, all additive.** New tables for API
  idempotency keys, push tokens, rate alerts, contacts, saved numbers,
  budgets, vaults, scheduled payments, P2P market alerts and virtual card
  activity; new nullable columns on tokens, users, gift cards, wallets, P2P
  ads and reviews; ticket replies widened past 255 characters. Two touch
  data: the StroWallet placeholder `secret_key => "secret_key"` is cleared (a
  secret an operator typed is left alone), and the savings features are added
  to the feature catalog so upgraded installs get their rows.

- **Eleven commands join the schedule. They need a running scheduler and
  queue worker.** `schedules:run` (every 5 min), `p2p:reprice` (5 min),
  `virtual-card:unfreeze-due` (15 min), `p2p:market-alerts` (15 min),
  `rate-alerts:check` (quarter-hourly), `virtual-card:sync-activity`
  (30 min), `budgets:check` and `schedules:remind` (hourly),
  `vaults:auto-save` (06:05), `requests:remind-due` (09:00) and
  `statements:email` (the 1st, 06:00). Expired mobile idempotency rows are
  now pruned with the merchant ones.

- **P2P payment-account files move off the public disk. Run
  `php artisan p2p:privatise-payment-files` after upgrading** (`--dry-run`
  lists what would move). New files already go to private storage; an old one
  stays reachable by URL until the command moves it.

- **Stored phone numbers can be normalised: `php artisan phone:normalize`.**
  It only reports by default; `--apply` writes, `--skip-collisions` leaves
  numbers two accounts would now share. A local trunk zero is no longer
  stored on new numbers.

- **Android push needs Firebase.** Set `FCM_PROJECT_ID` and either
  `FCM_CREDENTIALS_PATH` or `FCM_CREDENTIALS_JSON`. Left empty, the channel
  is simply not offered.

- **`/install` now serves the setup wizard.** The previous installer stays at
  `/install/classic`. An installed site is unaffected.

### Mobile API

Fourteen rounds of `api/v1` endpoints behind Sanctum tokens bound to the
handset they were issued to, with one response envelope, one error
vocabulary, per-tier rate limits and replay protection on every route that
moves money. Customers get the wallet, ledger, deposit, withdraw, send,
exchange, money requests, merchant payment, recharge, vouchers, gift cards,
Wallet Earn, ranks, referrals, KYC, support, the vendor marketplace, the
whole P2P market and virtual cards; agents get their till, cash-in and a
customer-approved cash-out; merchants get their business, payment links and
subscriptions without the app ever holding their API keys. An admin can set
a minimum app version, `php artisan api:openapi` writes the OpenAPI
document, and FCM delivers notifications with nothing monetary on the lock
screen.

### New

- **Setup wizard** — server check, license, database and SMTP tests that
  report what they found, timezone, demo data, queue, cache and PWA options,
  and an install run that shows its stages.
- **Aurora**, a fourth storefront theme, over live platform data.
- **BSICARDS virtual cards** — Visa and Mastercard issue, funding, details,
  freeze, activity and verified webhooks.
- **Budgets, vaults and scheduled payments**, rate alerts, contacts, saved
  recharge numbers and bundles, monthly statement emails, wallet labels.
- **Customers can close their own account**, on the web and in the app.
- **Sign in with a phone number** on the website as well as the app.
- **Realtime on Reverb** as well as Pusher.
- The admin control panel becomes a launchpad; Card Cashback gets its
  settings screen; P2P payment-account proofs can be verified by an admin;
  the wallet ID prefix is a setting.

### Security

- `/file/download` could read any file on the server. It cannot now.
- A customer could read and post into another customer's support ticket.
- Only the payer may approve a money request, and the wallet PIN now guards
  a transfer, a P2P release and a request approval, with one failure counter
  keyed on the payer rather than one per screen or per address.
- A merchant API caller can no longer choose its own environment.
- No customer upload can be an SVG or an XHTML page; a payment proof is a
  photo or a PDF.
- Agent cash-out codes are bound to the exact amount and expire.
- Secure account signs the website out too, and a phone signed out from
  elsewhere stops receiving notifications.

### Money

- Send money, withdraw, deposit and exchange are lifted out of their
  controllers into services, pinned by tests first. An exchange cannot mint a
  sub-unit tail it cannot state; deposit and withdrawal fees are cut rather
  than rounded up; a switched-off method takes no more money.
- Vouchers and gift cards credit once however many requests race for them;
  cancelling a gift card refunds its sender and its issue fee.
- Schedules and vaults cannot pay twice or into a closed vault; a failed
  Wallet Earn rollover can no longer hold a matured stake.
- Agent amounts are decimals quantized at the currency, with the commission
  on the exact principal.

### Mobile recharge (Reloadly)

- **A typed amount is priced from the wallet's currency.** It used to reach
  Reloadly as a local amount whatever the wallet was, so a USD wallet paying
  10 bought 10 BDT of airtime. A wallet in the operator's own currency is
  still sent as a local amount; any other is converted into the account's
  currency at the site's rates and never rounded up. The Reloadly account's
  currency (usually USD) must exist under Currencies with a rate.
- **Web recharges detect the operator from the number.** The web form sends
  an operator name and there is no default-operator field, so every web
  recharge through Reloadly used to fail and refund.
- **The Sandbox credential is a Sandbox / Live choice.** As free text,
  "false", "off" and "live" all read as sandbox, so live keys were sent to
  the sandbox and refused.
- The plugin dialog's **Run test** authenticates against Reloadly and shows
  its own reason on failure; saving the provider form no longer stores the
  Client Secret unsealed; an Integration Center card names the keys actually
  missing instead of printing the total.
- A bundle costs the wallet what the provider bills for it.

---

## 2.6.5.1

The work that followed the 2.6.5 rebuild: the money layer taught to respect
each currency's own precision, the KYC providers made to actually verify
documents, abuse answered at the address instead of at the account, and the
admin screens the rebuild had not reached yet — Site Settings, the KYC queue
and review workspace, agents, merchants — redrawn with the defects each
rebuild uncovered fixed rather than styled over.

### Upgrade notes

- **No new Composer or npm packages.** `composer.json` gains `composer test`,
  `test:parallel` and `test:serial` scripts and nothing else.

- **Eleven guarded migrations run**, every one additive, widening or
  constraint-only, so no stored value changes: a quick-menu preferences
  table; the remittance money columns widened from `decimal(15,2)` to
  `decimal(24,8)`; a KYC review reason code; KYC triage columns; auto-block
  fields and signals on `ip_blocks`; KYC template routing and editor columns;
  a provider test result; rejection reasons on `merchants` and `agents`; and
  the KYC template foreign key changed from cascade to restrict.

- **Identity documents move off the public disk. Run
  `php artisan kyc:privatise-documents` after upgrading.** A passport
  uploaded before this release is still sitting under the storage symlink,
  reachable by anyone holding the URL. New documents go to a private disk at
  `storage/app/private`, outside the web root, and the only path to one is
  the authorised controller action that streams it. The command moves the
  existing files and rewrites their rows; rows still carrying a legacy public
  path keep working until it is run.

- **`kyc:reconcile-pending` joins the schedule, every ten minutes.** Every
  provider driver implements `checkStatus()` and nothing called it, so an
  automated submission whose webhook was lost, refused on a signature, or
  delivered while the worker was down stayed `PENDING` for good. A submission
  is eligible once it has sat untouched for `KYC_RECONCILE_AFTER_MINUTES`
  (30), and only terminal verdicts are settled, through the same
  transactional `KycDecisionEngine` path the webhook uses. **This needs a
  running scheduler and queue worker**, as remittance reconciliation already
  does.

- **Automatic IP blocking is on by default.** `IpAbuseGuard` weights each
  refused request — a login lockout counts five, a throttle or 403 three, a
  401 two, a CSRF failure one — and blocks an address reaching 12 inside ten
  minutes for an hour, doubling on repeats up to a day. 404 and 422 are not
  signals; a typo is not an attack. Private and reserved ranges are never
  blocked, an address three or more different accounts have signed in from in
  the last day is treated as shared and left alone, and
  `IP_AUTO_BLOCK_ALLOWLIST` names the office and the monitoring probe. Set
  `IP_AUTO_BLOCK_ENABLED=false` to switch it off entirely.

- **`BlockIp` now runs for the whole `web` and `api` groups**, prepended in
  `bootstrap/app.php` rather than listed on the `user` prefix. A denied
  address is turned away at the sign-in page instead of only once it is
  already inside. No route is lost by the move.

- **Names, usernames and emails are bounded on input** — 39, 30 and 254
  characters, each the limit a standard already sets — and this bounds forms
  only. The columns stay `varchar(255)`, so existing rows holding longer
  values are untouched and keep rendering; a customer editing such a profile
  is asked to shorten it before saving.

- **A new currency is created at its own precision.** The create form
  pre-filled `decimal_places` with a literal `2`, so the ISO 4217 / crypto
  default behind it could never be reached and every currency added from the
  panel got two places — which is how a USDT or BTC wallet ends up rendering
  every balance as `0.00`. The field is blank now with an "Automatic"
  placeholder. Currencies already created keep the precision they have; fix
  one by editing the field.

- **`money:audit-precision` gains a `wallet-dust` check.** The arithmetic
  that created sub-cent residue in a two-decimal wallet is fixed, but wallets
  debited before the fix still hold what it left behind — a balance that
  renders as `48751.72` and can never be spent to zero. Like every other
  check in that command it reports and never repairs: whether the residue is
  written off, credited up or left alone is the operator's decision.

- **A KYC template can no longer be deleted while verifications reference
  it.** The foreign key has carried `onDelete('cascade')` since February and
  the controller asked nothing before deleting, so removing a form an admin
  believed was unused destroyed every submission made against it — approved
  identities, their append-only review logs, consent records and document
  digests — and reported success. The controller now refuses first, naming
  the count and pointing at pause; the constraint is the backstop for every
  other path to the row.

- **Nothing is removed.** No routes, permissions, columns or translation keys
  are dropped in this release.

### Money

- **A wallet holding exactly its own total was refused.**
  `calculateTransactionDetails()` worked at the ledger's eight places all the
  way through, which is right for the base-denominated `decimal(24,8)`
  columns and wrong for `payableAmount` — the figure the wallet is debited
  and the processor invoiced. A 1% fee on 99.01 USD made the total
  100.0001, the browser previewed it as "100.00", and the balance check
  compared the unrounded figure against a wallet holding 100.00 and answered
  "Insufficient wallet balance". Reported as "2 decimals are already
  selected, deposit works but withdraw does not"; both halves were true, and
  the second explained the first. The payable amount now lands on its own
  currency's precision, which also stops a withdrawal that did clear the
  check leaving dust behind.

- **A remittance is quoted in the currencies it actually moves.**
  `QuoteService` rounded through `DecimalMoney` defaults at two places
  whatever the corridor was denominated in, and the module's columns agreed
  with it. The corridors operators actually open include the three-decimal
  Gulf dinars (BHD, JOD, KWD, OMR, TND) and the zero-decimal destinations
  (JPY, KRW, UGX, VND, XOF): a KWD payout silently dropped its third digit
  on the leg the beneficiary receives, and a JPY quote gained a fraction of
  a yen that does not exist. Each leg now lands on its own currency's
  precision, and the schema was widened to hold it.

- **One money formatter in the browser, in both panels.** The PHP side was
  swept in 2.6.5 and `MoneyFormattingGuardTest` has no allow list, but
  `digikash-money.js` was only ever loaded by the customer layout, so every
  figure the admin drew in JavaScript was left to whatever the page felt like
  writing and three forks had accumulated. Both layouts serve it now. A
  dashboard read in BTC showed "₿0.00" on every chart point because the
  tooltip formatted at `toFixed(2)`; the precision travels with the symbol.

- **The agent and merchant rebuilds went back to `number_format($value, 2)`**
  on fourteen figures across four views. Rates go through `feeRate()` and
  amounts through `money()` at the currency's own precision. The merchant
  review modal was also labelling its three flat-amount boxes with the site
  currency while the preview computed them in the merchant's own, so a
  merchant priced in anything but the base was told the wrong unit for the
  number it was about to charge.

- **A withdrawal method with no ceiling previewed as broken.**
  `effectiveMaxAmount()` returns null when neither the platform nor the
  processor states an upper bound, and JavaScript coerced that null to 0, so
  every amount typed read as above the maximum and the summary never
  rendered. An absent ceiling now reads "No limit".

### KYC

- **Smile ID never had a document verification flow.** `verify()` posted to
  `async_id_verification` with `job_type` 6 — a real endpoint, but the ID-number
  lookup API, which accepts only `job_type` 5 and transmits no images at all.
  It was the wrong product, not an integration missing its images. Document
  verification is now the two-call upload flow their API actually documents.

- **ShuftiPro and Sumsub are sent the documents the applicant already
  uploaded**, instead of being asked to collect them again — which is what an
  installation keeping its own KYC form needs, and what neither driver did.
  Sumsub requires ISO 3166-1 alpha-3 on every upload
  while this application stores alpha-2; the mapping lives in `config/kyc.php`
  so an operator can correct it for their own market without a release, and a
  country that is not listed is routed to manual review rather than guessed
  at — a wrong code makes Sumsub reject with `differentDocTypeOrCountry` and
  the applicant waits on a verdict that is never coming.

- **A Sumsub verdict is matched to the submission it belongs to.** The
  webhook was resolved by applicant, and an applicant can hold more than one.

- **The queue and the review screen are rebuilt** to the approved design: a
  provider-signal column, triage chips and a row menu on the queue, and a
  full workspace page in place of the review modal. Provider activation is
  now a transaction with `lockForUpdate()`, since the page promises exactly
  one engine can be active. Enter no longer decides a review, and a decision
  locks once taken.

### Security

- **Abuse is answered at the address, never at the demo account.** The demo
  user, agent and merchant logins are published credentials, so suspending
  one locks out every visitor at once. They cannot be suspended at all now:
  `User::isSuspended()` returns false for a demo-protected account, the admin
  endpoint and P2P trader moderation both refuse one, `LoginRiskEvaluator`
  downgrades BLOCKED to SUSPICIOUS rather than terminating a shared session,
  and the suspend modal says so before the admin clicks. What gets refused is
  the address — see the auto-block note above — with a side panel on the IP
  access screen keyed by address rather than row id, because a held-back
  block is the one that was never written.

- Identity documents left the public disk, and the only test that covered
  document serving had been asserting the exposure worked.

### Site Settings

Thirteen sections and ninety-eight fields sat in a card inside a card inside
a scroll area, every label carrying a tooltip nobody hovered, with no way to
find a setting without already knowing which section held it.

- **The page is a rail and a body now.** The rail groups the sections under
  four headings and searches every field in all of them; the body lays each
  setting out as a row — what it is, what it costs when it is wrong, and
  whether it still holds the value it shipped with on the left, the control
  on the right. On a wide screen the page itself no longer scrolls.
- **Every setting has a description of its own**, written to be read rather
  than hovered, and shortened again once they were all in place.
- **The colour control and the image field were rebuilt** from the reference
  design — the colour field had been two controls stuck together, with a
  swatch taller than every other control on the page — and the upload-limits
  dialogue with them.
- The section head docks rather than floats, stays a card while it holds the
  top, and no longer swallows the dialogues opened from it; a jump lands the
  caret in the field it sends you to and scrolls the rail to match.

### One control, once

Three of these existed in several versions at once, which is how two panes
on one screen came to disagree about what the same control looks like. Each
is now declared once, with a test that fails on a page sheet drawing its own.

- **One scrollbar.** Nineteen page stylesheets declared their own, between
  them thirteen thumb colours and nine widths. The canon lives in
  `custom.css` against four tokens; hiding a bar outright is still allowed
  and is written down in the test's allow list.
- **One checkbox.** Three pages had forked it — two repainting the native
  box with `accent-color`, one drawing a 16px square with a Font Awesome
  tick. A switch is deliberately excluded twice, by both the wrapper class
  and `.coevs-switch`, because five switches sit outside a wrapper and four
  more carry only the plain input class.
- **One save bar.** It had shipped three times under three prefixes, with
  three anatomies, three ways of saying what was unsaved, and only one of
  them carrying the keyboard hint. Every settings screen now loads the shared
  sheet, and the bar behaves as it looks: it counts the unsaved changes,
  discards them, and saves on the keystroke it advertises.

### Admin screens

- **Agents and merchants are built around the decision.** Four scopes had
  rendered one view with a different title while the status filter was hidden
  on three of them, so which slice you were looking at could not be read from
  the page or changed on it — and the agents' Disabled slice was reachable
  from nowhere at all. They are tabs with real counts now, over a header
  carrying counts and rates rather than a cross-currency total nobody can
  act on. Pending gets a screen of its own, oldest first, with the wait in a
  column.
- **A rejection now carries a reason, and the reason arrives.** The merchant
  review modal never rendered the field, so the template's
  `{rejection_reason}` slot fell back to "No reason provided" on every
  rejection ever sent. Both modules require one, store it, and show it back
  on the rejected profile; an agent rejection with no reason is refused.
- **The transaction dialogue opens from a user's tabs**, the raw gateway
  panel is worth opening rather than a wall of JSON, an absent payload says
  so instead of printing an empty one, and its copy button works without TLS
  and stays where it is while you use it.
- **The add-user dialogue is a sectioned form**, and the KYC records table
  drops an action column that did nothing.
- Smaller repairs: the Merchants row lights on a merchant's review screen,
  the empty state takes the width of the card it sits in, the agent review
  header holds its RTL rule after the rebuild, and the sidebar mark is drawn
  at a logo's size in the site's own primary.

### Marketplace and P2P settings

- Marketplace settings navigate down the side, one section at a time; P2P
  marketplace settings are a tabbed page. Both had declared their save
  controls twice — once in the header and once at the foot — which reads as
  two different saves; the header copy is gone and the shared bar is the one
  that commits.

### The customer side

- **Each user chooses which quick-menu shortcuts they keep.** The apps panel
  showed all 22 to everyone. A Customize control swaps the grid for an editor
  with a toggle per shortcut, reordering, and Save / Cancel / Reset. Entries
  are keyed by route name so a selection survives an upgrade, submitted keys
  are validated against the catalog that user is entitled to, and a stored
  selection is re-intersected with it on every render — so disabling a
  feature removes its shortcut rather than leaving a dead tile.

### Mobile

The admin panel was swept at 360px. The top bar fits, a hidden label no
longer drags the page sideways, the drawer stops printing child labels over
their own tree, the empty state is back on screen, filter chips reach the
36px touch floor, the toggle switch is sized for a thumb, and the ledger
gives its amount a line of its own rather than printing a separator against
nothing. The notification panel opens on Unread.

### Internationalisation

208 new keys per file across `en`, `es` and `ar` — the IP access and
auto-block work, the KYC queue and review workspace, the agent module and
the merchant review sheet — plus the marketplace and P2P settings strings.
Additive only, with real translations rather than the English source.

### Housekeeping

- **The updater raises a memory ceiling and never lowers it.** Four places
  asked for 512M before walking a vendor tree or zipping storage, written as
  a plain `ini_set` that also means "at most this much" — so an installation
  running at 1G had the updater halve its own headroom in the middle of the
  operation that needed it most. The cost was paid a long way from the cause:
  the test suite died in the translation loader thousands of tests later.
- **The suites that own a path outside their own database get their own
  pass.** A parallel run reported thirteen to fifteen failures and a
  different set each time; four workers get four databases but share one
  working copy, and `artisan down` answers 503 to every other worker.
  `composer test` runs everything in parallel, then the serial group alone.
- Test probes rendered into the web root are ignored rather than committed.

---

## 2.6.5

The admin panel rebuilt. The shell around every screen, the home page,
background tasks, activity history, the project updater, feature management,
the Integration Center, the media library, team members, roles and
permissions, the user record and the ledger were each redrawn to the
handed-off design and given the backend they needed — along with the defects
that rebuilding them uncovered, several of which were making the panel report
the opposite of what had happened.

### Upgrade notes

- **No new Composer or npm packages.** Fifteen guarded migrations run, every
  one additive and either nullable or defaulted, so an upgraded installation
  renders on upgrade day what it rendered the day before: a wider
  `settings.val`; hosted-KYC session and document-hash storage; currency
  display format; sign-in history gains `succeeded`, `admin_id` and its risk
  context; a payment-method rate timestamp; `languages.native_name`; a media
  provider allowance; a plugin test timestamp; P2P order numbers; user
  suspension and password flags; and three columns on the updater's history.

- **Sign out and back in.** Four new permissions — `dashboard-queue`,
  `dashboard-sla-manage`, `system-health` and `risk-watch` — are seeded
  additively, and admin permissions are cached in the session, so the new
  entries appear in the sidebar after the next sign-in. `install:reconcile`
  is the path that reaches an existing installation; it seeds those, the
  dashboard SLA targets, the sign-in risk rules and the blocked-sign-in
  notification template.

- **The queue's `retry_after` default is 310 seconds, up from 90.** Every
  worker command this project ships with outran the old value — Forge writes
  `--timeout=299`, the Supervisor block on the Setup tab says `--timeout=120`
  — so any job past ninety seconds was handed to a second worker while the
  first was still inside it. On this application that is money moved twice,
  not a duplicated email. Raising it only delays recovery from a worker that
  genuinely died, so the trade runs one way. `DB_QUEUE_RETRY_AFTER` still
  overrides it.

- **SMTP and provider secrets no longer reach the browser.** The settings
  forms show only whether a value is saved. Existing plain-text rows keep
  working and are sealed with the application key on their next save; a field
  left blank keeps what is stored.

- **Administrator sign-ins are recorded from now on, not retrospectively.**
  Team Members shows "last active" and "last IP" from the login history,
  which has only ever recorded customers. Existing members read as never
  having signed in until their next sign-in.

- **Routes that are gone**, each because the screen behind it was merged into
  another: `GET admin/staff/{id}/edit`, the separate role-edit page, and four
  System Health screens — Overview, Live tail, Data & privacy, and
  Integrations & SDK — with their five routes. The `observability-settings`
  permission gated only removed screens and is dropped from both seeders. The
  Telegram and WhatsApp alert adapters are removed; Slack, Discord, Teams and
  the generic webhook remain.

- **P2P orders are addressed by order number.** Links mailed before the
  number existed still resolve by id, and existing rows are numbered by the
  migration, so an upgraded install addresses its old orders the same way as
  its new ones.

- **Nothing changes for the customer activity log.** It now says "customers
  only" explicitly, so it lists exactly what it listed before.

### The admin shell

- **The sidebar and the header are rebuilt.** The menu is re-ordered onto a
  new information architecture with one owner per badge, the rail says which
  page you are on, one group is open at a time and the page decides which,
  and the collapsed state survives a reload. One easing curve is stated once
  for the whole shell, and reduced motion once rather than as a list of six
  selectors that every later transition silently opted out of.

- **A page arrives rather than lands.** Only the content column animates: the
  column and the header are identical on every screen, and animating them on
  a page load is what read as the whole window jumping. A real cross-document
  view transition switches the fade off, so the fade is the default and a
  browser the detection misses is left smoothed rather than stranded — which
  is how Firefox came to be receiving no smoothing at all. The rules are
  written down and held by a test suite that turns red if any of them is
  reintroduced.

- **The top bar is a command palette and a triage bell.** Search covers
  pages, users and transactions through one permission-filtered endpoint,
  throttled, with scope chips, aborted stale requests and per-admin recents.
  The bell is a feed of the latest ten notifications, read or not, with
  All / Unread / Action needed tabs and a mark-all that settles in place
  instead of reloading.

- **One header for every admin page.** `<x-page-header>` — breadcrumb,
  identity row, one H1, one status pill, one primary action, the ⋯ menu and
  an optional stats strip — now draws every screen in the panel. The two
  adapter components it replaced are retired, and the rule that new pages
  call it directly is written into the repository's own instructions.

- **Fixed: the sidebar was highlighting nothing.** The link partial wrote
  both a `class` attribute and an `@class` directive; a parser keeps the
  first and drops the second, so every active and open state the sidebar
  computed was thrown away. Only `aria-current` survived to say otherwise.

- **Fixed: the mobile drawer had no sub-menus** for any operator whose
  desktop rail was collapsed, because the drawer's revert list forgot them.

- **Fixed: one roleless account took the whole Team Members page down.** The
  table read `->id` off the first role without guarding it, so an account
  whose role had been deleted answered every administrator with a 500, and
  the only way back was editing the database.

- **27 KB off every admin page**, from 116 rules in `custom.css` whose every
  class is referenced nowhere — 62 of them under a modifier no template
  renders and no script adds, so every selector below it was unmatchable
  however live its descendants looked.

- **The webfont payload falls from 1.9 MB to 0.53 MB.** The same seven faces
  as woff2, transcoded from the pinned files already in the tree rather than
  refetched from Google — which would have moved the metrics under every
  admin screen — and verified glyph for glyph against the originals. Inter at
  weight 300 is gone entirely: 318 KB nothing in the panel had asked for.

### The dashboard

- **One screen, four questions, in the order an operator asks them:** what
  needs my action, how is money moving, is the platform healthy, is anything
  risky. The sixteen-card metric grid and four separate chart panels are
  replaced by review queues that say how long the oldest item has waited and
  how much money is behind it, today's volume and fee revenue restated into
  the base currency, the treasury, a thirty-day movement chart, platform
  health and recent activity.

- **Every panel owns its empty state.** A fresh install gets the setup
  checklist in place of the queue, a platform under a week old gets "not
  enough data" instead of a percentage against zero, and six empty queues get
  the all-clear rather than six rows of dashes.

- **SLA targets are admin-editable**, seeded additively with a default on
  every read, so an installation that has not run the seeder still classifies
  its queues. Each SLA state carries an icon as well as a colour.

- **Two new review queues, tickets and P2P disputes.** The disputes row is
  omitted when the marketplace module is off, because that screen is
  feature-gated and the Review button would 404.

- **The risk panel reports the four patterns it can actually measure.** Two
  from the handoff are absent on purpose: there is no failed-login row in the
  schema for repeated-failure detection to read, and no document hash for
  duplicate-document detection. An empty panel is honest; a plausible fraud
  alert with nothing behind it is not.

- **Admins are told when a queue goes late**, and how many are late.

- **Money in & out opens on thirty days** and is drawn from server-computed
  percentages — four figures and at most thirty columns did not justify a
  charting library, and a canvas runtime would have put a second copy of the
  layout in JavaScript.

- **Rate problems are reported as health rows, not a red banner**, and a
  demo install is told why the travel signal fires on it.

### Background tasks

- **Four tabs, one design.** Commands, Run history, Failed jobs and Setup had
  drifted into four treatments of the same module; they share one card head,
  one code block, one confirmation and one toast now. The module used to
  state the same health facts four times over — header chips, stat cards, a
  tab badge and a 29 KB notice partial — and states them once, in a strip
  where each chip links to the tab that can act on it, with an attention bar
  that appears only while something is measured as wrong.

- **A run reads as one row.** Run history is a disclosure — time, the command
  with the flags it was really invoked with, status, first output line,
  duration and who triggered it — with the full output underneath, rather
  than three columns and a duplicated mobile card list. A 24h / 7d window
  joins the day filter, because "the last 24 hours" is not a date and a run
  at 23:58 yesterday is usually the one being looked for.

- **Fixed: a failed scheduled command was logged as a success.** The
  scheduler dispatches its finished event whatever the child process
  returned, and the failure hook only sees an exception raised while starting
  it. The exit code is read now.

- **Fixed: Retry and Delete on a failed job did nothing and said they had.**
  Both posted the row id while the database failer looks jobs up by uuid, so
  the command printed a miss, exited 0, and the controller flashed success
  over nothing.

- **Fixed: one dead payload blocked every retry behind it.** `queue:retry`
  unserialises a payload to read its retry window, so a job carrying a
  deleted model threw out of the command — and because it retries in a single
  loop and the failer returns newest first, one unrestorable job at the top
  of the list meant none of the rest were ever retried. Measured on a real
  checkout: 595 failed jobs, 331 unrestorable, and the newest of them was
  stopping all 264 good ones. They are retried one at a time now and the
  screen names both counts.

- **Delete unretryable jobs.** Until now the only way to be rid of a job
  whose record has been deleted was Flush, which takes the whole table. The
  narrower action sits above it in the warning tone and reports both counts.

- **Fixed: the queue chip called an unmeasurable driver "not detected"** and
  called `sync` an active worker with nothing pending; the attention bar
  quoted a ten-minute window against a service that measures five; and the
  Setup note pointed at the wrong file for a schedule registered elsewhere.

- **The cron builder is retired.** This application needs exactly one cron
  line, and a builder offering "every 5 minutes" invited the one crontab that
  breaks the schedule. Seeing how often each command runs — what the builder
  was really for — is read off the scheduler on the Commands tab. The cPanel
  worker line the redesign had dropped is back under the Supervisor block,
  for hosts with no Supervisor.

### Activity history

- **A bare table of arrivals becomes a risk screen.** A four-cell stats
  strip, a filter toolbar with removable chips, a day-grouped table with a
  Risk column, and a detail drawer whose one action follows from the verdict.
  Sign-in rows gain city, ISP, coordinates, device, method, verdict, reason,
  session and failed-attempt count.

- **Each arrival is judged against that account's own history.** Impossible
  travel blocks; an anonymising network or a burst of refusals is suspicious;
  an unseen country or device is a new device. An account's first sign-in is
  never flagged. Thresholds and retention are admin settings under Security,
  with a nightly batched prune.

- **The account holder is told when a sign-in is blocked.** The risk engine
  used to lock accounts without a word, which is indistinguishable from an
  outage to the person it happened to — and they are the only one who can say
  "that was me, I was travelling". Email and push name the location, device,
  time and the rule that fired. Delivery is best effort and deliberately
  cannot undo the lock: a mail server that is down must not leave a
  compromised account open.

- **Fixed: a blocked verdict was only a label.** Nothing refused the next
  sign-in, and the session teardown ran only on the database session driver,
  so on a file-driver install the screen claimed "Session terminated" while
  the intruder's cookie stayed valid. Impossible travel now suspends the
  account through the same gate the login already enforces, revokes its
  sessions and tokens, and unblocking lifts the suspension as well as
  clearing the label.

- **Fixed: the date range button lied.** A legacy picker script included on
  every admin page overwrote the input from a handler that runs after this
  page's own.

- **The IP blocks table finally has an admin screen**, and every write busts
  the cache the blocking middleware reads.

- **The screen enforces the seeded activity-log permission**, which it never
  did before, and its destructive actions require user management.

- **Long addresses stay inside their cell**, and on a demo install every
  address is masked to its first two groups — in the table, the drawer and
  the export alike.

### Project updater

- **A state-driven release console.** The Release card, environment
  readiness, update history, the licence and backup rail, the install confirm
  and the live install run are each their own partial under the shared
  header.

- **Fixed: the page could offer a downgrade.** The `status` column is frozen
  at check time, so a v2.0.1 row still marked "available" from a check five
  months earlier had a 2.6.4 installation offering to install it — older
  files copied over the application and that release's migrations run. The
  offer is gated on the version being newer now, and install refuses anything
  that is not, equal only as a retry of a failed run.

- **Fixed: "Up to date" from a three-month-old check.** It says the check is
  out of date instead. And packages the release server ships unsigned no
  longer claim to be "signed and sha256 verified".

- **Fixed: the history rendered update checks as if they were installs** —
  three different old versions all labelled "Current", four columns that
  could never be filled. Rows say which kind they are, and only an install is
  asked how long it took and who ran it.

- **Roll back the files an install replaced.** Every install already copied
  the pre-update version of each file it touched into a snapshot; rollback
  puts them back. This is a deploy rollback in the Envoyer sense — the code
  returns to what it was and the database is left exactly as it is. The
  snapshot's `database.sql` is deliberately never replayed: that dump
  predates the install, so restoring it would erase every transaction, wallet
  movement and KYC decision written since, which on this application is not a
  recoverable mistake. The manual path is documented in the snapshot and the
  confirm dialog leads with the fact that the database is untouched.

  Each guard is covered by a test that fails when the guard is removed: only
  the release in force or one whose install failed can be rolled back; the
  snapshot has to still be on disk; its manifest goes through the same
  traversal and protected-path check as the package manifest; a pre-rollback
  snapshot is taken first, so the rollback is itself reversible; a stale page
  is refused on a version mismatch; and install and rollback share one lock,
  because run together they interleave file copies and leave a tree that is
  neither release.

- **Fixed: a rolled-back release made the header announce "Up to date"**
  while sitting on the older version with the newer one plainly ahead of it.
  That question is asked of the version now and of nothing else.

- **The recovery backup cannot be downloaded on a demo install.** The updater
  is exempt from the demo write-block because installs and rollbacks are what
  an evaluator came to try, but that exemption also covered an endpoint that
  builds a zip of a full database dump plus the storage folder and streams it
  to the browser. It is refused at the route and again in the service, so no
  console command or future route can build the archive either.

### Feature management

- **The catalog is organised by category.** Twenty switches in one flat list
  become a rail counting each category's enabled features, an overview card,
  and a card grid filtered to the open category with All / Enabled /
  Disabled / Core and a search box. Each card carries its catalog key, a CORE
  or ROLE tag where it applies, and one chip per panel, tinted where the
  feature is visible. The controller, its routes and every field name are
  untouched.

- **The Manage screen is built around panel tabs**, so what a feature exposes
  on each panel is edited where it is read.

- **Fixed: an Arabic admin got an English confirm dialog.** The script
  assembled its sentences itself — `"Disable " + label + "?"` — on every
  core and role toggle. Every string is read off a data attribute the blade
  writes in the administrator's own locale, and the test that pinned those
  literals, and so was guarding the defect, states the rule that replaced
  them.

- **Fixed: a filtered-out card could stay on screen** with the DOM reading
  back hidden, because the user-agent `[hidden]` rule is outranked by the
  card's own `display: flex`.

### Integration Center

- **The page says why a provider is off.** One Active/Inactive pill cannot
  tell a provider switched off on purpose from one whose keys were never
  typed in, and those are the two halves of the only question the screen is
  opened to ask. The seeders ship placeholders that are the key's own name,
  so anything checking for emptiness read a brand-new install as fully
  configured; a provider now resolves to active, needs setup, or off.

- **On that:** an overview card counting all three, per-group rails, a strip
  that appears only when something is genuinely missing keys, per-group
  search and filters, and a switch on each card that posts status without
  opening the dialog.

- **That switch runs the same reCAPTCHA activation guard the full save
  does** — the guard that keeps a widget with unusable keys from locking
  every admin and user out of login — and its endpoint never reads a
  credential from the request, so it can turn a provider on and off and
  cannot be used to write a key.

### Media library

- **A three-pane workspace.** Folder rail with per-folder counts, type chips
  that carry counts for the folder in view, grid or list (remembered per
  admin), and an inspector that saves title, alt text, caption and folder
  without a page reload.

- **Drag and drop uploads.** Files dropped on the library upload to the
  folder in view and report what failed, per file, instead of bouncing the
  admin to a redirect.

- **Storage usage is reported.** Summed from the assets rather than asked of
  the bucket, held for five minutes and dropped the moment a file is added
  or removed, so the rail never contradicts the grid beside it. A provider
  can be given an optional allowance.

### Team members

- **Presence, 2FA and activity per row**, with search, role chips and an
  All / Active / Inactive filter, and a drawer that edits one member in
  place.

- **Three new actions, all behind `staff-edit`.** Switching an account off
  cannot be done to yourself or to the last active super admin, and ends
  that member's sessions. Reset password mails a link to the address on
  file — the screen never sees a password. Revoke ends open sessions and
  rotates the remember-me token.

- **One way to edit a member, and it is Manage.** A kebab beside Manage used
  to open a second form with the same fields plus an avatar and a password.
  It is gone, with its modal and the `GET admin/staff/{id}/edit` route behind
  it. Manage itself moved from a drawer beside the table into a modal,
  unchanged inside.

- **A password can no longer be set from another admin's browser.** The
  deleted form carried password fields and `update()` hashed whatever
  arrived; Manage never offered them. The endpoint now ignores a password
  posted to it — **Reset password** mails the member a link instead.

- **The page wears the shared admin header** and no longer prints its own
  breadcrumb above it.

- **Fixed: the sign-in history could refuse a sign-in.** Recording an
  arrival happens after the credentials have been accepted, but a failure
  writing that row was thrown out of the listener — so on an installation
  whose `login_activities` had no `admin_id` column yet, every successful
  sign-in, administrator and customer alike, answered with a 500. The
  session was already written, so reloading appeared to fix it. The write
  can no longer decide the outcome of a sign-in, in either direction, and a
  history row that cannot be written is logged as a warning instead.

### Roles & permissions

- **The roles index is a table again, and permissions have a page.** 143
  capabilities never fitted beside a role list. The index now scans — name,
  type, how many permissions of the total with a progress bar, who holds the
  role, when it changed — and **Manage permissions** opens a full-width
  editor at `admin/role/{id}/permissions`.

- **The editor edits one module at a time.** All 42 modules are rendered up
  front and the page shows one, so switching never loses your place or jumps
  the scroll. A rail filters them by name or by whether the role holds any of
  them; each row carries a `granted/total` tally. Per module there is **View
  only**, **Full access** and **None**, plus a grant-all switch.

- **It saves as a plain form.** Every switch is a real checkbox posting
  `permission[]`, so the page saves correctly with JavaScript switched off —
  the script only keeps the tallies, the progress bar and the unsaved count
  in step. Permissions are sent by **name**, not id, so a page left open
  across a reseed grants what it displayed rather than whatever now occupies
  those id numbers.

- **Seeing permissions and changing them are separate rights.** The editor
  opens for `role-list`; the save is refused without `role-edit`, whether or
  not the button was on screen.

- **One screen per role, not two.** Naming a role and choosing what it can do
  is one decision, so the separate *Edit role* page is gone — along with its
  route, its form and the Edit button on the index. The permissions page
  carries the role's name and description, and **New role** is that same
  screen with nothing granted yet.

- **One endpoint saves it.** The editor posts name, description and
  `permission[]` together to `PUT admin/role/{id}`; the create screen posts
  the same shape to `POST admin/role`. Both send permission **names**, so the
  create form no longer speaks in ids while the editor speaks in names.

- **Fixed: `GET admin/role/{id}` raised a 500.** The route existed with no
  controller method behind it. It answers the role as JSON now.

- **Fixed: super-admin was only cosmetically protected.** The index hid the
  button, but the update route accepted the id just the same — a renamed or
  narrowed super-admin locks an installation out of its own panel. It is
  refused server-side, and the matrix renders read-only.

- **The role editor is one screen that fits the viewport.** Its second header
  is merged into the page header, which carries the role's name and a Rename
  action in the ⋯ menu; the breadcrumb trail above it is gone.

### Manage user

- **One page for an account, from the premium handoff.** A stats strip in the
  header, section tabs, an overview chart with 7d / 30d / 12m ranges, a
  transactions tab with type segments and an export, flat referral, ticket
  and activity lists, and a rail of wallets, account controls and security.
  Six modals replace the old row of icon buttons: manage funds, notify, reset
  password, convert to merchant, suspend and delete.

- **The backend those modals needed.** Timed suspensions — reason, note and
  an end date — refused at login and by the status gate, and lifted
  automatically by a scheduled command. A must-change-password flag enforced
  on every request. An admin reset link through the password broker, or a
  temporary password. Sign-out-all revoking sessions and tokens.
  Convert-to-merchant requiring a business name. Delete requiring the typed
  word and, while any wallet holds a balance, an acknowledgement that the
  funds are forfeit.

- **Every figure is restated in the base currency** through the currency
  service and decimal money, not floating-point arithmetic.

### Notify users

- **A five-field form becomes a guided composer** — audience, channel,
  message, delivery — beside a live preview of the email or push as the
  recipient will see it, a readiness summary, and a confirm sheet before
  anything is queued. The POST contract is unchanged and the cards are real
  radios, so the form still posts correctly before the script has run.

- **It says out loud what the code already did:** only active accounts
  receive messages, so each audience card carries its active count; a push
  title is optional and defaults, so the row stays and says so rather than
  hiding; whether realtime is available is a fact of the installation; and
  the schedule is in the site timezone and cannot be earlier than now there.

### Transactions and the ledger

- **A row opens into the authoritative record, not a copy of itself.**
  Clicking a transaction used to build a drawer client-side out of twenty
  `data-trx-*` attributes, which could only ever repeat what the list already
  showed. A row carries one attribute now — the address of what it opens —
  and four endpoints back it: a detail payload that doubles as a deep link
  for URLs pasted into tickets, a branded receipt, and the two money-moving
  actions.

- **Approve and refund from the transaction you are looking at.** Neither
  reimplements anything: approve and reject delegate to the same calls the
  deposit and withdraw screens make, including the refund on a rejected
  withdrawal that returns held funds to the wallet, and refunds go through
  the merchant refund service, which owns the merchant binding, the
  refundable ceiling and the wallet debit under a row lock. The button being
  drawn grants nothing — every endpoint asks the same three questions again,
  because each is reachable without the modal, and each stays behind the
  permission its own screen already uses.

- **Gateway payloads are masked server-side.** They are rendered verbatim in
  a `<pre>`, so any key matching token, secret, password or key is redacted
  before it leaves the server, and the transaction token is not in the
  contract at all.

- **The ledger gets a summary, a detail drawer and a CSV export.** The four
  counts above the table are the filters that produce them. The export
  streams the rows the screen is showing, built from the one filter array the
  list reads so it can never describe a different set, and chunked by id
  rather than collected — this table runs to hundreds of thousands of rows on
  a live install. Amounts are quantised to the currency's own precision and
  written without separators so a spreadsheet reads them as numbers; a UTF-8
  BOM leads, because Excel reads a BOM-less file as the local codepage and
  turns every non-Latin name into mojibake; and any cell opening with `=`,
  `+`, `-` or `@` is escaped, since names and descriptions are end-user
  input.

- **One transactions table, on the ledger and the dashboard**, with a compact
  variant rather than a second copy, and a printer icon that reaches a
  printable page instead of a download.

- **The detail panel opens instead of appearing.** It was shown with `hidden`
  alone — measured in a browser at full opacity on its first frame — so it
  replaced the page between two frames. The scrim now fades and the dialog
  rises into it, and closing runs the same motion backwards. Reduced motion is
  respected, and the panel still closes instantly when that is asked for.

- **Fixed: a transaction could show the figures of the one opened before it.**
  The panel is revealed and *then* fetched, and nothing cleared the previous
  values on the way in — so the amount, status and id of the last transaction
  stayed on screen until the response landed. A skeleton now covers them until
  the real ones arrive.

- **Fixed: the panel's script was never cache-busted.** Every sibling asset
  carries a `filemtime` query; this one did not, so a browser that already had
  the file kept running it after a deploy. All three screens that mount the
  panel now version it.

- **Fixed: a failed load left its message on the next transaction.** "This
  transaction could not be loaded" was cleared only by a later successful
  load, and it sits below the body where the skeleton does not reach — so the
  next row opened showing a skeleton and that message at once, about two
  different transactions. Starting a fetch now clears it.

The same panel is mounted on the dashboard, and was checked there: identical
motion, skeleton and behaviour.

### System Health

- **Four screens are removed outright**, with everything behind them: an
  Overview chart nobody triages from, a Live tail duplicating the issue list,
  a Data & privacy preview of a pipeline already covered by tests, and
  documentation rendered as a screen. The module root serves the issue list,
  so the module lands where somebody actually works.

- **The error detail screen is three tabs and an editor.** It asked eight
  questions across eight tabs when a reader opens it to ask two: what broke,
  and what to hand somebody fixing it. Summary and stack trace are one Error
  tab that opens on the failing line; request, breadcrumbs, occurrences,
  related and activity gather behind Details, still queried only when opened.
  Code panes are syntax-coloured server-side, so there is no library to ship
  and no repaint after load on a screen somebody opened because something is
  already wrong. Links to a retired tab land on the tab that absorbed it.

- **Every list on that screen has a budget**, because the errors worth
  reading are the busy ones. The copy and the download still carry
  everything.

- **Two panels that described behaviour the code did not have are gone.**

### KYC

- **A provider-hosted verification journey.** When automation is on and an
  enabled provider covering the applicant's country can host the capture, the
  template form is replaced by a handover: consent, then a redirect into
  Sumsub's WebSDK or ShuftiPro's flow, with the verdict arriving on the
  existing webhook. Drivers opt in, so Smile ID, an uncovered country and an
  applicant with no country on file all keep the form.

- **A failed handover rolls back** rather than leaving a documentless row in
  the manual queue, and an abandoned provider session is offered for resuming
  instead of stranding the applicant on a pending row.

- **The KYC page explains itself when no verification type applies**, rather
  than rendering a type picker with nothing in it.

- **Fixed: ShuftiPro is sent an empty face object, not an empty array.**

### Mail

- **A premium transactional template.** The flat blue header over a paragraph
  becomes a 600px card — brand row, gradient accent, optional status pill,
  headline, body, optional details panel, a bulletproof button with a
  fallback link, a security strip and a footer — built as tables with inline
  CSS so Gmail, Outlook and Apple Mail draw the same thing. The brand is the
  installation's own, and footer links resolve against routes the
  installation really has and are dropped rather than guessed.

- **Fixed: a temporary SMTP refusal failed a queued notification for good.**
  A daily sending limit, a 4xx greeting or a transport that never opened a
  session is now classified, waited out on a widening backoff on the mail
  channel only, and a permanent rejection still passes straight through.

- **Fixed: a worker never saw mail settings saved after it booted.** A worker
  runs for hours, so every queued email kept using the SMTP values from boot
  time. They are re-read before each job and the mailer rebuilt when the host
  changes.

- **Fixed: the admin's single-user email reported success before anything was
  sent.** It sends inside the request now, reports the host's refusal as an
  error, refuses to claim success for an inactive user, and warns when the
  queue worker has stalled. Bulk and scheduled sends still queue, and say so.

### Money, providers and the queue

- **StroWallet is aligned with the current API reference.** Deposits go
  through the documented `/pay` checkout, with the callback bound to the
  transaction by reference and verified against the Checkout Status API
  before settlement; the signed express IPN keeps working for in-flight rows.
  Fund, withdraw, freeze and details each call the documented endpoint first
  and fall back to the legacy path only on a bare 404 or 405 — never on a
  refusal or a server error. The OTP webhook now reaches the cardholder, the
  balance hook is answered, and transaction created/refund hooks are recorded
  rather than answered as authorisations.

- **Fixed: every FX quote was paying for a redirect.** The keyless fiat route
  asked a host that now exists only to answer 301, so each quote spent a
  second DNS lookup and TLS handshake out of one five-second budget — and the
  connection that timed out was the redirect target, which is why the error
  reports named a URL no file here wrote. The endpoint is a setting now, so
  the next move needs no code change.

- **Fixed: a failed notification could fail a committed trade.** The P2P
  order notification defers to the after-commit flush, which is not covered
  by the transaction's own guard, so on a sync queue an SMTP rejection
  propagated out of the transaction after the order, the escrow hold and the
  ledger entry were already durable — and the taker got a 500 for a trade
  that had in fact succeeded, with the counterparty's funds held in escrow.

- **P2P orders carry a reference of their own.** Every link used to carry the
  row id, which told a customer how many orders the platform had taken and
  let anyone walk the sequence.

- **An administrator can set symbol position and digit grouping per
  currency.** The two display columns shipped with the dashboard work had no
  admin surface, so a suffix symbol or lakh/crore grouping was reachable only
  by editing the database. Each option shows what it produces using the
  currency's own symbol, and a form posted without the fields cannot silently
  reset how a currency reads.

- **Fixed: the ledger audit reported itself as failed.** The audit is
  read-only and returns success on purpose, and the notification then
  labelled that same event FAILED — one run reading "success" in Background
  Tasks and arriving as a red pill in the bell beside it, over a message
  whose own last sentence is "Nothing was modified." A REVIEW case in amber
  says what it means, and both surfaces still count it as needing action.

### Security

- **The SMTP password and every plugin secret were printed into the admin
  forms as input values** — CoinMarketCap, Currencylayer, IPinfo, Twilio,
  Pusher, reCAPTCHA. Anyone with the settings screen open, including a demo
  evaluator, could read them from the page source and reuse them. Secrets
  never leave the server now, and at rest they are sealed with the
  application key, so a copied row or a database export is useless elsewhere.

- **Fixed: a duplicate phone number ended registration in a 500.** The unique
  index shipped in an earlier release but none of the three registration
  requests validated against it — and a plain `unique` rule would not have
  caught it, because the controllers store a formatted number while the form
  posts the bare national one. Each request formats the input the way its own
  controller does before looking it up.

- **Fixed: an unknown blog slug answered with a 500, not a 404.** Crawler
  traffic for URLs that are not blog slugs therefore ended in an error page,
  and each miss wrote a permanent null into the cache on its way there.

- **Fixed: a stale `bootstrap/app.php` took down the whole user area.** An
  update that refreshes `app/` and `routes/` but leaves that file as
  installed gives the router a middleware alias it has never heard of, and it
  resolves it as a class name — so every route in the group failed, not one
  feature, and signing up or logging in ended on a 500. The alias is
  re-registered when the running bootstrap file does not declare it, and left
  alone when it does.

### Performance

- **249 queries down to 94 on the admin dashboard, and 164ms to 39ms.**
  Measured rather than guessed: 134 of them were `information_schema` round
  trips from the check the `setting()` helper runs before every single read,
  and 16 more from the default-currency lookup that money formatting reaches
  for on every amount. Both latch their positive answer only — a table that
  exists cannot vanish mid-request, while a false answer has to stay live so
  the installer sees the table the moment it creates one.

- **The growth panel was caching password hashes.** It cached Eloquent user
  models for sixty seconds, and `$hidden` governs array conversion, not
  serialisation — so every hashed password and wallet PIN in the four newest
  signups was being written into the cache store to make a dashboard faster.
  It caches the five display fields it draws.

- **Also measured and fixed:** three probes that ran twice per request; a
  chart aggregation paid for and discarded on every ajax branch and by every
  role that cannot see the chart; a summary that loaded every row decided
  today across five tables into memory to produce a count and a max, both of
  which are SQL aggregates; a growth comparison counting a boundary row in
  both periods; a future-dated timestamp reading as badly over SLA because
  the difference was absolute; a timezone setting read under a key that has
  never existed; health chips linking every administrator to screens their
  role may not open; and a range change that fetched the payload, discarded
  it and reloaded, running the whole aggregation twice.

- **The treasury total is added up by the database**, not in PHP.

### Internationalisation

- **Each language is named in its own language.** The picker showed the
  English name of every language, in a list read by people who cannot read
  the page it sits on — and someone who needs the picker needs it precisely
  because the current language is one they do not read. The stored endonym is
  the source of truth and an administrator can set it; it is filled in only
  when left blank, from `ext-intl` where the server has it and a table of
  twenty-two endonyms otherwise, and left null rather than guessed.

- **The admin dashboard, the shell and the background-tasks registry are
  translated** in English, Arabic and Spanish, including the strings that had
  shipped with empty values.

- **Sides are logical throughout.** The remaining physical `left`/`right`
  rules across every admin stylesheet are replaced with their logical
  equivalents, reserved icon and indent space moved onto the inline axis, and
  a test forbids the physical properties coming back — so an Arabic panel
  mirrors instead of stranding a control at the wrong end of its row.

### Demo installs

- **More of the panel can actually be tried.** The review queues, System
  Health triage, and the whole Background Tasks screen — commands, retries,
  forget and flush — are allowed, because a tab where half the controls
  refuse is a worse demonstration than either extreme. Every allowed action
  rewrites state the application recreates, with `forget` and `flush` the
  documented exception. The observability channel routes, which connect a
  real Slack or webhook destination, stay blocked.

### Calmer surfaces

- **The coloured rule above each dashboard counter is gone.** Five of them in
  a row read as tabs rather than as figures, and each card already carries its
  colour in the icon tile, the sparkline and the corner wash.

- **The brand stripe above every auth card is gone**, on all fourteen sign-in,
  register, reset, confirm and verify screens. The role still reads from the
  title and the primary button, which carry the same gradients — a coloured
  rule as well made the panel look banded rather than branded.

- **A queue past its SLA no longer fills its row with red.** The progress bar
  ran the full width in `--color-danger-deep` and the action was a solid
  crimson button — the shape this design system uses for destructive
  confirmations — so a list of ordinary work looked like a list of failures.
  `--sla-over` is rose-700 now, carried at full strength, and the action is
  outlined rather than filled: the weight comes from hue and contrast instead
  of from a slab of colour.

### Housekeeping

- **Code and assets nothing has loaded since before 2.3 are removed:**
  nineteen Blade files no controller returns and no view includes, two
  stylesheets no layout links, forty editor backup copies of SVG icons
  committed beside the real files, three seeder scaffolds that were never
  registered, five unreachable methods, and CSS for modifiers no view
  composes. Dynamically resolved files were checked one by one and kept.

- **Four things that reached the buyer zip no longer do:** a design bundle
  whose work was implemented the day it arrived, a sandbox probe script, three
  audit CSVs generated from a development database, and an unzipped copy of
  the WooCommerce plugin that duplicated the `.zip` beside it. Each path was
  added to every list that governs it, and the result was checked against the
  build command rather than by reading the patterns.

---

## 2.6.4

A review pass over everything that shipped in 2.6.3, with the defects it
found fixed and the modules it under-described written up properly.

### Upgrade notes

- **No new Composer packages.** Two guarded migrations run (an admin
  two-factor column and a rollup slot column type change).
  `install:reconcile` seeds everything else.

- **Sign out and back in to see System Health.** Admin permissions are
  cached in the session, so the new observability entries appear in the
  sidebar after the next sign-in.

- **Mail configured only through `MAIL_URL` no longer applies.** Since 2.6.3
  the admin panel's SMTP settings are the source of truth and the DSN is
  cleared at boot. If a site relied on the DSN and left the admin fields at
  their placeholders, fill in *Settings → Mail* after updating.

- **Virtual card balances correct themselves on the next sync.** A card
  funded from a wallet in another currency stored its balance in wallet
  units; the scheduled sync now writes the provider's own figure.

### Security

- **A client can no longer choose the host of a password-reset link.** 2.6.3
  trusted `X-Forwarded-Host` from every proxy, and nothing forces the root
  URL, so a forgot-password request could deliver a link pointing at a host
  of the attacker's choosing. Only the scheme and port headers are trusted
  now.

- **Secrets no longer reach the error records.** Exception messages were
  stored as written, and a failed insert carries the customer's email and
  password hash while a rejected gateway call carries the rejected key.
  Messages are scrubbed before storage or alerting. Wallet PINs, one-time
  codes and account numbers in a failing request body were stored in clear;
  they are dropped now.

- **The upload lists cannot admit executable files.** An administrator
  typing `php` into the new Media & Uploads lists would have turned the KYC
  form into a way to run code on the server. A fixed deny-list applies to
  every list and the uploader refuses those types regardless.

### Fixed — virtual cards (money)

- **A provider that never answered is no longer refunded.** A gateway error
  page or a server error from StroWallet on a fund or unload was read as a
  refusal (and refunded) or, with no status flag, as a success. Both are
  now parked as unfinished. The same rule applies to card issuance: a call
  that never completed keeps the fee and the initial load, notes it on the
  request, and reuses both when the request is approved again.

- **A request can only be approved once.** Two admins, or one double click,
  could issue two cards and take two loads. The fee now leaves under a lock
  on the request, and the provider is called with no database transaction
  open, so a wallet is never locked for the length of a provider round trip.

- **A withdrawal is written down before the card is unloaded.** A local
  failure after the provider had moved the money left it off the card with
  no record. The ledger row is pending before the call, failed on a refusal,
  left pending for reconciliation when the provider never answers, and
  completed with the wallet credit only on a confirmed unload.

- **A live card reported cancelled is blocked, not refunded.** An admin
  refresh on a card the provider had cancelled months after issue marked it
  failed and returned its initial load a second time.

- **A sync without a balance no longer zeroes the stored one.**

### Fixed — error management (observability)

- **Alerts hold during a burst.** Throttling looked for a delivered row,
  which only exists after delivery, so a burst that enqueued hundreds of
  alert plans sent every one. The window is claimed when the alert is
  planned and checked again at delivery. Ignored groups are never announced.

- **The System Health settings section renders.** Retention and quiet hours
  were seeded but the settings screen never drew the section, and its number
  field type had no template.

- **`assigned:` in the issues search no longer answers with a 500.** It
  filtered on a column the admins table does not have.

- **Each error counts once.** A failing queue job was captured twice, filed
  under cron, and its counters were read-modify-write. One capture, filed
  under the queue with the job on it, atomic counters.

- **Retention catches up.** The prune stopped at five thousand rows a night
  and never touched the delivery log; both are cleared to the window now.

- **Rollup buckets on MySQL 5.7 and MariaDB before 10.10.** The slot column
  silently gained ON UPDATE CURRENT_TIMESTAMP on those servers, so every
  increment moved it and the Overview kept one bucket per event.

### Fixed — admin, KYC and QR

- **The admin two-factor challenge page rendered a 500** for every admin
  with two-factor on. The column it reads also never existed on an
  installation built from the migrations; a guarded migration adds it.

- **A resubmitted KYC application no longer carries an earlier consent.**
  When automation had been switched off in between, the previous attempt's
  consent date, address and wording stayed attached to the new documents.

- **The QR scanner decodes on Chromium builds without barcode support.** An
  empty list of supported formats was treated as "QR supported", so the
  native detector rejected every frame and the fallback was never fetched.

- **Cardholder and P2P document uploads follow the Media & Uploads
  settings**, so a file the form accepts is never refused by the uploader.

- **The theme composer survives duplicate block keys.** Two administrator-
  made blocks sharing a key made a theme round trip drop every block the
  other theme lacks.

### What 2.6.3 actually contained

The 2.6.3 notes summarised several modules in a line each. In full:

- **Error management.** Every exception, failed job, failed scheduled task,
  failed outbound HTTP call and slow query is captured into grouped issues
  with occurrence counts, affected users, releases and a redacted request
  snapshot. Admin screens: Overview, Issues (with a search grammar:
  `level:`, `source:`, `is:`, `assigned:`, `code:`, `age:`), Live tail,
  Error codes, Notification channels (Telegram, Slack, Discord, Teams,
  WhatsApp, generic webhook), Data & privacy, Integrations & SDK. A public
  `SystemHealth::report()` facade and a browser SDK feed the same pipe.
  Alerts route by severity with throttling and quiet hours; a debug report
  can be exported per issue with a strict redaction check.

- **Theme Manager.** Three site themes (Classic, Golden, Emerald) with a
  per-theme colour panel, CSS thumbnails and a component library scoped by
  theme. Page compositions are remembered per theme, so switching back
  restores the page exactly.

- **Virtual cards in their own currency.** A USD card funded from an NGN
  wallet is priced at the stored rate; the rate is written on the request,
  the top-up and the ledger; fees are charged in the wallet currency; a
  five-minute sync settles pending cards; ambiguous provider outcomes are
  parked, never guessed.

- **StroWallet hardening.** Credential diagnostics against the card
  endpoint, response-shape handling for every answer format the provider
  has used, refusal of private and local image URLs, phone and expiry
  normalisation.

- **Email delivery hardening.** Links signed as https survive a proxy that
  speaks plain HTTP to PHP; registration completes when the verification
  mail cannot be sent; the resend button tells the truth.

- **Merchant checkout KYC gate.** A guest payer's KYC state is checked only
  after the wallet PIN proves who they are, and never revealed before.

- **API errors answer as JSON.** A `NotifyErrorException` on an API route
  returns the message with the exception's HTTP status, never a redirect.

---

## 2.6.3

### Upgrade notes

- **No new Composer packages.** `composer install` is not required for this
  release. Four migrations run, and `install:reconcile` seeds the new settings
  sections, the observability permissions and the Emerald theme components, so
  nothing needs to be done by hand after the update.

- **Automated KYC is now an admin setting.** *Settings → KYC Verification*
  carries the automation switch and its two risk thresholds. The `.env` flags
  keep working as the fallback until the settings rows exist, after which the
  admin panel is the source of truth.

- **Applicants must consent before automated verification.** While automation
  is on, the KYC form requires the applicant to agree to a consent statement
  before submitting. Submissions made before this release, or while automation
  was off, carry no consent record; the provider is never called for them and
  they are routed to manual review instead.

### Fixed — QR and payment links

- **The QR scanner reads codes on Safari, iOS and Firefox.** The in-app
  scanner decoded only through the `BarcodeDetector` API, which ships in
  Chromium alone, so on every other browser the camera opened and silently
  never read anything. A fallback decoder is now loaded only when the native
  API is missing, so Chromium users download nothing extra.

- **A scanned checkout can no longer take a payer's details and then 404.**
  Only the pay endpoint was guarded by the `payment_link` feature switch; the
  checkout page itself rendered, collected the payer's details and wallet PIN,
  and refused the submission with a bare 404 when the feature was off. The
  switch now removes the whole public payment-link surface.

- **Wallet payments on a payment link no longer refuse unverified payers.**
  Both wallet-PIN entry points hardcoded an email-verification check that the
  rest of the application only enforces when the admin setting is on. On a site
  with the setting off, effectively every wallet was refused at the last step of
  a scanned payment. The check now follows the setting.

- **Wallet surfaces show a currency mark instead of a broken image.** The
  currency catalog stores a bare code in `flag`, not an asset path, so the
  receive-QR card and the wallet list rendered a broken-image glyph next to
  most currencies.

### Fixed — KYC and uploads

- **A `.jpeg` identity document is accepted.** The uploader's hardcoded
  allow-list included `jpg` but not `jpeg`, while the form validated against
  both, so a photo straight off a phone passed validation and was then rejected
  as "Invalid file".

- **Upload limits are admin-controlled.** A new *Settings → Media & Uploads*
  section owns the upload size and separate image, attachment and
  identity-document type lists. The existing `max_upload_size` value carries
  over untouched. The enforced cap is clamped to PHP's own limits, and a Server
  Limits panel shows them, so a value above the server ceiling reports a clear
  size message instead of a discarded request.

### Fixed — admin and account

- **Admin subscription pages no longer 500.** The list, detail and
  transactions pages render when a subscriber or plan row no longer exists
  instead of throwing.

- **Email verification pages were blank.** The verify-email and
  confirm-password views were empty files. The verification link now works
  from any browser, verifies without signing anyone in, and redirects to login.

- **Amounts follow the wallet or site currency.** Gift card faces, the
  welcome-bonus popup, the delivery email and the admin gift-card table used a
  hardcoded dollar sign; design tiles re-format when the wallet changes. A
  virtual card funded from a wallet in another currency shows its balance in
  the card's own currency.

- **A long settings description no longer breaks the header.** The title
  block truncates and the description is capped at two lines, so Save and the
  section button stay on one row at every width.

- **The virtual-card request modal explains a missing cardholder.** It links
  to create one and hides Continue while there is nothing to pick.

### Added

- **Observability.** A system health API, an error collector with redaction,
  alert channels and admin screens for triage, plus an `observability:prune`
  command scheduled daily. Access is split across three new permissions.

- **Emerald theme.** A new site theme with its own layouts and sections. Page
  compositions now survive a theme switch.

- **Virtual cards in their own currency.** Card-currency FX columns, a
  `virtual-card:sync-pending-cards` command every five minutes, and hardening
  of the StroWallet gateway diagnostics and response handling.

- **Recorded KYC consent.** The consent statement is administrator-editable
  under *Settings → KYC Verification*. The exact wording shown, the timestamp
  and the IP address are stored on the submission, so editing the statement
  later cannot rewrite what an earlier applicant agreed to. The admin review
  modal shows all three.

- **Arabic and Spanish translations for the virtual-card module.** 28 new keys
  were added to all three catalogues and 194 Spanish strings that had never
  been translated were filled in.

---

## 2.6.1

### Fixed — updating on MariaDB

- **The update no longer fails on MariaDB.** Four migrations widen money
  columns, and because `->change()` regenerates a column definition in full,
  each reads the existing default back off the live schema to restate it.
  MySQL reports a `DEFAULT NULL` column as a real null; MariaDB reports the
  string `NULL`, and quotes literal defaults besides. Handing that back
  produced `default 'NULL'` on a decimal column, which MariaDB rejects:

  ```
  SQLSTATE[42000]: 1067 Invalid default value for 'min_limit'
  alter table `currency_roles` modify `min_limit` decimal(24,8) null default 'NULL'
  ```

  The update stopped there and rolled back. It only ever affected MariaDB.

- **A rate column can no longer be given a default of zero by accident.** Two
  of those four migrations cast the default to float instead of failing, so
  MariaDB's `NULL` string became `DEFAULT 0` on a conversion-rate or
  percentage column that was meant to have no default at all. Nothing reached
  them in practice — the crash above runs first and stops the update — but a
  zero rate does not truncate a conversion, it destroys it.

  Defaults read from the schema now go through one normaliser that treats a
  real null, `NULL`, `'NULL'` and empty alike as *no default*, unquotes the
  numeric forms MariaDB reports, and never turns an absent default into zero.

---
## 2.6

### Upgrade notes

- **Run the scheduler and a queue worker.** Several fixes in this release
  depend on them, and the admin dashboard now tells you when they are not
  running.

  ```
  * * * * * cd /path/to/site && php artisan schedule:run >> /dev/null 2>&1
  ```

  Keep a worker alive (`php artisan queue:work`) on a VPS, or drain the queue
  from a once-a-minute cron on shared hosting.

- **Run `composer install` after updating.** This release adds the
  `laravel-notification-channels/webpush` package. The updater never replaces
  `vendor/`, so web push stays switched off until the dependency is installed.
  Everything else works without it.

- **Email verification is off by default.** Turning it on is an explicit
  choice in *Settings → Security → Access Protection*. Read the note under
  "Email verification" below before enabling it on a live site.

- Admin password reset links issued before this update stop working. Anyone
  mid-reset should request a new link.

### Fixed — money and delivery

- **Scheduled gift cards are delivered.** A gift card bought for a future date
  was charged to the sender's wallet and then never sent: nothing in the
  application ever read the `scheduled` status back. A new
  `gift-card:dispatch-scheduled` command runs every minute and delivers them.
  Cancelled and expired cards are skipped, and a card can never be sent twice.

- **A missing notification template no longer reverses a transaction.**
  Notification channels are resolved inside the database transaction that
  moves the money, so an unseeded template rolled the whole settlement back
  and surfaced to the customer as "Deposit failed". Deposits, manual approval
  and rejection now complete whether or not the template exists.

- **A missing admin permission no longer reverses a payment.** The same defect
  in the admin notification path could roll back a settled wallet payment on
  an installation that had not seeded a newly added permission.

- **Legacy transactions can be bound to their originating gateway.**
  `payments:backfill-gateway-code` stamps transactions created before gateway
  binding existed, so callbacks can settle them again. It never guesses: a row
  whose origin cannot be proven is reported, not filled in.

### Fixed — email

- **Password reset tells the truth.** The reset form showed "Reset Link Sent
  Successfully" unconditionally — for an unknown address, a throttled retry,
  or a failed send. A broken SMTP configuration surfaced as an unhandled 500.
  Failures are now caught, logged and shown as a clear message.

- **Email verification works at all.** The `User` model implemented the
  verification trait but not the contract Laravel checks, so the framework's
  listener silently skipped every registration and no verification email was
  ever sent.

- **Admin-panel mail settings no longer erase the rest of the mail config.**
  Applying SMTP settings replaced the entire `mail` config array, discarding
  the log/array/failover mailers and the markdown mail configuration.

- **The "Email From Name" setting is honoured.** It was present in the admin
  panel but never read; outgoing mail used the site title instead.

### Security

- **Admin password reset moved onto the framework's password broker.** The
  previous flow stored the reset token in plaintext, never checked its age, let
  anyone enumerate admin email addresses through validation errors, and was not
  rate limited. Tokens are now hashed, expire after 60 minutes, are consumed on
  use, and both endpoints are throttled. Responses no longer reveal which
  addresses exist.

### Added

- **Background work health check.** The admin dashboard now measures whether
  the scheduler and queue are actually running — a per-minute heartbeat and the
  queue's own backlog — instead of showing a static setup reminder. A confirmed
  stall is shown even to an admin who dismissed the notice, and cannot be
  dismissed until the server recovers. Drivers that cannot be inspected report
  nothing rather than a false alarm.

- **Email verification, under an admin toggle.**
  *Settings → Security → Access Protection → Require Email Verification*, off by
  default. While off, no verification email is sent and nobody is gated. While
  on, new registrations receive a link and unverified users are held at the
  verification screen until they confirm.

  > Enabling this on an existing site does gate users who have never verified.
  > They can resend the link from the verification screen.

### Fixed — installation and upgrades

- **A fresh install no longer comes up missing its reference data.** The
  installer imports `DB/digikash.sql` only on MySQL; a SQLite install fell back
  to seeders that did not cover notification templates, plugins or the site
  builder. Those are now seeded, and the installer list covers every module.

- **`install:reconcile` runs after every install and update.** It seeds only
  additive data, so it can be replayed safely and never overwrites copy,
  credentials or switches an administrator has changed.

- **The feature catalog heals itself.** A feature added in a new version
  reached an existing database only if someone remembered to hand-write a sync
  migration; without the row, every route guarded by that feature returned 404.

- **Admin two-factor setup works on a migrated install.** The `admins` table
  was missing the `google2fa_secret` column that only ever existed in the
  shipped database dump.

- **The media library works without the `intl` extension.** It previously
  returned a 500 on hosts that do not ship it.

- **The marketplace commission account provisions itself.** A commissioned
  vendor payment fails closed unless the marketplace points at a protected
  commission account, and nothing ever created one except an administrator
  pressing "Create protected account" on the marketplace settings screen.
  Any site that never visited it rejected every commissioned order with "The
  protected marketplace commission account is not configured" — including
  sites upgrading from a build that stored a personal user id, because a
  personal account is no longer accepted. `install:reconcile` now provisions
  it on both install and update. A legacy personal account is repointed; its
  wallet keeps its balance, and only future orders switch.

- **A cached setting no longer goes stale for good.** Models that cache
  themselves cleared their cache from inside the writing transaction, where
  the new row is still invisible to other connections. A read arriving in
  that window stored the pre-commit row under a key nothing would clear
  again, so the marketplace could keep reporting a commission account as
  unconfigured after it had been set. The flush now repeats once the write
  has committed.

---

## 2.5 and earlier

Changes before 2.6 were recorded in commit history only.
