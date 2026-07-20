# Olama Transportation 2.1

Standalone school transportation planning for the Olama ecosystem.

## Data ownership

- Oracle is authoritative for student, family, employee, bus, and source-region
  master data.
- Olama Oracle Sync is the only WordPress component allowed to call the
  read-only Oracle Bridge.
- Olama Core stores the canonical local mirror consumed by every Olama domain
  plugin. Transportation never stores bridge credentials or calls Oracle.
- Imported WhatsApp/Excel coordinates are migration input only.
- Olama Transportation owns approved family/shared stops, student-level
  enrollment, major areas, demand, planning capacity, bus allocation, route
  versions, approvals, reports, and optional integration configuration.
- Legacy Oracle bus and sequence values are reference-only.
- Traccar is optional and disabled by default.

## Setup

1. Activate Olama Core, Olama School, Olama Oracle Sync, and Olama Transportation.
2. Configure the Oracle Bridge only in **Olama Oracle Sync → Settings**.
3. Run **Import transportation master to Core**, then use **Refresh from Olama
   Core** on the Transportation Buses screen.
4. Import family locations and reconcile all `needs_review` rows against Core.
5. Create major areas, approved stops, student enrollments, and allocations.
6. Create draft routes. Configure Google or an external webhook only when route
   data is ready.
7. Review optimized drafts and publish them. Publishing archives the previous
   published version; published history is immutable.

Google optimization uses OAuth service-account credentials. Define the
credentials file outside the web root:

```php
define('OLAMA_TRANSPORT_GOOGLE_CREDENTIALS', '/secure/path/service-account.json');
```

The PHP Google Auth package must be available through the Olama installation.

## REST API

Authenticated WordPress REST endpoints use namespace
`/wp-json/olama-transportation/v1`.

CRUD resources:

- `areas`
- `area-mappings`
- `family-stops`
- `stops`
- `enrollments`
- `allocations`
- `devices`
- `routes`

Operations:

- `POST /routes/{id}/optimize`
- `POST /routes/{id}/publish`
- `POST /imports/family-stops`
- `POST /core/refresh-buses`
- `GET /reports/summary?academic_year_id={id}`
- `GET|PUT /settings`

## Import columns

CSV/XLSX headers may use English or Arabic aliases. Recommended:

`family_id, phone, maps_url, latitude, longitude, address, area, notes`

Rows never become approved automatically. Coordinates are checked against the
configured service bounds and reconciled against Olama Core. Imports fail closed
when the family does not exist in Core.

The primary Family Locations screen lists every Core family registered in the
selected academic year. Staff can paste `latitude, longitude` received through
WhatsApp or paste a full Google Maps URL. Manual changes are normalized, checked
against service bounds, audited, and returned to `needs_review`. Excel/CSV remains
available as a secondary bulk-import option.

## Safety

- All modifying REST routes require management capabilities and a WordPress
  REST nonce.
- Route publication requires the approval capability.
- Secrets are never returned by the settings endpoint.
- Bus master records cannot be created or deleted in Transportation. Only
  Olama-specific planning capacity, WordPress account links, accessibility, and
  tracking configuration may be changed.
- Audit records store a keyed hash of the IP address, not the raw IP.
- External optimization creates drafts only and can never auto-publish.
- Traccar remains disabled until explicitly configured.
