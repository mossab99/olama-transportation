# SECURITY AUDIT — olama.online

**Stage:** 1 — forensic audit only  
**Audit date:** 2026-08-28 (Asia/Amman)  
**Production site:** `https://olama.online`  
**Origin tested:** `84.247.143.44` with TLS SNI/Host `olama.online`  
**Disposition:** Active compromise confirmed; no remediation performed

> **ROOT CAUSE STATUS: ROOT CAUSE CONFIRMED**
>
> Google’s “Deceptive pages” finding is explained by a live rogue WordPress loader named `site-helper-1317e86c55e6`. It is present in the generated HTML of every one of the 518 public URLs discovered through the WordPress sitemaps. The loader reads attacker-controlled code from Base smart contract `0x58460d0b3d4d6b03761c89120393c0c676676496` and can place a full-screen fake-verification page over the legitimate school site. The live `/nochain-sw.js` file has SHA256 `8dd49a4f0e68e9c9373c4ce52656bf302eba28f0f4d2f5e9ce165fb47ffd745d`, an exact match for the IOC published by Netskope Threat Labs for the active fake-reCAPTCHA/ClickFix/Amatera campaign.

## Scope and evidentiary limits

Stage 1 was kept read-only. No WordPress, database, user, plugin, theme, DNS, Cloudflare, SSL, firewall, permission, or server configuration was changed. The only created artifact is this report.

The following evidence was available:

- Live public HTTP through Cloudflare.
- Direct-origin HTTP using `curl --resolve olama.online:443:84.247.143.44`.
- The local WordPress development copy at `C:\Users\Mossab\Local Sites\olama3\app\public`.
- Local development logs and partial application-data backups.
- Official WordPress checksum data and current threat-intelligence reporting.

The production origin did not have a trusted SSH host key or usable production SSH credential in the workspace. A key for the separate `bus.olama.online` service was discovered but was not used beyond a minimal access check because that service is out of scope. Production filesystem, database, administrator, cron, ownership, and log evidence therefore remains unavailable. This prevents identifying the initial intrusion vector or every persistence artifact, but it does not weaken the confirmed explanation for Google’s finding: the malicious loader and service worker are live and directly observable.

## 1. Executive Summary

`olama.online` is actively compromised and is **not safe to submit for Google review**.

The production site serves three confirmed malicious/supporting assets:

1. A rogue `site-helper-1317e86c55e6` WordPress component injected into all 518 sitemap URLs.
2. `runtime-sample.js`, which contacts a Base blockchain smart contract, executes attacker-supplied JavaScript with `new Function`, and can cover the site with a full-screen iframe on Windows and macOS once per day.
3. `/nochain-sw.js`, a malicious Service Worker that can persist in visitors’ browsers, strip Content Security Policy headers, avoid administrators/logged-in users, and inject the same blockchain-delivered payload.

The observed contract, filenames, behavior, and Service Worker hash exactly match the mass WordPress compromise documented by [Netskope Threat Labs](https://www.netskope.com/jp/blog/etherhiding-in-the-browser-clickfix-chain-ends-in-amatera). That campaign displays a fake Google reCAPTCHA/verification overlay and instructs Windows users to run an `mshta` command, ultimately delivering the Amatera credential stealer.

No evidence implicates the custom OLAMA plugins. The available local copy contains none of the campaign IOCs, its present WordPress core files match official checksums, and its limited high-risk PHP-function hits have legitimate application purposes.

## 2. Google Search Console Finding

- Reported category: **Deceptive pages**.
- Sample URLs: none supplied by Google.
- Forensic explanation: the malicious loader is injected site-wide, so a single sample URL is unnecessary; all 518 sitemap URLs contained the loader marker during this audit.
- Google can detect the malicious static loader and/or execute the JavaScript to observe the fake verification overlay.
- The fake verification content is mutable because it is fetched from a blockchain contract. The visible malicious page can therefore change without any file on the WordPress server changing.

## 3. Environment

### Production, confirmed remotely

| Item | Finding |
|---|---|
| Public proxy | Cloudflare (`104.21.93.107`, `172.67.208.215` observed) |
| Origin | `84.247.143.44` |
| Origin web server | nginx |
| Origin optimization | `X-Page-Speed: 1` / mod_pagespeed behavior |
| WordPress version | 7.1, confirmed by RSS generator and core asset versions |
| WordPress home/site URL | `https://olama.online` |
| Active theme | Kadence; asset version 1.5.2 observed |
| Production OS/hostname | UNKNOWN — server access unavailable |
| Production PHP version | UNKNOWN |
| Production database/version | UNKNOWN |
| Production document root | UNKNOWN |
| Production table prefix | UNKNOWN |
| Production filesystem owner | UNKNOWN |
| Production PHP configuration | UNKNOWN |
| Production WP/system cron configuration | UNKNOWN |

### Local development copy

| Item | Finding |
|---|---|
| OS | Microsoft Windows 10 Pro 10.0.19045 |
| Hostname | `M-ALHUNIATI345` |
| Local server stack | Apache 2.4.43, PHP 8.4.10, MySQL 8.0.35 |
| CLI PHP | 8.4.12 |
| WordPress | 7.1 |
| Document root | `C:\Users\Mossab\Local Sites\olama3\app\public` |
| Local URL | `http://olama3.local` |
| Table prefix | `wp_` |
| Environment | `WP_ENVIRONMENT_TYPE=local`, `WP_DEBUG=true`, logging enabled, display disabled |
| Owner | `M-ALHUNIATI345\Mossab` |
| WP-CLI | Not installed/available |
| Local DB state | Site service stopped; it was not started to avoid unintended database/log changes |

## 4. Backup Status

### Production backup status

- Filesystem backup available: **UNKNOWN**
- Database backup available: **UNKNOWN**
- Backup location/date: **UNKNOWN**

This is a critical Stage 2 precondition. No destructive action should occur until both a full production filesystem snapshot and a consistent database dump are created and independently verified.

### Local backup evidence

- `C:\Users\Mossab\Local Sites\olama3\olama_backups` contains seven daily JSON files through 2026-08-25.
- Those JSON files are partial OLAMA application-table/option exports, not full WordPress database backups.
- `C:\Users\Mossab\Local Sites\olama3\appPublic` is an old partial `wp-content` copy dated 2026-02-11, not a validated full filesystem restore point.
- The latest partial JSON contained no campaign IOCs, `<script>`, `<iframe>`, `javascript:`, or redirect markers searched during this audit.
- A sensitive application deletion credential is stored in plaintext inside the local backup’s serialized settings. Its value is intentionally omitted from this report. This is a separate hardening issue.

**BACKUP STATUS: no validated complete production backup was identifiable.**

## 5. Root Cause Status

**ROOT CAUSE CONFIRMED**

This status applies to the cause of Google’s “Deceptive pages” classification. The original access vector used to plant the rogue plugin remains unknown pending production logs and filesystem/database timelines.

## 6. Root Cause Evidence

1. Every one of 518 unique URLs discovered through the four WordPress sitemap files returned HTTP 200 and contained `site-helper-1317e86c55e6` and/or `wpPerfSamplefe`.
2. Public page HTML enqueues:
   - `/wp-content/plugins/site-helper-1317e86c55e6/assets/vendor.min.js?ver=1.2.0`
   - `/wp-content/plugins/site-helper-1317e86c55e6/assets/runtime-sample.js?ver=1.2.0`
3. The inline configuration points at contract `0x58460d0b3d4d6b03761c89120393c0c676676496` on Base chain 8453.
4. `runtime-sample.js` contacts multiple Base RPC endpoints, reads strings from the contract, and executes them through `new Function(...)`.
5. When a contract “demo page” is present, the script creates a fixed, viewport-sized, maximum-z-index host, attaches a closed Shadow DOM, and loads attacker content into an iframe using `src` or `srcdoc`.
6. Its static targeting selects Windows and macOS, excludes `/wp-admin` and `/wp-login.php`, and rate-limits display with local storage. The inline activation timestamp is `1786076772` = **2026-08-07 04:26:12 UTC**.
7. `/nochain-sw.js` is live through Cloudflare and the origin. Its SHA256 is an exact published campaign IOC.
8. The Service Worker contains the same contract address, skips `wordpress_logged_in_` cookies, admin/login paths, and `nc_skip=1`, strips CSP headers, and persists via Service Worker APIs.
9. Netskope Threat Labs describes this exact `site-helper-<hex>` / `runtime-sample.js` / `nochain-sw.js` / Base-contract chain as a fake-reCAPTCHA ClickFix campaign delivering Amatera. A second report independently describes the same indicators and behavior ([GBHackers summary](https://gbhackers.com/wordpress-sites-hijacked/)).

## 7. Core Integrity Findings

### Local copy

- WordPress 7.1 `en_US` was checked against the [official WordPress checksum API](https://api.wordpress.org/core/checksums/1.0/?version=7.1&locale=en_US).
- Official manifest entries: 3,782.
- Expected bundled files absent locally: 441, consisting of removed Akismet/Hello Dolly and default themes. This is a legitimate installation choice, not a checksum failure.
- Existing official files checked: 3,341.
- Checksum mismatches: **0**.
- Classification: **CLEAN** for all present local core files.

### Production

Production core checksums could not be verified without filesystem/WP-CLI access. Publicly exposing `/readme.html` is unnecessary information disclosure and should be addressed during hardening, but is not the malware source.

## 8. Malicious Files

| Production logical path/URL | Size | SHA256 | Classification | Evidence |
|---|---:|---|---|---|
| `[DOCROOT]/nochain-sw.js` | 6,374 bytes | `8dd49a4f0e68e9c9373c4ce52656bf302eba28f0f4d2f5e9ce165fb47ffd745d` | **MALICIOUS** | Exact Netskope IOC; malicious Service Worker injection/persistence |
| `[DOCROOT]/wp-content/plugins/site-helper-1317e86c55e6/assets/runtime-sample.js` | 4,686 bytes | `2c651b9394c3876e06c8261b0ef2887c235743717930122ef0ed3ae1d29c3fc4` | **MALICIOUS** | Executes mutable contract payload; full-screen iframe overlay |
| `[DOCROOT]/wp-content/plugins/site-helper-1317e86c55e6/assets/vendor.min.js` | 522,095 bytes observed | `9a85a5aa81305f85e6546452fd2093a8a68932bed3cec4f6491e4d031a90bc95` | **MALICIOUS SUPPORTING COMPONENT** | Ethers 6.16 library bundled solely for the rogue loader; library code is not inherently malicious |
| Rogue PHP/MU loader that enqueues the above | UNKNOWN | UNKNOWN | **MALICIOUS** | Must exist on production; likely a `site-helper-<hex>` or `nc-dropin.php` campaign artifact, but exact path/hash require server access |

The runtime asset reports `Last-Modified: Fri, 07 Aug 2026 04:26:06 GMT`. `/nochain-sw.js` is served with a ten-year `max-age` and was a Cloudflare cache HIT during the audit, making cache purge essential during remediation.

## 9. Suspicious Files

No local file matched the campaign IOCs.

The local web root contains multiple unauthenticated development utility scripts. They appear to be legitimate OLAMA development/debug code, not malware, but several can disclose settings or modify data if deployed to production. Notable examples:

| Local file | SHA256 | Classification | Concern |
|---|---|---|---|
| `fix_target_type_column.php` | `0A5CC0A12676065D5FC7B95F071CD57F7687D28A5EF880F5C7302F5B0482C7B0` | Legitimate development utility; high risk if deployed | Runs ALTER/UPDATE without authentication |
| `scratch.php` | `197B815AFE31B3141D6A05A7440F16687ACCCC311480FFC8594AF725BCB5F1BB` | Legitimate development utility; high risk if deployed | Deletes/inserts mock database rows |
| `tmp_test_insert.php` | `321DE7FB0B87AF9C857EF8480544B5826A4A8A2C29EAB103472D9C97E9D0A249` | Legitimate development utility; high risk if deployed | Inserts database data |
| `tmp_test_loop.php` | `B2F27E46E4C7C122FFFDBDA719A37E2A16ECEDF84CC249948763D5A85204F7AF` | Legitimate development utility; high risk if deployed | Inserts test records |
| `create_prepared_campaign.php` | `9C63ACC8F588E988580A205063CF754D2D18F5056BD1C7FA9119D76A29A9C650` | Legitimate development utility; high risk if deployed | Creates messaging campaign data |
| `debug-settings.php` | `96325C39AAA5905061508B69F9AF0C658743091A79E6EA77F68D200288FE4EFB` | Legitimate debug utility; sensitive | Prints integration settings |
| `test_opts2.php` | `8FFE139035316511ADA78250C4D01E399C9CBD81765C245F1E198E84BBA86C43` | Legitimate debug utility; sensitive | Prints school settings |
| `local-xdebuginfo.php` | `C123196F91C96947B15752373D0C442CA20352629CDBB59AA2CA042A2DE8006A` | Legitimate local utility; sensitive | Exposes Xdebug/environment details |

Production presence was not tested because even an HTTP HEAD request can execute PHP and trigger these scripts. Stage 2 must compare the production web root against an approved deployment manifest before acting.

## 10. Uploads Audit

Local executable files under uploads:

| Path | Size | SHA256 | Purpose/classification |
|---|---:|---|---|
| `wp-content/uploads/olama-billing-reset-backups/index.php` | 37 | `173FA45CBE78F410D31ACB40BCAFDC0971996768D82EB2A108662FFCE62E08D4` | CLEAN; returns HTTP 404 and exits |
| `wp-content/uploads/olama-logs/index.php` | 0 | `E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855` | CLEAN; empty directory index |

Both directories also contain deny rules. Production uploads remain unaudited due lack of server access.

## 11. JavaScript Findings

### Confirmed malicious

- `runtime-sample.js` uses `new Function` to execute smart-contract strings.
- It supports encoded `nc-blob:` and obfuscated `ob:` content, Base64 decoding, decompression, and full-screen iframe injection.
- It targets Windows/macOS and uses a daily local-storage key to reduce repeat visibility.
- `nochain-sw.js` can inject contract-resolved code into HTML responses and remove CSP response headers.
- The generated WordPress HTML carries `wpPerfSamplefe` with the malicious contract address and activation timestamp.

### Local code scan

- No `eval`, `gzinflate`, `gzuncompress`, `str_rot13`, `shell_exec`, `passthru`, `system`, `exec`, `popen`, or long encoded PHP payloads were found outside core/vendor exclusions.
- Two `base64_decode` uses are legitimate authenticated decryption/secret storage code in OLAMA components.
- `proc_open` uses are limited to the OLAMA PDF tools QPDF runner/locator and build escaped command arrays; classified **LEGITIMATE MODIFICATION/FEATURE**.
- The only long Base64 string is an embedded Kadence PNG icon; classified **CLEAN**.

## 12. Database Findings

- Production database: **not accessible**, so `options`, `posts`, `postmeta`, `users`, and `usermeta` were not queried.
- Production table prefix: **UNKNOWN**.
- Local DB service was stopped and was not started because startup/recovery can change database/log files.
- The latest partial OLAMA JSON export contained no known campaign IOC or HTML/JavaScript injection marker.
- The partial export is not a substitute for a read-only production SQL audit.
- A plaintext application deletion credential exists inside serialized local backup settings; remediation should migrate this to a password hash or protected secret and rotate it after incident containment.

Required Stage 2 pre-remediation SELECT checks:

- `siteurl`, `home`, `active_plugins`, theme options, cron, widgets, sidebars, autoloaded options.
- Search options/posts/postmeta/usermeta for all campaign IOCs, `site-helper`, `nochain`, Base RPC domains, `<script>`, `<iframe>`, `new Function`, `atob`, and unknown URLs.
- Determine whether the rogue loader is in `active_plugins`, an MU-plugin file, an `auto_prepend_file`, or another persistence path.

## 13. Redirect Findings

- `/sitemap.xml` legitimately redirects once to `/wp-sitemap.xml`.
- No unauthorized server-side external redirect was observed across desktop, mobile, bot, referer, public, or origin tests.
- The local `.htaccess` contains only standard WordPress rewrite rules. No UA, referer, IP, query-string, cookie, or bot redirect logic was found.
- The malicious behavior is a client-side full-screen overlay/iframe and ClickFix lure, not a conventional HTTP 30x redirect.

## 14. Cloaking Findings

The campaign implements deliberate client-side cloaking:

- Skips `/wp-admin` and `/wp-login.php`.
- The Service Worker skips logged-in WordPress cookies and `nc_skip=1`.
- Targets Windows/macOS and applies a daily frequency limit.
- Uses a closed Shadow DOM and maximum z-index to cover legitimate content.
- Stores the visible payload off-server in a mutable smart contract.

Origin HTML sizes varied by approximately 4 KB depending on UA/referer. Exact diffing showed this specific variation was nginx PageSpeed image optimization and mobile handling, not the malicious payload. The malicious loader markers remained present. Public Cloudflare responses were stable across referer tests.

## 15. External Domains

### Malicious/IOC domains and infrastructure

These are deliberately defanged:

| Indicator | Classification | Purpose |
|---|---|---|
| Base contract `0x58460...6496` | MALICIOUS | Mutable payload/script registry |
| `mainnet.base[.]org`, `base.publicnode[.]com`, `base.drpc[.]org`, `1rpc[.]io` | Abused legitimate RPC infrastructure | Contract retrieval |
| `ultraspeed[.]pro` | MALICIOUS | Encrypted fingerprint/telemetry beacon reported for campaign |
| `timelevel12[.]com` | MALICIOUS | ClickFix `mshta` second stage reported for campaign |
| `gpuh.gravityzone[.]army` | MALICIOUS | Emmenhtal loader delivery |
| `i.ibb[.]co/3ytBLkY6/init-block.jpg` | Abused legitimate CDN | Steganographic payload image |
| `gw.proxyvector[.]cc` | MALICIOUS | Amatera C2 |

### Legitimate domains observed in static public HTML

- `static.cloudflareinsights.com` — Cloudflare analytics/performance.
- `fonts.googleapis.com`, `fonts.gstatic.com` — Google Fonts.
- `api.w.org`, `s.w.org`, `schema.org`, `www.w3.org` — WordPress/schema references.
- `get.adobe.com`, `www.enable-javascript.com` — viewer/help links.
- Single-page resources from Tailwind CDN, unpkg, and Unsplash were observed; these should be mapped to their owning page during the production database audit but were not associated with the malware chain.

## 16. Administrator Audit

Production administrator accounts could not be enumerated without database or authenticated WP-CLI access. No account is cleared or accused by this report.

Stage 2 must list every administrator with ID, login, email, registration time, capabilities, session tokens, last-login/security metadata, and role-change history before any removal. Unknown accounts should be disabled only after ownership verification and explicit approval.

## 17. Plugin Audit

### Production, publicly observed

| Component | Version evidence | Status/classification |
|---|---|---|
| `site-helper-1317e86c55e6` | 1.2.0 asset version | **MALICIOUS; active site-wide** |
| Use-your-Drive | 3.9.2 asset version | Business plugin; not implicated by current evidence; validate license/source and integrity |
| Kadence integration/theme assets | 1.5.2 | Legitimate |

The full production active/inactive/MU inventory is unknown. The malicious component may use an MU drop-in to enqueue files from the normal plugins directory; Netskope reports `site-helper-<hex>` and `nc-dropin.php` as campaign artifacts.

### Local installed plugins

Custom OLAMA plugins were treated as legitimate internal software. Installed local folders/versions:

- Members 3.2.22; User Switching 1.12.0; WP User Switch 1.1.3.
- `olama-core` 1.0.0; `olama-dashboard` 1.0.3; `olama-employees` 1.1.0; `olama-exam-engine` 1.2.1; `olama-exam-management` 1.1.0; `olama-invoice` 2.2.0; `olama-kg` 1.1.0; `olama-media-library` 2.0.4; `olama-messages` 2.5.1; `olama-oracle-sync` 0.7.0; `olama-pdf-tools` 2.2.2; `olama-performance-monitor` 1.0.0; `olama-school` 2.9.4; `olama-stores` 1.5.4; `olama-student-evaluation` 1.2.0; `olama-student-gateway` 0.1.0; `olama-supervision` 1.1.0; `olama-transportation` 2.9.4; `olama-users` 0.6.3.
- Official checksums for Members, User Switching, and WP User Switch: **0 mismatches**.
- Local active/inactive state and local MU-plugin inventory could not be obtained from the stopped DB. No local MU-plugin files were listed.
- `wp-content/Olama - NEW` is a non-executing internal source/staging tree and showed no campaign IOC.

## 18. Theme Audit

- Production body classes and assets identify Kadence 1.5.2 as active.
- Local installation contains only Kadence 1.5.0.
- Local theme scans found no obfuscated PHP, hidden iframe, malicious redirect, campaign IOC, or encoded payload beyond a legitimate embedded image.
- Production theme filesystem integrity and inactive themes are unknown.

## 19. Cron Audit

- Production WordPress cron and system cron: **UNKNOWN**.
- No server-side cron persistence was observed through HTTP.
- The campaign’s reported `serviceerg` scheduled task is created on a Windows visitor endpoint only after the victim executes the ClickFix command; it is not evidence of a server cron job.
- Stage 2 must inspect WP cron, Action Scheduler, user/root crontabs, `/etc/cron*`, systemd timers, and PHP auto-prepend mechanisms before removal.

## 20. Permissions Audit

- Production Unix ownership/modes: **UNKNOWN**.
- Local Windows ACL owner is `M-ALHUNIATI345\Mossab`; the available sandbox group has read/execute only.
- Unix `777`, world-writable PHP, and web-server-user ownership checks require origin access.

## 21. Log Findings

- Production nginx, PHP, authentication, Cloudflare, and security logs were unavailable.
- Local Apache/PHP logs contain no campaign IOC. They show only loopback development traffic, many OLAMA admin-ajax requests/timeouts, and PHP Imagick startup warnings.
- The local logs cannot establish how production was compromised.
- The compromise timestamp is at or before 2026-08-07 04:26 UTC based on the malicious runtime modification/activation evidence; production logs should be preserved from at least 30 days before that time through the present.

## 22. Googlebot vs Visitor Comparison

Test matrix included Chrome desktop, Chrome Android, Safari iPhone, Googlebot, Googlebot Smartphone, and Bingbot, with and without `Referer: https://www.google.com/`, through both Cloudflare and direct origin.

- HTTP status: 200 for tested public pages, login, robots, and sitemap.
- Redirects: none except the legitimate sitemap alias.
- Titles/canonicals/static external domains: materially consistent.
- Public response sizes: stable apart from one-byte mobile differences.
- Origin response differences: attributable to PageSpeed and mobile image optimization.
- Static malicious loader: present across all 518 sitemap URLs.
- JavaScript runtime behavior: selectively targets visitors and hides from admin/login contexts. This is the material cloaking behavior even though the initial HTML remains similar.

## 23. Severity Assessment

**CRITICAL — active credential-stealing malware delivery and deceptive-page compromise.**

Potential impact:

- Visitors can be shown a counterfeit verification prompt on the school’s trusted domain.
- Windows users who follow the prompt may execute malware that steals browser/system credentials.
- A malicious Service Worker may persist in browsers after server files are removed.
- The smart-contract payload is attacker-mutable.
- The server’s initial compromise mechanism and additional persistence remain unknown.
- School administrator, staff, family, and student credentials may have been exposed if users followed the lure; server credentials may also be compromised depending on the intrusion vector.

## 24. Proposed Remediation

No item below has been executed.

1. Place the site into a controlled incident-response window and create verified full filesystem and database backups.
2. Preserve forensic copies, metadata, owners/modes, and hashes of every malicious/suspicious file before quarantine.
3. Locate the exact PHP/MU loader, `nc-dropin.php`, `site-helper-<hex>`, `auto_prepend_file`, `.user.ini`, and other persistence entries.
4. Quarantine/remove the rogue loader directory and all associated PHP/MU files.
5. Neutralize `/nochain-sw.js` using a short-lived trusted unregister/cache-delete Service Worker at the same URL, purge Cloudflare, then remove it after an appropriate update window.
6. Purge Cloudflare cache for all malicious asset URLs and HTML.
7. Remove a rogue `active_plugins` entry only if confirmed in the database; inspect cron/options/posts for IOC persistence.
8. Verify WordPress core and every repository plugin against trusted checksums; compare custom OLAMA code to the approved local/deployment repository.
9. Audit all admins, session tokens, SSH keys, SFTP/control-panel accounts, API tokens, database users, and deployment credentials.
10. Rotate WordPress salts, administrator/staff passwords, hosting/SSH/SFTP/database/Cloudflare credentials, and sensitive API keys after containment.
11. Patch WordPress, trusted plugins, themes, PHP, nginx, and the control panel after preserving evidence and verifying compatibility.
12. Investigate the entry vector using logs around and before 2026-08-07.
13. Notify users who may have seen the fake verification prompt; provide endpoint cleanup guidance for anyone who ran the command.
14. Re-run the complete validation matrix and only then prepare a Google review request.

## 25. Files Proposed for Deletion

| Path | Current SHA256 | Classification | Evidence | Proposed action | Risk of action |
|---|---|---|---|---|---|
| `[PROD_DOCROOT]/wp-content/plugins/site-helper-1317e86c55e6/` | Per-file hashes required; two known below | MALICIOUS | Site-wide injector matching campaign | Hash/manifest, quarantine entire directory, then delete after validation | Removing only directory may leave MU/drop-in persistence; no legitimate dependency expected |
| `.../assets/runtime-sample.js` | `2c651b9394c3876e06c8261b0ef2887c235743717930122ef0ed3ae1d29c3fc4` | MALICIOUS | Contract loader/full-screen overlay | Quarantine/delete with rogue component | Cloudflare/browser cache can continue serving it until purged |
| `.../assets/vendor.min.js` | `9a85a5aa81305f85e6546452fd2093a8a68932bed3cec4f6491e4d031a90bc95` | Malicious supporting component | Bundled for contract loader | Quarantine/delete with rogue component | Ethers itself is legitimate; ensure no legitimate code incorrectly references this rogue copy |
| Exact PHP/MU loader, likely `site-helper-<hex>` or `nc-dropin.php` | **UNKNOWN — mandatory pre-removal hash** | MALICIOUS when correlated | Enqueues known malicious assets | Locate, hash, quarantine, then delete | Wrong-path deletion could affect legitimate MU code; require content evidence |
| `[PROD_DOCROOT]/nochain-sw.js` after neutralization period | `8dd49a4f0e68e9c9373c4ce52656bf302eba28f0f4d2f5e9ce165fb47ffd745d` | MALICIOUS | Exact published IOC | Replace temporarily with kill worker, purge cache, later delete | Immediate deletion alone can leave already-registered workers controlling clients |

Conditional only if found on production: remove the unauthenticated development/debug utilities listed in section 9 from the public document root after hashing and confirming they are not operational dependencies.

## 26. Files Proposed for Replacement

| Path | Current SHA256 | Classification | Evidence | Proposed action | Risk of action |
|---|---|---|---|---|---|
| `[PROD_DOCROOT]/nochain-sw.js` | `8dd49a4f0e68e9c9373c4ce52656bf302eba28f0f4d2f5e9ce165fb47ffd745d` | MALICIOUS | Persistent Service Worker | Temporarily replace with minimal reviewed script that unregisters itself and deletes malicious caches; record new hash; purge Cloudflare | Requires careful testing and sufficient serving window; premature removal strands infected clients |

No WordPress core file replacement is currently proposed because production checksums have not been run and the local core had zero mismatches. Replace production core files only if official verification identifies mismatches.

## 27. Database Records Proposed for Modification

No unconditional database modification is approved or yet justified.

| Table | Row identifier | Field | Suspicious value summary | Reason | Conditional proposed action |
|---|---|---|---|---|---|
| `[prefix]_options` | `option_name=active_plugins` | `option_value` | Possible entry referencing `site-helper-1317e86c55e6` | Rogue component is active | If present, snapshot/hash serialized value and remove only the exact malicious entry |
| `[prefix]_options/posts/postmeta/usermeta` | Any IOC match | Matching field | Contract address, loader names, campaign domains/scripts | Potential secondary persistence | Modify only confirmed malicious rows after export and explicit approval |

## 28. Users Proposed for Removal or Role Change

None yet. Production administrators were not accessible. Any future recommendation must include username, role, email/domain, creation date, capability evidence, and ownership confirmation.

## 29. Cron Jobs Proposed for Removal

None yet. No server cron was identified. Remove only events tied by callback/file evidence to the rogue loader after production cron inspection.

## 30. Plugins Proposed for Update/Removal

- **Remove/quarantine:** `site-helper-1317e86c55e6` and its loader/drop-in — confirmed malicious.
- **Validate and update from licensed source if needed:** Use-your-Drive 3.9.2. It is not implicated by current evidence, but its source/integrity and exposure should be reviewed as part of entry-vector analysis.
- **Retain:** custom OLAMA plugins unless server-side evidence shows a specific compromised file.
- **Update after evidence preservation/compatibility testing:** WordPress, Kadence, and trusted third-party plugins to current supported releases.
- **Review necessity:** having both User Switching and WP User Switch locally may be redundant; this is a hardening consideration, not a compromise finding.

## 31. Hardening Recommendations

- Set `DISALLOW_FILE_EDIT` after confirming deployment workflow.
- Prevent PHP execution in uploads and other data-only directories at nginx/PHP-FPM level.
- Remove public debug/test/repair scripts from production and enforce an approved deployment manifest.
- Make WordPress core/plugin PHP read-only to the web-server user where operationally feasible; isolate writable upload/cache paths.
- Restrict SSH/SFTP/control-panel access, require MFA, and review authorized keys.
- Add file-integrity monitoring for document root, plugins, MU plugins, `.user.ini`, and web-server configs.
- Deploy server-side malware/IOC monitoring that includes Service Worker files and blockchain RPC indicators.
- Add a carefully tested Content Security Policy after cleanup. Note that the malicious Service Worker can strip CSP for already-controlled clients, so Service Worker eviction remains required.
- Disable or restrict XML-RPC if not needed; rate-limit login and admin endpoints.
- Do not expose `readme.html` or environment/debug pages.
- Store application deletion credentials as strong password hashes or protected secrets, never plaintext serialized options/backups.
- Encrypt backups, restrict access, and regularly test full restores.
- Maintain centralized origin and Cloudflare logs with retention spanning incident-response needs.

## 32. Remaining Unknowns

- Initial intrusion vector and attacker identity.
- Exact production document root, OS, hostname, PHP/database versions, owner/modes.
- Exact PHP/MU dropper filename and all files in the malicious directory.
- Whether `front-probe.js`, `nc-dropin.php`, alternate Service Worker names, `.user.ini`, or `auto_prepend_file` persistence exists.
- Production core/plugin/theme integrity beyond publicly observable assets.
- Complete active/inactive/MU plugin list.
- Production database injections, admin accounts, sessions, cron, systemd timers, SSH keys, and system crons.
- Production access/error/authentication/Cloudflare log timeline.
- Whether any visitors executed the fake verification command.
- Whether any visitor browsers still retain the malicious Service Worker/cache.
- Whether server, WordPress, Cloudflare, staff, or third-party integration credentials were stolen.
- Whether valid complete production backups predate the compromise.

## 33. Google Review Readiness

**NOT YET SAFE TO REQUEST GOOGLE REVIEW**

The live site still serves the rogue loader on all 518 sitemap URLs and serves the confirmed malicious Service Worker. A review request now would likely fail and would not protect visitors.

Before review readiness can change:

- Remove every server-side malicious loader/persistence artifact.
- Neutralize existing Service Worker registrations and purge Cloudflare caches.
- Complete production filesystem, database, admin, cron, permission, and log audits.
- Establish and close the likely intrusion path.
- Rotate affected credentials.
- Re-run core/plugin checksums, malware/upload/database/redirect/cloaking/mobile/bot/admin/cron/external-domain tests.
- Verify over time that public and direct-origin HTML contain none of the IOCs and that `/nochain-sw.js` no longer returns malicious content.

---

## Stage 1 Stop Point

**FORENSIC AUDIT COMPLETE.**  
**AWAITING APPROVAL FOR STAGE 2 REMEDIATION.**

Exact changes requiring approval are:

1. Create and verify full production filesystem/database backups and preserve forensic copies.
2. Quarantine/remove `site-helper-1317e86c55e6` and its exact PHP/MU loader after hashing.
3. Replace `/nochain-sw.js` temporarily with a reviewed unregister/cache-delete worker, purge Cloudflare, then delete it after the update window.
4. Remove only confirmed IOC-bearing database option/content rows, malicious cron events, and unauthorized users found during authenticated inspection.
5. Rotate salts and all potentially exposed credentials/tokens after containment.
6. Patch trusted WordPress components and apply the hardening measures above.
7. Perform full post-remediation validation before preparing, but not submitting, a Google review statement.
