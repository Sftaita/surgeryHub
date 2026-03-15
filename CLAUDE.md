# SurgicalHub — CLAUDE.md

## Project overview

SurgicalHub is a surgical management platform with:
- **Backend**: Symfony PHP (`backend/`)
- **Frontend**: React/TypeScript (`frontend/`)
- **Docs**: `docs/` — sources of truth for the project

## Sources of truth

Always consult these documents before making architectural or API decisions:

| File | Purpose |
|------|---------|
| `docs/api.md` | API contract — endpoints, request/response shapes |
| `docs/architecture.md` | System architecture and component responsibilities |
| `docs/decisions.md` | Architecture Decision Records (ADRs) |

When in doubt, the docs take precedence over inferred conventions.

## Backend conventions (Symfony PHP)

- **Authorization**: Strict RBAC via Symfony Voters. Never bypass or duplicate authorization logic outside of Voters.
- **Status transitions**: All entity status transitions go through dedicated endpoints — never inline state mutation in generic CRUD endpoints.
- **No business fallbacks**: Business rules are enforced server-side; the frontend must not implement fallback business logic.

## Frontend conventions (React/TypeScript)

- **No business fallbacks**: The frontend reflects server state. Do not implement client-side fallbacks that compensate for missing or ambiguous server responses.
- **Status transitions**: Trigger status changes via the dedicated backend endpoints; never derive or mutate status locally.

## Key rules summary

1. Read `docs/` before proposing API changes or new endpoints.
2. Authorization always goes through Symfony Voters — no ad-hoc permission checks.
3. Every status transition has its own endpoint.
4. The frontend trusts the backend; no business logic duplication on the client.
