---
name: sylius-events
description: React to domain changes in Sylius 2.x - pick the event source (Sylius resource event vs Doctrine onFlush/postFlush vs preUpdate), register listeners attribute-only, collect codes in onFlush and dispatch in postFlush, hand heavy work to Messenger handlers, respect plugin decorators (MSI moves stock). Part of the sylius-dev skill family; for a whole feature start from sylius-dev. Triggers on "Sylius listener", "Doctrine listener", "onFlush", "when stock changes", "inventory listener", "back-in-stock", "Sylius event", "Messenger handler in Sylius", "async in Sylius".
---

# Sylius Events and Listeners

Steps 7-8 of the `sylius-dev` build map. Read `sylius-dev` first for discovery (domain event grep, `sylius_installed_plugins` + `sylius_service_decorators`) and the cross-cutting rules. Paths like `sylius-dev/reference/services.md` point at a sibling skill, installed next to this one (directory `<name>` in the extension, `mate-<name>` under `.agents/skills/`).

## Event Source Decision

For features watching a field change (a threshold crossing, a status flip):

- **Doctrine `onFlush` + `postFlush`** (Pattern A, attribute-only) - catches all paths: admin grid mutations, API, order-workflow side effects, bulk imports. Preferred for cross-path coverage.
- **Sylius Resource event (`<host>.post_update`)** - admin/API only; order-workflow side effects bypass the Resource controller. Idiomatic for admin-only-mutation features.

Many fields worth watching (stock, order state) live on an existing resource rather than being a resource of their own, and have no dedicated Sylius domain event. Doctrine listener is then the simplest reliable signal - `sylius-dev/reference/worked-example.md` walks the stock case end to end.

## Build (steps 7-8)

Full procedure - decision tree, the `onFlush` / `postFlush` skeleton, both accepted registration attributes, the handler shape - in `workflow.md`. In short:

- **7. Listener:** Sylius event if one exists; otherwise Doctrine `onFlush` collects codes into a private array, `postFlush` dispatches. Attribute-only registration (`#[AsDoctrineListener]` per event, or one `#[Autoconfigure(tags: [...])]`), never plus a yaml tag. Confirm the target service is not decorated by a plugin first.
- **8. Async handler:** `#[AsMessageHandler]`, email through Sylius `SenderInterface` with `channel` (via `Sylius\Component\Channel\Repository\ChannelRepositoryInterface`) + `localeCode` (from the persisted resource) + resource keys in the context. Verify with `bin/console messenger:debug`.

## Hard Rules (refuse if violated)

Rationale and ✅ replacements in `anti-patterns.md`.

- ❌ Doctrine `preUpdate` when a Sylius event covers the case.
- ❌ Sync mail send loop inside a controller/listener → dispatch a Messenger message, send in the handler.
- ❌ **R-FLUSH-ORDER.** Dispatching or writing from `onFlush` (or `register_shutdown_function` from a listener) → collect in `onFlush`, dispatch in `postFlush`.
- ❌ **R-LISTENER-CODE-NOT-ID.** Listener collecting autoincrement ids for downstream dispatch → collect `code` / UUID; ids only for entities without natural codes, documented.
- ❌ **R-DOCTRINE-LISTENER-ATTRIBUTE.** `#[AsDoctrineListener]` / `#[Autoconfigure(tags:)]` AND a yaml `doctrine.event_listener` tag → fires twice; attribute-only.

## Linked Files

- `workflow.md` - the build steps above in full: tool calls, outputs, per-step verification.
- `reference.md` - events vs Doctrine listeners: order of preference, Sylius event listener, the `onFlush` + `postFlush` split, side-effect safety.
- `anti-patterns.md` - ❌/✅ pairs for the rules above.
- `sylius-dev/reference/services.md` - listener registration shapes (attribute-only, the rare yaml-only fallback).
