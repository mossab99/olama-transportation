# Security Remediation Report — olama.online

Date: 2026-08-29 (Asia/Amman)  
Scope: production WordPress site `https://olama.online`, origin `84.247.143.44`.

## 1. Executive Summary

Stage 1 confirmed a site-wide compromise. Stage 2 preserved evidence, created verified rollback backups, quarantined the malicious plugin and MU loader, replaced the malicious service worker, removed database persistence, rotated WordPress salts, revoked privileged sessions, hardened nginx/WordPress, purged Cloudflare, and validated the public site. External credential rotation and licensed third-party plugin validation remain outstanding.

## 2. Initial Compromise

The confirmed chain was `site-helper-1317e86c55e6` (masquerading as Site Health Troubleshooting), `nc-dropin.php`, injected blockchain JavaScript, and `/nochain-sw.js`. The MU loader recorded administrator IPs, injected the contract loader, registered a root-scope service worker, and could plant a Custom HTML widget. The active plugin could restore itself from an uploads ZIP.

## 3. Production Access Used

Authenticated Contabo VPS access, WordPress/WP-CLI, nginx/PHP configuration, cron/systemd, logs, Cloudflare dashboard, and the GitHub deployment repository were verified. Production document root: `/home/olama/htdocs/olama.online`; prefix: `wp_`; PHP-FPM/web owner: `olama`; WordPress 7.1; PHP 8.4.24; nginx 1.30.4; Ubuntu 24.04.4.

## 4. Backup Evidence

Contabo snapshot: `olama-pre-remediation-20260828`, created 2026-08-28 22:36, retained through 2026-09-27. Server evidence root: `/root/olama-stage2-backup-20260828T204123Z`. Verified archives and SHA-256 values:

| Archive | SHA-256 |
|---|---|
| `site-files.tar.gz` | `85265be99706d40ea6855ad06832e5d6907ee78a965cccd7a623af1e5abe4e55` |
| `database.sql.gz` | `cdfc7418201fb306a9dbbfab0e458bb09558dea2a6e3a262db250e03b22f8b0e` |
| `server-configs.tar.gz` | `2000633bba52b01c3a372ae1cec6c8fabc5c9208fa3e2b25482ded3d4c00eeaa` |
| `logs-since-2026-07-07.tar.gz` | `369d4cdc2463eaaafcadd5abf386b7486599c1018c8f384d342267c9b8c7801c` |

## 5. Files Quarantined

Moved, not destroyed, under `/root/olama-stage2-backup-20260828T204123Z/quarantine/live/` after metadata/hash preservation:

- `wp-content/plugins/site-helper-1317e86c55e6/` (main file SHA-256 `f4ce22691e8f178ebb3d6b397ff6554a964dd213fdecf4c2600e29fc83b300d5`)
- `wp-content/mu-plugins/nc-dropin.php` (`e181fab0b20eefa5cf5ff88efc82dda14d482b6ef4359ff98dfa84f38e44bca7`)
- upload restore copies named `cache-fe46eab4.jpg`
- `wp-content/nochain/` if present
- the pre-containment PageSpeed cache tree.

## 6. Files Removed

No confirmed malware evidence was irreversibly deleted. The rogue plugin, loader, restore copies, and stale PageSpeed cache were moved to protected quarantine.

## 7. Files Replaced

| Path | Old SHA-256 | New SHA-256 | Reason |
|---|---|---|---|
| `/nochain-sw.js` | `8dd49a4f0e68e9c9373c4ce52656bf302eba28f0f4d2f5e9ce165fb47ffd745d` | `C5552332196F115A5BFD65A42952CC13779BC5D54D87E5F17EF59034B740B507` | Temporary reviewed unregister/cache-cleanup worker |
| `wp-content/mu-plugins/olama-security-cleanup.php` | new file | `E9D336726877E5FEECCA042121A8D9C68DF6E27B7815E80DB397B291402BBDD5` | Forces cleanup-worker update and clears NoChain browser storage |
| `wp-config.php` | `b390014f290bda3d10c0a08af135b50f83a01f11edcb7ce0d899422c1012ac78` | `b8aa0cd3be75db3eb733063eddb1170fbf65291bfa4361b8fd254b7909ce47c9` | Salts and hardening constants |
| `/etc/nginx/sites-enabled/olama.online.conf` | `4c6713178b1219d7f23cc04b98c8acdcf35375516cebce8d378ee16412bd3e2d` | `b0aa6ef16bfc3466e40884de4a612e9c12c41872cb7e459fdde7ce45c0f4b9aa` | No-cache service-worker route and uploads PHP deny rule |

## 8. Service Worker Remediation

The old root-scope worker was preserved and replaced. The replacement calls `skipWaiting`, deletes Cache Storage, claims clients, unregisters itself, and reloads controlled windows. nginx now serves it with `Cache-Control: no-store`, `Pragma: no-cache`, `Expires: 0`, and `Service-Worker-Allowed: /`. The temporary helper remains through 2026-09-30 for returning visitors.

## 9. Database Changes

Before changes, affected rows were exported to `database-cleanup/affected-options-before.tsv`. Exact changes:

- removed `nochain_admin_ips`, `nochain_plant`, and `nochain_installer_slug` options;
- deactivated the exact `active_plugins` entry for `site-helper-1317e86c55e6`;
- removed the exact rogue key from `recently_activated` and the JSON Simple History plugin-information cache;
- scrubbed only the exact rogue key/string from the WPvivid task inventory, preserving the surrounding task data;
- destroyed all sessions for administrator IDs 1, 4, 10850, 10857, and 10986.

Post-cleanup IOC counts are zero in options, posts, postmeta, and usermeta.

## 10. User/Admin Findings

Current administrators: `mossab99`, `ratwaah`, `emp1`, `emp24`, and `emp113`. The user confirmed the three `emp*` accounts are legitimate staff accounts (`fsa.com`) and they were retained. All privileged sessions were revoked; no administrator was deleted.

## 11. Cron/Persistence Findings

No malicious root cron, system cron, systemd timer, PHP auto-prepend, `.user.ini`, nginx include, or remaining MU loader was found. The confirmed persistence mechanisms were the rogue plugin restore path, `nc-dropin.php`, its control options, and the root service worker.

## 12. WordPress Core Integrity

`wp core verify-checksums` passed: “WordPress installation verifies against checksums.”

## 13. Plugin Integrity

The rogue plugin is quarantined. WordPress.org checksum verification covered 12 of 32 plugins; 19 custom/unavailable-source plugins were skipped and one added-file finding was reported for `user-menus`. Custom `olama-*` plugins were not removed. `Use-your-Drive` 3.9.2 remains a non-IOC item requiring comparison with its licensed release.

## 14. Theme Integrity

Active Kadence 1.5.2 and installed themes were inventoried. No confirmed IOC was found in theme files or public responses. A trusted-source comparison should still be completed during routine maintenance.

## 15. Entry Vector Investigation

ENTRY VECTOR: UNKNOWN. The malicious files share an Aug-07 timestamp and logs show subsequent asset requests, but no preserved log proves the initial upload/activation request. No SSH authentication event or server-level persistence was linked to the initial installation.

## 16. Credentials Rotated

Completed: WordPress authentication salts shuffled; all privileged WordPress sessions destroyed. Not completed in this run: administrator passwords, Contabo password, SSH/SFTP keys, database password, Cloudflare API tokens, GitHub deployment secrets, and third-party integration secrets. These require an owner-coordinated rotation window.

## 17. Cloudflare Purge

Cloudflare “Purge Everything” was confirmed after containment. The previously cached `vendor.min.js` hash `9a85a5aa81305f85e6546452fd2093a8a68932bed3cec4f6491e4d031a90bc95` changed from `HIT` to 404 `MISS`; the cleanup worker is 200 `BYPASS` with no-store headers.

## 18. Hardening Changes

Added `DISALLOW_FILE_EDIT=true` and `FORCE_SSL_ADMIN=true`; added an exact no-cache service-worker nginx route; blocked PHP/PHTML/PHAR execution in uploads; reloaded nginx; retained UFW default-deny inbound and Fail2ban SSH protection. No 777 sensitive files were introduced.

## 19. Post-cleanup Malware Scan

Filesystem, database, origin response, service-worker, and quarantine-aware IOC scans are clean. Confirmed malicious files are outside the web root.

## 20. Sitemap Crawl Results

The sitemap contains 518 URLs. The post-purge validation completed 1,236 requests with 1,236 HTTP 200 responses, zero errors, zero IOC responses, and zero visitor/Googlebot-smartphone material differences. A broader matrix covered 20 representative URLs across Chrome desktop/Android, Safari iPhone, Googlebot desktop/smartphone, and Bingbot, with and without a Google referrer.

## 21. Googlebot/Mobile Tests

Chrome desktop, Chrome Android, Safari iPhone, Googlebot, Googlebot Smartphone, and Bingbot were tested with and without a Google referrer on representative URLs. Critical `/`, `wp-login.php`, `robots.txt`, and sitemap endpoints returned clean responses. The old runtime URL is 404 after purge.

## 22. Remaining Risks

External credential rotation is outstanding. `Use-your-Drive` source validation and a full trusted-source theme comparison remain outstanding. The cleanup worker should remain until 2026-09-30, after which browser-registration checks should be repeated before removal.

## 23. Final Security Status

NOT YET SAFE TO REQUEST GOOGLE REVIEW

The site content and cache are clean, but the runbook requires completed external credential containment and complete third-party integrity review before declaring SAFE.

## 24. Google Search Console Review Recommendation

Do not submit a review yet. After external credential rotation, Use-your-Drive/theme validation, and final monitoring, prepare a manual statement describing the confirmed rogue plugin/MU loader, service-worker replacement, persistence removal, Cloudflare purge, core verification, privileged-user review, credential rotation, hardening, and complete crawl. No review was submitted automatically.

## Evidence References

- Backup workflow run: https://github.com/mossab99/olama-core/actions/runs/33209288598
- Forensic preservation workflow run: https://github.com/mossab99/olama-core/actions/runs/33210054121
- Targeted evidence workflow run: https://github.com/mossab99/olama-core/actions/runs/33210332220
- Containment workflow run: https://github.com/mossab99/olama-core/actions/runs/33210847991
- Post-containment audit run: https://github.com/mossab99/olama-core/actions/runs/33211208436
- Hardening verification run: https://github.com/mossab99/olama-core/actions/runs/33212246804
- Database cleanup run: https://github.com/mossab99/olama-core/actions/runs/33212879791
