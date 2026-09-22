---
status: accepted
---

# Revision files are stored outside the Vault

The Vault is the boilerplate's media manager: 50 MB cap, image sanitising, an extension allowlist meant for documents and pictures, and public UUID URLs. Revision files are `.h5` model weights and `.zip` bundles containing `.dll` executables, routinely larger than 50 MB, and must only be downloadable by an authenticated caller with the download logged. We store them on a separate private disk with their own upload path (`.h5`/`.zip` only, ~1 GB cap, SHA-256 recorded, ClamAV scan reused when enabled) and serve them only through the authenticated download endpoints. Preview Images still use the Vault because they are ordinary public pictures.
