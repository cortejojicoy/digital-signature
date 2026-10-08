# Signature hub

One place where UPLB signatures are created, paired and revoked
(`signature.uplb.edu.ph`), and many apps that use it. The same package runs
everywhere; `SIGNATURE_MODE` decides which parts switch on.

| Mode | Runs on | What it does |
|---|---|---|
| `standalone` (default) | any app not on the hub | Today's behaviour. Nothing in these pages applies. |
| `hub` | `signature.uplb.edu.ph` | People pair their computer, say who they are, and manage their signature. Admins verify claims and see the audit trail. Apps sign in and sign through its API. |
| `client` | performance, amp, sims, … | Keeps its own documents and the drag-onto-PDF viewer. Signs people in through the hub, holds a read-only mirror of each person's signature image, and sends the hub a hash to sign. |

The design and its reasoning: [plans/signature-hub.md](../../plans/signature-hub.md).

## Pages

- [Contracts](contracts.md): the API, webhook and agent wire formats every
  part relies on.
- [Hub API](api.md): OAuth for apps, people and signature endpoints, sign
  requests, webhooks, commands.
- [Identity and sign-in](identity.md): "Pair this computer", "Who are you?",
  verification, agent sign-in, moving to a new computer.
- [Panels](panels.md): the person panel (no topbar) and the admin panel, and
  how to set them up in the hub app.
- [Client mode](client.md): moving an app onto the hub, rollback, commands.
- [Hash-only signing](deferred-signing.md): how a PDF is stamped in the app
  and signed at the hub without the document leaving the app.
- [RustFS](rustfs.md): keeping signature images in object storage.

## In one picture

```
Kukux Sign Agent ── pair · sign in · approve ──► signature.uplb.edu.ph (hub)
                                                   ▲   │ webhooks
             SSO · signature image · sign a hash   │   ▼
                         performance, amp, sims (client mode)
```

Apps never talk to the agent, and the agent only ever talks to the hub.

## Switching modes

- **Hub:** `SIGNATURE_MODE=hub`, a personnel model fed from HR Kafka
  (`SIGNATURE_HUB_PERSONNEL_MODEL`), and the two panels from
  [panels.md](panels.md). The first super-admin pairs, identifies, then runs
  `php artisan signature:hub-admin {emp_no}`.
- **Client:** `php artisan signature:install --mode=client`, then register
  the app at the hub (`php artisan signature:hub-app …` there) and fill the
  `SIGNATURE_HUB_*` keys. See [client.md](client.md).
- **Back to standalone:** set `SIGNATURE_MODE=standalone`. Client mode never
  deletes local signatures, devices or certificates, so nothing is lost.

All tables exist in every mode, so switching never needs a migration.
