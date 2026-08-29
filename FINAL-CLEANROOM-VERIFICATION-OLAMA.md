# Final Clean-Room Verification — olama.online

## 1. Executive Summary
Independent public clean-room verification found no active malware indicators, malicious public responses, bot/mobile cloaking difference, or accessible old malware URLs. Existing production evidence records passing core, database, persistence, and hardening checks. Credential rotation and two trusted-source comparisons remain incomplete; therefore the site is not ready for Google review.

## 2. Verification Date
2026-08-29 (Asia/Amman).

## 3. Production Environment
https://olama.online; origin 84.247.143.44; WordPress 7.1; PHP 8.4.x; nginx 1.30.x; Ubuntu 24.04; Cloudflare proxy; document root /home/olama/htdocs/olama.online; table prefix wp_.

## 4. WordPress Core Integrity
PASS in the recorded post-remediation verification: wp core verify-checksums reported “WordPress installation verifies against checksums.”

## 5. Plugin/MU Inventory
The rogue plugin site-helper-1317e86c55e6 and MU loader nc-dropin.php are absent from the live web root. The recorded plugin audit covered 12/32 plugins; 19 custom/unavailable-source plugins were skipped and one benign user-menus added-file finding was recorded.

## 6. Filesystem IOC Scan
Recorded production scans found zero confirmed active matches for campaign indicators, contract address, and linked domains. Quarantine files were excluded from active-web-root classification.

## 7. Uploads Audit
The uploads PHP/PHTML/PHAR execution deny rule is active. No unknown executable payload was reported.

## 8. Database IOC Scan
Recorded post-cleanup queries returned zero IOC matches in options, posts, postmeta, and usermeta. No database rows were modified during this verification.

## 9. Administrator Audit
Privileged users are mossab99, ratwaah, emp1, emp24, and emp113; all are known legitimate accounts per owner confirmation.

## 10. Registration Security
Registration settings were reviewed during remediation; final owner-side confirmation that users_can_register=0 and the default role is non-privileged remains required.

## 11. WordPress Cron
The recorded cron review found no malicious hooks referencing quarantined malware, service worker, restore, or install paths.

## 12. System Persistence
Recorded checks found no malicious root cron, system cron, systemd timer, PHP auto-prepend, .user.ini, nginx include, or remaining MU-loader persistence.

## 13. Service Worker Validation
PASS. /nochain-sw.js returned HTTP 200 with expected SHA-256 C5552332196F115A5BFD65A42952CC13779BC5D54D87E5F17EF59034B740B507, Cache-Control no-store, and Service-Worker-Allowed /. It is the approved temporary cleanup worker and must remain through 2026-09-30.

## 14. Cloudflare Cache Validation
PASS. After Purge Everything, old vendor/runtime URLs returned 404 with cache MISS; the cleanup worker returned 200 with BYPASS/no-store. Homepage contained no campaign IOC.

## 15. Old Malware URL Tests
PASS. Old rogue-plugin runtime and vendor URLs were inaccessible (HTTP 404).

## 16. Public HTML IOC Scan
PASS. Homepage and tested public responses contained zero campaign IOC matches, malicious contract references, or malicious external domains.

## 17. Sitemap Crawl
PASS. Sitemap discovery found 518 URLs. Complete crawl made 1,236 requests: 1,236 HTTP 200, 0 other statuses, 0 errors, 0 IOC responses.

## 18. Browser/Bot Matrix
PASS. Chrome desktop, Chrome Android, Safari iPhone, Googlebot, Googlebot Smartphone, and Bingbot were tested with and without a Google referrer across 20 representative URLs. Material difference count: 0.

## 19. External Domain Inventory
Observed domains were cdn.tailwindcss.com, static.cloudflareinsights.com, and unpkg.com, classified as expected CDN/analytics/library dependencies. Malware-linked domains and Base RPC endpoints were absent.

## 20. Hardening Validation
Recorded verification confirms DISALLOW_FILE_EDIT=true, FORCE_SSL_ADMIN=true, uploads execution blocking, UFW default-deny inbound, Fail2ban SSH protection, and no known 777 sensitive files.

## 21. Log Review
Preserved log review found no matching SSH authentication event or server-level persistence tied to the compromise. Initial entry vector remains UNKNOWN.

## 22. Quarantine Isolation
Forensic artifacts remain outside the public document root under /root/olama-stage2-backup-20260828T204123Z/quarantine/ and were not counted as active production IOCs.

## 23. Final IOC Counts
| Check | Result |
|---|---:|
| Filesystem active IOC matches | 0 |
| Database IOC matches | 0 |
| Public HTML IOC matches | 0 |
| Cron IOC matches | 0 |
| System persistence IOC matches | 0 |
| Old malicious URLs accessible | 0 |
| Unauthorized administrators | 0 |
| Malicious external domains | 0 |
| Unexpected Service Workers | 0 |
| Approved temporary cleanup worker | 1 (/nochain-sw.js) |

## 24. Outstanding Issues
Administrator, Contabo, SSH/SFTP, database, Cloudflare, GitHub, and third-party credentials have not been fully reviewed/rotated. Use-your-Drive licensed-source validation, Kadence trusted-source comparison, and final registration-setting confirmation remain outstanding.

## 25. Final Security Decision
NOT SAFE TO REQUEST GOOGLE REVIEW

This decision is required because outstanding credential and trusted-source closure gates are incomplete.

## 26. Google Review Recommendation
Do not submit a Google Search Console review yet. Complete owner actions, retain the cleanup worker through 2026-09-30, run one final persistence/crawl check, then prepare a manual review statement. No review was submitted automatically.
