# Architecture

```text
Oracle ERP (read only)
  -> Oracle Bridge
     -> Olama Oracle Sync
        -> Olama Core families, students, student-years, buses, regions
           -> Olama Transportation
              -> family locations + transport enrollment demand
              -> local area projection + geographic groups
              -> legacy annual assignments (separate)
              -> immutable route versions + optional optimizer (separate)
```

## Geographic planning boundary

`Olama_Transportation_Area_Sync` projects `olama_core()->transport_master()->get_regions(false)` into existing major areas/mappings. Existing color, boundary GeoJSON, and notes are preserved. Core regions missing from a later refresh are made inactive, never deleted. Family-stop backfill changes only stops with no current area so a reviewed/manual classification is preserved.

`Olama_Transportation_Map_Data` is the single normalized demand provider. It selects one demand mode for the whole academic year: direction-specific active transport enrollments, or academic-registration fallback when the enrollment table has no rows for that year. It combines family identity, Core transport region, family stop, area mapping, and active group assignment into one map response.

`Olama_Transportation_Geographic_Planning` owns group persistence. Draft create/update and approval use transactions and row locks, re-fetch demand/location records, detect family-direction and bus-trip conflicts, enforce the bus direction's configured trip range, and calculate effective capacity per trip. Browser-provided counts, coordinates, assignments, and capacity are ignored.

Explicit group-family rows are authoritative. Polygon GeoJSON is selection metadata only. Membership stores the demand count, coordinates, area, and region-name snapshot used for that planning decision.

## Lifecycle, permissions, and audits

- `draft`: editable by transportation management.
- `approved`: immutable until reverted by an approval user.
- `archived`: historical and non-blocking; never hard-deleted by the planner.
- View: `olama_access_transport_mgmt`.
- Draft create/edit: `olama_manage_transport_buses`.
- Approve/revert/archive: `olama_approve_transport_routes`, with the established management fallback.

Area refresh, group lifecycle, membership, bus trip-count, and planning-capacity changes use `Olama_Transportation_Audit::record()` with aggregate snapshots rather than student-sensitive payloads.

## Separation from existing workflows

The legacy `wp_olama_student_bus_assignments` table still represents one annual student-to-bus relationship. Geographic planning does not write it because it cannot represent direction, trip number, or family membership. Existing Student Assignments behavior remains intact.

Route versions remain a later operational stage. Saving or approving a geographic group does not invoke the optimizer or create/update a route version. The existing route CRUD, publication, Google/webhook abstraction, and tracking configuration are unchanged.

## Current limitations

No automatic clustering/group creation, route ordering, travel-time matrix, arrival estimate, trip timing dependency, shared pickup generation, GPS/driver/parent app, attendance, or notifications are part of the 2.3 MVP.
