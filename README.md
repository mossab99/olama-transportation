# Olama Transportation 2.4.1

School transportation planning for the Olama ecosystem. Version 2.4.1 completes the area-based workflow with location-independent family classification, focused administration screens, server-confirmed preview-before-save, pagination, and concurrency conflict handling.

## Effective planning model

The active relationship is:

`Student -> Family -> Family Stop -> Planning Area -> Academic Year + Direction Area Allocation -> Bus + Trip`

`wp_olama_transport_family_stops.major_area_id` is the family-level classification. Every student in the family inherits that planning area. `wp_olama_transport_area_bus_assignments` allocates an area independently for each academic year and direction. Morning and afternoon can use different buses and trip numbers. Changing a family area changes its effective assignment immediately; no student assignment row is updated.

The legacy `wp_olama_student_bus_assignments` table remains the separate annual student-assignment workflow and is never written by area planning.

## Planning workflow

1. In **Family Locations**, select an active local Planning Area per family or use the atomic bulk action. This works even before coordinates exist: Transportation creates a nullable location placeholder without inventing a map point. Oracle Area remains read-only Core data.
2. Add or update the family pickup coordinates when available; this preserves the Planning Area and its audit metadata.
3. In **Area Planning**, select academic year and direction, then allocate each Planning Area to an active bus and valid direction-specific trip number.
4. Review the server-confirmed capacity preview and save. The map displays effective area/bus-trip allocation and never determines membership.

The effective capacity is positive `planning_capacity`, otherwise `passenger_capacity`. The preview is calculated on the server and is advisory; save always recalculates inside the transaction. A preview hash detects intervening demand/capacity changes and returns HTTP 409 with updated capacity. Demand counts active, direction-enabled transportation enrollment students. If the selected academic year has no active enrollment rows, the entire context uses academic-registration fallback and displays a warning; sources are never mixed. Multiple areas may share a bus trip, and their student demand is aggregated. Over-capacity saves return a conflict instructing the operator to create/use a smaller planning area and manually move families; areas are never split automatically.

## Core synchronization and ownership

Oracle ERP is read only through Olama Oracle Sync and Olama Core. Transportation never connects to Oracle. Core region refresh is idempotent, preserves local colors, notes and boundaries, and deactivates only missing Core-mapped areas. Unmapped local planning areas remain active. Core backfill fills only unassigned, non-manually-cleared family stops and records `area_assignment_source=core`. Manual classification or clearing records the actor/time and is not overwritten. Coordinate saves preserve all existing area metadata.

## Database changes in 2.4.1

- Family stops add `area_assignment_source`, `area_assigned_by`, and `area_assigned_at`.
- Family-stop latitude and longitude are nullable so planning classification can precede location capture.
- Area bus assignments add `trip_number`, `notes`, and `updated_by`.
- The canonical assignment key is academic year + direction + planning area. Bus-trip lookup includes academic year + direction + bus + trip. Multiple areas may use the same bus trip.
- Installation explicitly removes the obsolete area+bus unique index and adds the new area key when existing data is unambiguous. Historical rows are retained.
- Polygon group and group-family tables are retained unchanged for read-only history.

## REST API

Namespace: `/wp-json/olama-transportation/v1`.

- `GET|POST /planning/area-allocations`
- `POST /planning/area-allocations/preview`
- `DELETE /planning/area-allocations/{id}`
- `POST /family-locations/{id}/area`
- `POST /family-locations/by-family/{family_uid}/area`
- `POST /family-locations/bulk-area`
- `GET /family-locations`
- `GET /planning/areas/{id}/families`
- `GET /planning/map-data` (now area-based)
- `POST /core/refresh-areas`

Legacy `/planning/groups` lifecycle routes remain available for compatibility but are deprecated and are not called by the active admin screen.

View access requires `olama_access_transport_mgmt`; classification and allocation changes require `olama_manage_transport_buses`. WordPress REST nonce and capability checks apply.

## Concurrency and safety

Bulk family changes validate first, then lock and update every family stop in one transaction. Area allocation changes lock the area assignment context, planning area, selected bus, and competing bus-trip allocation rows; demand and capacity are recalculated inside the transaction. The bus lock serializes simultaneous allocations to the same physical bus, preventing two requests from over-allocating a trip based on stale browser values. All counts, bus state, trip range, and capacity are server-authoritative.

Run:

```bash
phpunit -c phpunit.xml.dist
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Current limitations: planning does not automatically split areas, optimize stop order, calculate trip timing, generate shared stops, cluster markers, or convert allocations into route versions. Existing boundary GeoJSON is reference-only. Legacy polygon groups are historical and ambiguous groups are not auto-converted. The basemap still requires access to the configured tile provider.
