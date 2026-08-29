# Security Incident Closure Report — olama.online

Date: 2026-08-29 (Asia/Amman)  
Scope: production WordPress site `https://olama.online` (origin `84.247.143.44`)

## 1. Executive Summary
Stage 1 identified a site-wide compromise involving a rogue plugin, MU loader, injected blockchain code, and a root-scope service worker. Stage 2 preserved evidence, quarantined the confirmed malware, removed persistence, rotated WordPress salts, revoked privileged sessions, hardened nginx/WordPress, purged Cloudflare, and completed a clean public crawl. Stage 3 closure is not yet complete because external credential rotation and trusted-source validation remain owner actions.

## 2. Current Production Status
The public site is serving clean content. Confirmed malicious files are outside the web root in protected quarantine. The temporary cleanup worker remains intentionally deployed through 2026-09-30.

## 3. Stage 1 Summary
Forensic preservation confirmed `site-helper-1317e86c55e6`, `nc-dropin.php`, injected contract `0x58460d0b3d4d6b03761c89120393c0c676676496`, restore artifacts, and `/nochain-sw.js`. The initial entry vector remains UNKNOWN.

## 4. Stage 2 Summary
Verified backup/snapshot, evidence capture, quarantine, database cleanup, session revocation, salt shuffle, nginx/WordPress hardening, Cloudflare purge, and public/browser-bot validation were completed.

## 5. Clean Baseline Verification
Post-containment and post-purge scans found zero known IOC responses and zero residual IOC matches in options, posts, postmeta, and usermeta. The known rogue paths are absent from the live document root.

## 6. Administrator Audit
Retained administrators are `mossab99`, `ratwaah`, `emp1`, `emp24`, and `emp113`. The owner confirmed the three `emp*` accounts are legitimate staff accounts and they were not deleted.

## 7. Administrator Password Rotation
NOT COMPLETE. WordPress salts were shuffled and all privileged sessions were destroyed, but each administrator password still requires owner-coordinated rotation and recovery verification.

## 8. Contabo Access Review
NOT COMPLETE. Contabo account/root credential review and rotation require the owner’s authenticated action and recovery confirmation.

## 9. SSH/SFTP Review
NOT COMPLETE. The only observed root authorized-key fingerprint was `SHA256:DyXO3fe0F/+alkcX2k2chcXYeouDZRR7YwdAQwENkHQ` (label `Contabo-2 (RSA)`). Confirm ownership, remove unknown keys, and rotate/reissue keys where needed.

## 10. Database Credential Status
NOT COMPLETE. The application database password was not rotated. The `olama` database user could not query `mysql.user`; server-level account review therefore remains a closure gap.

## 11. Cloudflare Credential Status
NOT COMPLETE. Cache purge was completed, but Cloudflare API-token/account credential inventory and rotation remain outstanding.

## 12. GitHub Deployment Secrets
NOT COMPLETE. Review and rotate repository deployment secrets, especially `SERVER_IP` and `SSH_PRIVATE_KEY`, after confirming the replacement deployment path.

## 13. Third-party Secrets
NOT COMPLETE. Inventory and rotate SMTP, payment, storage, analytics, Google, and other integration secrets from their respective trusted consoles.

## 14. Use-your-Drive Validation
NOT COMPLETE. Installed `Use-your-Drive` 3.9.2 had no confirmed IOC, but it has not been compared against the owner’s licensed release package.

## 15. Kadence Validation
NOT COMPLETE. Kadence 1.5.2 had no confirmed IOC or clean-response difference, but a trusted-source comparison remains required.

## 16. WordPress Core Integrity
PASS. `wp core verify-checksums` reported that the installation verifies against checksums.

## 17. Plugin Integrity
PASS WITH LIMITATION. The rogue plugin is quarantined. WordPress.org checksums covered 12 of 32 plugins; 19 custom/unavailable-source plugins were skipped and one benign added-file finding was recorded for `user-menus`. No known malware IOC remained.

## 18. Custom OLAMA Integrity
PASS for the reviewed custom OLAMA code and public responses; retain normal source-control and release review.

## 19. Persistence Recheck
PASS. Confirmed malware paths, control options, restore copies, malicious scheduled references, and known persistence indicators were removed or quarantined. No malicious cron/systemd/PHP auto-prepend persistence was found.

## 20. Service Worker Status
PASS WITH TEMPORARY CONTROL. `/nochain-sw.js` is the reviewed cleanup worker, served with no-store headers and public hash `C5552332196F115A5BFD65A42952CC13779BC5D54D87E5F17EF59034B740B507`. Do not remove it before 2026-09-30.

## 21. Cloudflare Cache Status
PASS. “Purge Everything” completed. Previously cached malicious vendor content now returns 404/MISS; the cleanup worker returns 200/BYPASS with no-store headers.

## 22. Sitemap Crawl Results
PASS. Sitemap contained 518 URLs; post-purge validation made 1,236 requests, all HTTP 200, with zero errors and zero IOC responses.

## 23. Browser/Bot Validation
PASS. Chrome desktop/Android, Safari iPhone, Googlebot, Googlebot Smartphone, and Bingbot were tested with and without a Google referrer across representative URLs; no material differences or malware responses were observed.

## 24. Registration Security
PASS. Registration settings were reviewed during remediation; verify `users_can_register` remains disabled and `default_role` is not privileged during the final owner audit.

## 25. Hardening Verification
PASS. `DISALLOW_FILE_EDIT` and `FORCE_SSL_ADMIN` are enabled; uploads deny PHP/PHTML/PHAR execution; UFW default-deny inbound and Fail2ban SSH protection remain active.

## 26. Entry Vector Assessment
UNKNOWN. The shared Aug-07 timestamps and later asset requests do not prove the initial upload or activation request. No preserved authentication event establishes the entry vector.

## 27. Outstanding Risks
External credential rotation, database-account review, licensed Use-your-Drive validation, trusted Kadence comparison, and the post-2026-09-30 cleanup-worker removal check remain outstanding.

## 28. Final Security Decision
**NOT YET SAFE TO REQUEST GOOGLE REVIEW**

This status is required until all external credentials are reviewed/rotated and the trusted-source checks are complete.

## 29. Google Search Console Review Readiness
Do not submit a Google review yet. After the outstanding closure items pass, perform one final persistence check and crawl, then submit a manual review statement. No review was submitted automatically.

## Evidence
- [Backup](https://github.com/mossab99/olama-core/actions/runs/33209288598)
- [Forensics](https://github.com/mossab99/olama-core/actions/runs/33210054121)
- [Targeted evidence](https://github.com/mossab99/olama-core/actions/runs/33210332220)
- [Containment](https://github.com/mossab99/olama-core/actions/runs/33210847991)
- [Post-audit](https://github.com/mossab99/olama-core/actions/runs/33211208436)
- [Hardening](https://github.com/mossab99/olama-core/actions/runs/33212246804)
- [Database cleanup](https://github.com/mossab99/olama-core/actions/runs/33212879791)
