# Panelica WHMCS Server Module

Official WHMCS provisioning module for [Panelica](https://www.panelica.com) control panel servers.

Automates the full hosting account lifecycle through the Panelica External API
(HMAC-SHA256 request signing — API secrets are never sent over the wire):

| WHMCS action | What happens on the Panelica server |
|---|---|
| Create | Hosting account is created on the selected plan; the ordered domain is provisioned as a website (nginx+apache, PHP, Let's Encrypt SSL). If the website fails, the account is rolled back so retries are clean. |
| Suspend / Unsuspend | Account is suspended / re-activated |
| Terminate | Account is deleted (all panel resources removed) |
| Change Password | Panel login password is updated |
| Upgrade / Downgrade | Account is moved to the new Panelica plan — **kernel cgroup limits re-applied instantly** |
| Usage Update (nightly) | Disk & bandwidth usage and limits synced into WHMCS |
| Client Area | Full self-service suite (see below) — dashboard, email, DNS, files, databases, WordPress and more, without ever leaving WHMCS |

## Single Sign-On (one-click panel login)

With a **full-access (`*:*`) key**, WHMCS shows a **"Login to Panel"** button in
both the admin service page and the client area. One click logs the customer
straight into their hosting panel — no password. Under the hood the module
requests a one-time, 5-minute, single-use token (`POST /v1/accounts/{id}/sso-login`)
and WHMCS redirects to `https://panel:8443/auto-login?token=...`.

> Requires **Panelica server build with the `/v1/accounts/{id}/sso-login`
> endpoint** (added 2026-07). Older panels return 404 for SSO; the rest of the
> module works regardless. The key must carry the `*:*` scope for SSO.

## Client area self-service

Give the WHMCS API key the extra scopes and the client area turns into a full
hosting control panel — customers manage everything from inside WHMCS, without
ever logging into the panel. The product page loads instantly (an AJAX skeleton
renders first, then each tab lazy-loads its data):

- **Dashboard** — disk gauge, monthly bandwidth, domain/email/database/FTP counts
- **Email** — accounts (with quota), forwarders, autoresponders, and
  **Email Deliverability**: live SPF status and one-click **DKIM signing** with
  the exact DNS record to publish
- **WordPress** — every install detected on the account, each with **one-click
  wp-admin login**, **update plugins**, **update core**, and **backups**
  (create / list / one-click restore)
- **DNS zone editor** — A / CNAME / MX / TXT / … records (create / list / delete)
- **File manager** — browse, create folders/files, edit in-place, delete
- **FTP accounts** — create / list / delete
- **Subdomains** — create / list / delete
- **Databases** — MySQL users (create / list / delete) + one-click phpMyAdmin
- **Redirects** — domain redirects (301/302)
- **Cron jobs** — scheduled tasks (create / list / delete)
- **Backups** — on-demand account backup + restore
- **SSL** — status + one-click Let's Encrypt issuance
- **PHP** — switch the website's PHP version

Every tab only appears when the API key carries the matching scope, so a plain
`billing_integration` key still shows a clean Overview-only client area.

## Security

The client area is built with a defence-in-depth posture:

- **Account-scoped operations** — every self-service action is verified to belong
  to the WHMCS service's own Panelica account. A customer can never see, restore,
  or delete another account's resource (backups, mail, DNS, files, …), even by
  guessing an object id.
- **Output-safe rendering** — all panel data (file names, email addresses, DNS
  content, …) is HTML/attribute-escaped before display, so hostile content in a
  file name can never inject markup or script into the client area.
- **No secrets in the browser** — the API key/secret never leave the WHMCS
  server; requests are HMAC-SHA256 signed server-side. Module logs mask
  credentials.
- **Session-lock friendly** — the AJAX endpoints release the PHP session write
  lock immediately, so a tab's parallel requests never serialise or hang.

## Two plan modes

**1. Existing plan** — pick a plan already defined on the panel from the live
dropdown. Classic behaviour.

**2. Managed by WHMCS** *(advanced)* — select "— Managed by WHMCS —" in the
plan dropdown and define every resource directly on the WHMCS product:

| Product option | Enforced by |
|---|---|
| Disk quota, monthly bandwidth | panel quota layer |
| **CPU limit (%)** — 100 = 1 core | **cgroups v2 `cpu.max` (kernel)** |
| **Memory limit (MB)** | **cgroups v2 `memory.max` (kernel)** |
| **Max processes** | **cgroups v2 `pids.max` (kernel)** |
| **Disk I/O limit (MB/s)** | cgroups v2 `io.max` (kernel) |
| Max websites / databases / email / FTP | panel quota layer |
| Max Docker containers | panel container layer |
| PHP memory limit | PHP-FPM pool defaults |
| SSH access | jailed SSH toggle |

The module creates and versions a plan on the panel automatically
(`whmcs-p<product>-<hash>`). When you change resource values in WHMCS and run
Change Package, accounts are moved to a fresh plan version — the Panelica
backend rewrites the account's kernel cgroup limits immediately, and
superseded plan versions are garbage-collected once no account uses them.
Managed plans are hidden from the manual plan dropdown.

## Requirements & compatibility

| Requirement | Details |
|---|---|
| **WHMCS** | **8.0 – 9.0.x.** Developed and tested on **WHMCS 9.0.6**. The "Login to Panel" single sign-on uses the WHMCS 8.0+ SSO framework; on older WHMCS the rest of the module works normally and SSO is simply hidden. |
| **PHP** | **7.4+** — the module contains no PHP 8-only syntax. Tested on **PHP 8.3**. |
| **PHP extensions** | `curl`, `json`, `hash` (all standard). **No ionCube** — the module ships as plain, readable PHP. |
| **Client area themes** | Verified on the standard **Six, Twenty-One and Nexus** themes — pure vanilla JS with both Bootstrap 3 and 5 tab markup, so it renders and works across theme generations. |
| **Panelica server** | Any currently supported version for the core lifecycle. The richer client-area tools need a recent external-server build: **SSO** ≥ 1.0.5, **WordPress management / Email Deliverability / accurate disk usage** ≥ 1.0.6, **account-scoped self-service deletes** ≥ 1.0.7. What a client area offers is decided by the API key's scopes, not by the panel's version - the module does not ask what version it is - so on an older panel a feature it lacks is still offered and fails when used. |
| **Panelica license** | The **API Access** feature must be enabled. |

> **Tested matrix (evidence):** WHMCS 9.0.6-release.1 · PHP 8.3.31 — full 40-function
> lifecycle verified end-to-end against a live Panelica server (create → managed plan →
> suspend/unsuspend → password → package change → usage → client-area self-service →
> SSO → terminate).

## Installation

Copy the module into your WHMCS installation:

```
<whmcs-root>/modules/servers/panelica/
├── panelica.php
├── whmcs.json
├── lib/PanelicaAPI.php
└── templates/overview.tpl
```

No activation step is needed — server modules are picked up automatically.

## Step 1 — Create an API key in the Panelica panel

1. Log in to your Panelica panel as an administrator: `https://<your-server>:8443/`
2. Open the **API Keys** page.
3. Click **Create API Key**:
   - **Name:** `WHMCS` (anything you like)
   - **Scopes** — select these seven:
     - `accounts:read`
     - `accounts:write`
     - `accounts:delete`
     - `domains:write`
     - `plans:read`
     - `plans:write` *(managed plan mode)*
     - `bandwidth:read`
   - **IP Whitelist** *(recommended)*: your WHMCS server's IP address.
   - **Rate limit tier**: `professional` recommended for busy client areas.
4. Create. The panel shows the **API Key** (`pk_live_...`) and
   **API Secret** (`sk_live_...`) **only once** — copy both now.

> **Tip:** to unlock the full client-area self-service suite (email, DNS, files,
> WordPress, backups, …) plus one-click SSO, issue a **full-access (`*:*`)**
> key instead. The module hides any tab whose scope the key lacks, so you stay
> in control of exactly what customers can do.

## Step 2 — Add the server in WHMCS

**System Settings → Servers → Add New Server**:

| WHMCS field | Value |
|---|---|
| Module | **Panelica** |
| Hostname | Panel hostname or IP (e.g. `panel.example.com`) |
| Port | `8443` (module default — change only if your panel differs) |
| Username | *(leave empty — not used)* |
| Password | your **API Key** (`pk_live_...`) |
| Access Hash | your **API Secret** (`sk_live_...`) |

Click **Test Connection**. The module validates the credentials **and** the
key's scopes — if a required scope is missing, the error names it exactly.

## Step 3 — Create a product

1. **System Settings → Products/Services → Create a New Product**
2. Module Settings tab → Module Name: **Panelica** → pick your server group.
3. The **Panelica Plan** dropdown loads live from your panel — select the plan
   this product should provision.
4. Save. Orders now provision automatically.

## Known backend limitations (managed mode)

Two Panelica External API behaviours the module cannot work around client-side
(they need a panel-side fix; documented here for transparency):

1. **`inode_quota` is ignored** by `PATCH /v1/plans/{id}` — the module still
   sends it, but the panel does not persist that one column (other int64
   columns like `io_read_bps`, `network_bps_limit` work fine). All other
   ~20 resource fields apply correctly, verified at the kernel level.
2. **PHP `max_execution_time` is set at create time only.** On a package
   change the kernel cgroup limits (CPU/RAM/IO/processes) update live, and the
   PHP-FPM pool is written correctly on the initial account creation, but a
   later change of the PHP execution-time value is not re-applied to the pool
   until the account's next full config regeneration.

Everything else — CPU %, RAM, process, IO, network, containers, PHP memory,
upload/post size, quota mode, WAF, SSH level, all the max_* counts, and the
Advanced Overrides — is applied and kernel-verified on both create and change.

- **Self-signed certificates:** fresh Panelica installs serve `:8443` with a
  self-signed certificate. The module automatically retries with TLS
  verification disabled in that case (requests remain authenticated via HMAC
  signatures). Installing a trusted certificate is still recommended.
- **Clock skew:** signed requests are valid for 5 minutes. `TIMESTAMP_EXPIRED`
  errors mean the WHMCS server clock is off — enable NTP.
- **Debugging:** enable **Utilities → Logs → Module Log** in WHMCS. Every API
  call is logged with credentials masked.
- **License:** if the panel reports API access unavailable, the Panelica
  license on that server does not include the API Access feature.

## Tests

    composer install
    composer test                       # offline suite, talks to nothing

The offline suite covers request signing, the self-signed certificate retry,
error handling, ownership scoping and the usage sync, with WHMCS itself
doubled — no panel and no WHMCS installation needed.

A second suite drives the module's real lifecycle against a panel. Point it at
a **test** server, never one with customers on it: it creates accounts, moves
them between plans and deletes them again.

    PANELICA_TEST_HOST=panel.example.com \
    PANELICA_TEST_KEY=pk_live_... \
    PANELICA_TEST_SECRET=sk_live_... \
    vendor/bin/phpunit --testsuite integration

Optional: `PANELICA_TEST_PORT` (default 8443) and `PANELICA_TEST_PLAN` (a plan
UUID; otherwise the first plan on the panel is used). Every account it creates
carries a `wmt` prefix and is removed at the end, including after a failure.
Without those variables the suite skips itself.
