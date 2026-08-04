# Architecture

```text
Oracle ERP (read only)
  -> Olama Oracle Sync
     -> Olama Core families, students, academic years, buses, regions
        -> Olama Transportation
           -> family pickup location + family planning-area classification
           -> normalized direction-specific enrollment demand (or explicit fallback)
           -> academic-year + direction + area -> bus trip allocation
           -> effective family/student allocation resolver
           -> legacy polygon history (read only in the active UI)
           -> legacy annual student assignments and route versions (separate)
```

## Active planning boundary

`Olama_Transportation_Family_Area_Assignments` owns manual classification of `family_stops.major_area_id`. Single and bulk changes accept stable Core family UIDs and create a location-less placeholder when no family-stop row exists. Latitude/longitude remain nullable until captured. Changes preserve coordinates and unrelated fields, set manual audit metadata, never update students, and return the resulting effective allocation. Bulk changes validate all families before beginning a transaction and lock all selected stop rows before updating.

`Olama_Transportation_Area_Trip_Assignments` owns the unique academic-year + direction + area allocation. It validates active areas, active buses, effective capacity, and morning/afternoon trip range. Several areas can share a bus trip. Save locks the relevant area/assignment row, selected bus row, and competing trip rows, then recalculates area demand and aggregate trip demand before persistence. Browser counts are ignored. Deletion is non-destructive (`status=inactive`).

`Olama_Transportation_Effective_Assignments` is the normalized read model used by area tables, capacity summaries, family detail, and map data. It resolves:

`student -> family -> family stop -> major_area_id -> active area allocation -> bus/trip`

The resolver returns assignment status, location validity, direction student count, demand mode, effective capacity, aggregate used/remaining seats, and utilization. Transportation enrollments are selected for the whole year when any active enrollment exists; otherwise academic registration is used for the whole context with an explicit warning. The two sources are never silently mixed.

## Area synchronization

`Olama_Transportation_Area_Sync` projects active Core regions into local major areas and mappings. Existing local color, notes and boundary data are preserved. Core-mapped areas missing from Core may be deactivated; unmapped local planning areas are not deactivated. Backfill only fills `major_area_id IS NULL` when the stop has not been manually classified/cleared and records Core source metadata. WhatsApp/manual coordinate updates and imports preserve a populated area and manual audit metadata.

Oracle Area and Planning Area are deliberately separate. Oracle Area is read-only geography from Core. Planning Area is Transportation-owned operational classification. Existing boundary GeoJSON may be rendered as reference only and never determines family membership.

## Capacity and concurrency

Capacity is scoped to academic year + direction + bus + trip. Positive `planning_capacity` overrides `passenger_capacity`. Preview excludes the area's current assignment when editing, sums all other areas on the selected trip, adds current area demand, and returns capacity, remaining seats, utilization, status, and a hash. Save repeats the same calculation under locks; a changed hash produces HTTP 409 and updated capacity. Direction demand counts distinct active enrollment students with the matching direction flag. The selected bus row is the serialization lock for concurrent saves, so requests targeting different areas on the same physical bus cannot both validate from the same stale capacity state. Assignment rows and competing trip rows are also locked. Moving families later can surface a `capacity_problem` dynamically; the system does not silently split an area or mutate student records.

## Legacy separation

`wp_olama_transport_planning_groups` and `wp_olama_transport_planning_group_families` remain intact. Their REST routes are deprecated compatibility APIs. The primary UI cannot create or edit polygons and does not enqueue Leaflet Draw. Legacy membership never participates in effective assignment resolution; the admin shows a read-only historical table.

`wp_olama_student_bus_assignments` remains the legacy annual student-to-bus workflow. Area planning never writes it because it cannot represent direction, trip number, or family inheritance. Route versions, optimization, tracking, imports, and Core bus synchronization remain independent.

## Permissions and audits

- View: `olama_access_transport_mgmt`.
- Manage family areas and area trips: `olama_manage_transport_buses`.
- Legacy group lifecycle retains its prior approval convention only for compatibility.

Audits contain identifiers and aggregate calculations, not student identity. Events include family assign/clear/bulk, area-trip assign/update/unassign, and rejected capacity attempts.

## Current limitations

No automatic area splitting, marker clustering, route generation from allocations, stop ordering, travel-time matrix, arrival estimates, shared pickup generation, attendance, notification, or automatic legacy-group conversion is included in 2.4.
