---
status: accepted
---

# UNYSIS Boxes identify by motherboard UUID under a Customer User login

RPA-TOOL could authenticate to the Marketplace with a per-device token provisioned by a Team Member, or with a human Customer User login. We chose the human login: `/api/login` takes email, password and the box's motherboard UUID, issues a Sanctum token, and auto-registers an UNYSIS Box record under the user's Customer on first sight. Team Members label or block boxes afterwards. This means no field provisioning step before a box can pull scripts, at the cost of trusting the UUID the client reports; a blocked box is refused at login, and a whitelist mode can be added behind a setting if the trust proves misplaced.

## Consequences

- Download attribution is `Customer User + UNYSIS Box`, both derived from the token, never from request parameters.
- Customer Users live in the existing `users` table with role `customer` (`backend_access = false`); they are denied the web login and admin entirely.
