# Olama Transportation 2.3.0

School transportation planning for the Olama ecosystem. Version 2.3 adds a map-based, family-level geographic planner to the existing Area Planning tab while retaining legacy assignments and route planning.

## Data ownership and setup

- Oracle owns family, student, employee, bus, and transportation-region master data.
- Olama Oracle Sync is the only Oracle consumer. Olama Core stores the canonical mirror; Transportation reads Core services and never calls Oracle.
- Transportation owns family coordinates, normalized transport enrollments, local area projections, bus planning overrides, geographic groups, routes, and audits.
- Run the Core transportation import, refresh buses, then click **Refresh Areas from Olama Core** in Area Planning. The area sync is idempotent, preserves local colors/boundaries/notes, and never hard-deletes missing Core regions.
- Save/import family coordinates, open Area Planning, choose a year and direction, then select families by marker or polygon and save a bus-trip group.

## Geographic planner

The planning relationship is `Family -> Geographic Group -> Bus Trip -> Physical Bus`. Morning and afternoon are independent. A family can belong to one active group per year and direction, and a bus-trip slot can be occupied by only one active group.

Groups move through `draft -> approved -> archived`. Approved groups are read-only until an approval user reverts them. Archiving releases family and trip conflicts. Geographic groups are not written to `wp_olama_student_bus_assignments` and are not converted automatically into route versions.

Transport demand comes from active `wp_olama_transport_enrollments` rows and honors the morning/afternoon flags. If an academic year has no transport enrollments at all, the map uses academic registration for both directions and displays an explicit fallback warning; the sources are never silently mixed. Both `needs_review` and `approved` in-bounds family locations are selectable during the pilot.

Each bus defaults to two morning and three afternoon trip slots. Effective trip capacity is the positive planning capacity when present, otherwise Core capacity. A planning override may be entered when Core capacity is zero; a bus with zero effective capacity is unavailable. Capacity is enforced independently for every trip.

Leaflet and Leaflet Draw are bundled locally. Map tiles use the isolated OpenStreetMap-compatible URL configured in the planner bootstrap; attribution remains visible.

## Tables added in 2.3

- `wp_olama_transport_planning_groups`: group, direction, trip, bus, lifecycle, polygon, and server-calculated snapshots.
- `wp_olama_transport_planning_group_families`: authoritative family membership and location/demand audit snapshots.

The bus projection gains `morning_trip_count` and `afternoon_trip_count`. Core refreshes do not overwrite these local planning fields.

## REST API

Namespace: `/wp-json/olama-transportation/v1`.

- `GET /planning/map-data`
- `GET /planning/trip-slots`
- `GET|POST /planning/groups`
- `GET|PUT /planning/groups/{id}`
- `POST /planning/groups/{id}/approve`
- `POST /planning/groups/{id}/revert`
- `POST /planning/groups/{id}/archive`
- `POST /core/refresh-areas`
- Existing CRUD, route, optimizer, import, bus-refresh, report, and settings endpoints remain available.

View access requires `olama_access_transport_mgmt`; draft changes require `olama_manage_transport_buses`; lifecycle operations require `olama_approve_transport_routes` or the existing management fallback. WordPress REST nonces and server-side authorization apply to every endpoint.

## Safety and testing

Persistence uses prepared queries, InnoDB transactions and row locks. The server re-fetches families and recalculates demand, locations, conflicts, trip range, and capacity. Polygon input accepts only canonical Polygon GeoJSON. Dynamic map UI content is constructed as text, and no Oracle credentials or unnecessary student details are exposed.

Run:

```bash
phpunit -c phpunit.xml.dist
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Known limitations: the MVP does not optimize stop order, calculate timing/matrices, cluster markers, create shared stops, create route versions, or synchronize legacy annual assignments. It requires network access to the configured tile provider to display the basemap; planning markers and persistence remain local.
