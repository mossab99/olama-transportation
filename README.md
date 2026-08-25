# Olama Transportation 2.4.1

School transportation planning for the Olama ecosystem. Version 2.8.0 adds trip-owned companions, bus-derived drivers on trip cards, and private CR80 student transportation badge printing.

The Transportation Settings screen includes an independent English/Arabic language selector. It controls the complete workspace, dynamically rendered reports, print layouts, plugin REST messages, and RTL presentation without changing the WordPress or Olama School language.

## Effective planning model

The active relationship is:

`Student -> Family -> Family Stop -> Planning Area -> Academic Year + Direction Area Allocation -> Bus + Trip`

`wp_olama_transport_family_stops.major_area_id` is the family-level classification. Every student in the family inherits that planning area. `wp_olama_transport_area_bus_assignments` allocates an area independently for each academic year and direction. Morning and afternoon can use different buses and trip numbers. Changing a family area changes its effective assignment immediately; no student assignment row is updated.

`wp_olama_student_bus_assignments` is the operator-controlled student selection layer. Assignments are scoped by academic year, direction, bus, and trip. The assignment screen derives eligible students from the areas attached to an already-defined bus trip; area planning itself does not automatically select students.

## Planning workflow

1. In **Family Locations**, select a Planning Area from the active Oracle Area value set per family or use the atomic bulk action. The assignment is stored locally, so an administrator can correct a family's Planning Area without changing its read-only Oracle Area. This works even before coordinates exist: Transportation creates a nullable location placeholder without inventing a map point.
2. Add or update the family pickup coordinates when available; this preserves the Planning Area and its audit metadata.
3. In **Area Planning**, select academic year and direction, then allocate each Planning Area to an active bus and valid direction-specific trip number.
4. Review the server-confirmed capacity preview and save. The map displays effective area/bus-trip allocation and never determines membership.

Use **Family Move** to transfer one or more complete families between compatible trips. Both trips must share the academic year, direction, and lifecycle status; the destination must cover the families' planning areas and remain within its planning and bus capacities. Published moves update live student assignments atomically, and affected route versions must be rebuilt before they can be optimized or republished.

The effective capacity is positive `planning_capacity`, otherwise `passenger_capacity`. The preview is calculated on the server and is advisory; save always recalculates inside the transaction. A preview hash detects intervening demand/capacity changes and returns HTTP 409 with updated capacity. Demand counts active, direction-enabled transportation enrollment students. If the selected academic year has no active enrollment rows, the entire context uses academic-registration fallback and displays a warning; sources are never mixed. Multiple areas may share a bus trip, and their student demand is aggregated. Over-capacity saves return a conflict instructing the operator to create/use a smaller planning area and manually move families; areas are never split automatically.

## Core synchronization and ownership

Oracle ERP is read only through Olama Oracle Sync and Olama Core. Transportation never connects to Oracle. Core region refresh is idempotent, preserves local colors, notes and boundaries, and deactivates only missing Core-mapped areas. Unmapped local planning areas remain active. Core backfill fills only unassigned, non-manually-cleared family stops and records `area_assignment_source=core`. Manual classification or clearing records the actor/time and is not overwritten. Coordinate saves preserve all existing area metadata.

## Reporting model

Reports keep subscription, academic registration, and trip assignment as separate facts. The canonical subscribed population is the distinct active student set in `wp_olama_core_student_transportation` for the selected study year. Walking students are academically registered students absent from that active set. Shared-trip membership is then joined independently for arrival and departure; it never creates a transportation subscription and it never makes an unsubscribed student disappear from the walking report.

Direction-specific reports classify each subscribed student as assigned or unassigned for that direction. The combined report classifies students as assigned in both directions, assigned in one direction, or not assigned. Assignment-gap scopes distinguish a missing arrival, missing departure, either missing direction, and no trip in either direction. Report diagnostics expose collapsed duplicate subscription rows, missing Core identities/academic registrations, stale trip members that are no longer subscribed, and multiple assignments in one direction.

The KG aggregate accepts normalized and combined labels including `KG1`, `KG2`, `kg1 بستان`, `kg2 تمهيدي`, `بستان`, and `تمهيدي`. Filtered transportation summaries show synchronized source-row count separately from distinct-student count so duplicate Oracle rows remain visible without double-counting students.

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
- `GET /areas-workspace/shared-trips/{id}/badges`
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
