# Production PWA diagnostics

Safari's `FetchEvent.respondWith received an error: TypeError: Load failed` means a
service worker controlled the navigation and failed to provide a valid response. A
stale browser cache is one possible cause, but server timeouts, TLS/DNS failures,
redirect problems, or an exception in the worker can produce the same message.

## Server report

Run by SSH from the WordPress installation:

```bash
php wp-content/plugins/olama-transportation/tools/pwa-server-diagnostics.php \
  --url=https://olama.online/ \
  --output=/tmp/olama-pwa-report.json
```

If the plugin is outside the detected WordPress tree, add
`--wp-root=/absolute/path/to/wordpress`.

The script is CLI-only and read-only. It reports active plugins, cache drop-ins,
PWA-related options and rewrites, discovered worker/manifest URLs, response status
and cache headers, TLS/HTTP failures, and source files containing worker code. It
does not print option values or secrets.

## Affected Safari device

Connect Safari Web Inspector to the affected iPhone, open the Console for
`olama.online`, and paste the complete contents of
`tools/pwa-browser-diagnostics.js`. Copy the JSON result. The script enumerates
the controlling worker, its scope and script URL, Cache Storage names, and
no-store fetch tests. It does not unregister the worker or clear data.

Do not clear site data until both reports are captured; clearing it destroys the
best evidence for distinguishing a stale worker/cache from an origin failure.
