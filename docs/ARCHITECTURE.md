# Architecture

```text
Oracle ERP (read only)
  -> D:\api Oracle Bridge (read-only normalized endpoints)
     -> Olama Oracle Sync (the only Oracle consumer)
        -> Olama Core canonical tables and services
           -> Olama Transportation planning projection
              -> planning and immutable route versions
              -> Google/external optimizer (optional, draft proposals only)
              -> Traccar device mapping (optional, disabled by default)
              -> Olama Messages (future event consumer)
```

## Core workflow

1. Oracle Sync imports fleet and region masters into Olama Core.
2. Transportation refreshes its planning projection from Core; it never calls
   the Bridge.
3. Import family coordinates.
4. Reconcile and approve a family-level stop against Core families.
5. Enroll Core students separately for morning and afternoon.
6. Map stops to major areas.
7. Calculate demand and allocate buses by direction.
8. Assign approved stops to a bus and create a draft version.
9. Optimize the draft externally or order it manually.
10. Review and publish. Earlier published versions become archived.

## Ownership boundary

- `olama_core_*`: canonical Oracle-derived families, students, academic-year
  snapshots, employees, transportation records, buses, and regions.
- `olama_transport_*`: Olama-owned locations, enrollments, areas, planning
  overrides, allocations, routes, approvals, audits, and optional integrations.
- The local Transportation bus row is a planning projection keyed by
  `core_bus_uid`; Oracle-derived fields are read-only and refreshed from Core.

The optimizer computes order. It does not own student records, approvals, route
history, or family permissions.

## Traccar extension boundary

The `tracking_devices` table maps an Olama bus to a provider device. Future
event ingestion should be a separate authenticated endpoint with event
idempotency, active-trip checks, and Olama Messages recipient resolution.
No family notification is sent directly by Traccar.
