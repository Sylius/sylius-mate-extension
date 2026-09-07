# Sylius Events - build steps

Step numbers follow the `sylius-dev` build map; hard rules and their rationale are in `SKILL.md` and `anti-patterns.md` next to this file.

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
