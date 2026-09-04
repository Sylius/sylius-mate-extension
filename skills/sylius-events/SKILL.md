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

## 7. Listener

**Goal:** React to domain change.

**Decision tree:**

1. Sylius event exists? → tag listener on it.
2. No event but Doctrine mutation? → for a field-level state change use `onFlush` UnitOfWork (catches direct updates, bulk mutations, order-workflow side effects, etc.). `preUpdate` only as last resort.
3. Side effect non-trivial (email, external call) → dispatch Messenger message in listener, do work in handler.

**R-FLUSH-ORDER (mandatory split):**

- `onFlush` - read change set, collect **codes / UUIDs** (R-LISTENER-CODE-NOT-ID - never autoincrement ids; codes survive env restores / fixture reloads / snapshots) into a private array on the listener. NO dispatch, NO writes, NO `register_shutdown_function`. Exception: entities w/o natural codes (`Adjustment`, `OrderItemUnit`) - use id, document why.
- `postFlush` - iterate the collected array, dispatch Messenger messages / call downstream services. UoW guaranteed clean here.

```php
private array $collected = [];

public function onFlush(OnFlushEventArgs $args): void
{
    $uow = $args->getObjectManager()->getUnitOfWork();
    foreach ($uow->getScheduledEntityUpdates() as $entity) {
        if (!$entity instanceof <Entity>Interface) continue;
        $changes = $uow->getEntityChangeSet($entity);
        if (!isset($changes['<watched_field>'])) continue;
        [$old, $new] = $changes['<watched_field>'];
        if (/* $old no longer satisfies the trigger condition, $new does */) {
            $this->collected[] = (string) $entity->getCode();
        }
    }
}

public function postFlush(PostFlushEventArgs $args): void
{
    foreach ($this->collected as $code) {
        $this->bus->dispatch(new <X>Triggered($code));
    }
    $this->collected = [];
}
```

A filled-in instance of this exact pattern (stock crossing 0, dispatching a back-in-stock message) is in `sylius-dev/reference/worked-example.md`.

**Plugin gate (R-PLUGIN-AWARENESS):** before designing the listener target, confirm `sylius_installed_plugins` results. If `sylius/multi-source-inventory-plugin` present, stock lives in `InventorySourceStockInterface` rows - a Doctrine listener on `ProductVariant.onHand` is dead. Target MSI stock rows instead. Same applies to wishlist / refund / multi-currency / b2b plugin decorations.

**Output:** Listener registered via attribute (R-DOCTRINE-LISTENER-ATTRIBUTE). Two accepted forms:

Pattern A - `#[AsDoctrineListener]` per event:

```php
use Doctrine\ORM\Events;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class <X>Listener
{
}
```

Pattern A' - single `#[Autoconfigure]` w/ inline tags (cleaner on Symfony 6.4+):

```php
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(tags: [
    ['name' => 'doctrine.event_listener', 'event' => 'onFlush'],
    ['name' => 'doctrine.event_listener', 'event' => 'postFlush'],
])]
final class <X>Listener
{
}
```

Pick one. Never both. Never combine with yaml `doctrine.event_listener` tag (fires twice).

services.yaml - only declare for explicit DI args, NO `doctrine.event_listener` tag (autoconfigure + attribute already register; adding the tag fires twice):

```yaml
<AppNs>\EventListener\<X>Listener: ~
```

Forbidden hybrid: attribute + yaml tag together + `autoconfigure: true` → fires twice per event.

Pattern B fallback (rare): no attribute + `autoconfigure: false` + explicit yaml tags. Only when class lives in autowire-excluded dir AND attribute can't be used. With `<AppNs>\:` `exclude:` containing `'../src/EventListener/'`, Pattern B requires both the def and the tags in yaml.

**Choosing the source:** Doctrine `onFlush`/`postFlush` catches all paths (admin, API, order-workflow side effects, bulk imports). Sylius Resource event (`<host>.post_update`) covers admin/API only. For cross-path field watching, Doctrine is the simplest reliable signal when the field lives on an existing resource with no dedicated domain event - stock on `ProductVariant` is the canonical case, worked end to end in `sylius-dev/reference/worked-example.md`.

**Verify:** `bin/console debug:event-dispatcher` (Sylius events) / `bin/console doctrine:event:list`.

## 8. Async Handler

**Goal:** Heavy work off request path.

**Output:**

- `#[AsMessageHandler] final class <X>Handler`.
- Email send via Sylius `SenderInterface::send($code, [$recipient], $context)`. `$context` MUST include `channel` (resolved via `Sylius\Component\Channel\Repository\ChannelRepositoryInterface::findOneByCode($code)` - NOT `Sylius\Component\Core\Repository\ChannelRepositoryInterface`, which does not exist) and `localeCode` (from persisted resource, not current request) plus resource-specific keys (`variant`, `product`, `notification`, etc.).
- Messenger transport configured (`async` or `failed`).

**Verify:** `bin/console messenger:debug`.

## Hard Rules (refuse if violated)

Details + ✅ replacements in `anti-patterns.md`.

- ❌ Doctrine `preUpdate` when Sylius event covers the case.
- ❌ Sync mail send loop inside controller/listener (use Messenger async).
- ❌ **R-FLUSH-ORDER.** Dispatching Messenger / doing downstream writes directly from `onFlush`. UoW state shifts mid-`onFlush`; handler's flush corrupts or no-ops. Split: `onFlush` collects ids/keys into private array, `postFlush` iterates + dispatches. Also forbidden: `register_shutdown_function(...)` from a Doctrine listener (EM gone by then).
- ❌ **R-LISTENER-CODE-NOT-ID.** Doctrine listener collecting `$entity->getId()` for downstream Messenger dispatch when entity has a stable `code` / UUID. IDs change across env restores, fixture reloads, snapshots. Collect codes; handler does `findOneBy(['code' => $code])`. Exception: entities without natural codes (`Adjustment`, `OrderItemUnit`) - use id, document why.
- ❌ **R-DOCTRINE-LISTENER-ATTRIBUTE hybrid.** `#[AsDoctrineListener]` (or `#[Autoconfigure(tags:[...])]`) on class AND explicit `doctrine.event_listener` yaml tag together. With `autoconfigure: true`, both registrations apply → listener fires twice per event. Attribute-only is canonical. See `reference.md` for accepted patterns + rare yaml-only fallback.

## Linked Files

- `reference.md` - events vs Doctrine listeners: order of preference, Sylius event listener, the `onFlush` + `postFlush` split, side-effect safety.
- `anti-patterns.md` - ❌/✅ pairs for the rules above.
- `sylius-dev/reference/services.md` - listener registration shapes (attribute-only, the rare yaml-only fallback).
