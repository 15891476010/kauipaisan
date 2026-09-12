# API controller layers

All API endpoints remain in this backend project and keep the `/api/v1` URL format. Controllers are separated by caller:

- `User/` — user client endpoints (`/user/...`)
- `Agent/` — agent console endpoints (`/agent/...`)
- `Saas/` — SaaS platform/admin endpoints (`/admin/...`)

The layer controllers expose only the actions used by that layer and delegate to the existing domain controllers. This keeps the current behavior and database services while allowing each layer's methods to evolve independently. New layer-specific actions should be added to the corresponding directory and route target; do not add cross-layer methods to a shared controller.

`User/UserBusiness.php` contains the user betting and quick-entry implementation itself (it no longer delegates to the root `UserBusiness`). Changes to user betting behavior can therefore be made without changing the agent or SaaS controllers.
