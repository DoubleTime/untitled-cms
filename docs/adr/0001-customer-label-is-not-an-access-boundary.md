---
status: accepted
---

# Customer label is not an access boundary

Customer Users sign in to the Marketplace from RPA-TOOL, and every AI Model and Script can be labelled with the Customer it was built for, so the obvious design is to hide Inari's entries from Plexus. We decided instead that every authenticated Customer User sees the entire catalogue and the Customer label is only a secondary filter; the primary way the catalogue is browsed is by Machine Model. The team wants one shared pool of automation for a given machine regardless of who paid for the first copy, and adding a tenancy wall later is a strict narrowing that can be done with a single policy, whereas removing one after RPA-TOOL depends on it is not.

## Consequences

- Nothing customer-confidential may be uploaded to the Marketplace. If that changes, add a visibility flag then, not a per-customer wall.
- Preview Images are served on the existing public `/media` route without auth; this is acceptable only because the catalogue itself is open to all Customers.
