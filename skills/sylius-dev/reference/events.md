# Reference - Events vs Doctrine Listeners

Decision tree for "react to domain change".

## Order of preference

1. **Sylius domain event.** Tagged on the application's domain operation (e.g. `sylius.order.post_complete`, `sylius.product.post_update`). Call `sylius_domain_list_events` to find existing ones.
2. **Sylius lifecycle event from `Resource` operations.** Fires before/after factory create, repo add/update/remove.
3. **Doctrine `onFlush` + UnitOfWork.** Catches all persistence paths (direct updates, internal mutations). Use when no Sylius event covers the trigger - typical for inventory/stock changes.
4. **Doctrine `postUpdate` / `postPersist`.** Acceptable for simple field-watch with low side effect risk.
5. **Doctrine `preUpdate`.** Last resort - runs inside flush, can be skipped if change set empty, risky for side effects.

## Sylius event listener

```php
public static function getSubscribedEvents(): array
{
    return [
        'sylius.product_variant.post_update' => 'onUpdate',
    ];
}

public function onUpdate(ResourceControllerEvent $event): void
{
    $variant = $event->getSubject();
    // ...
}
```

With `autoconfigure: true` (Sylius-Standard default), implementing `EventSubscriberInterface` is enough. No yaml tag needed. Avoid mixing attribute and yaml tag on the same listener - `autoconfigure` + manual tag double-registers.

## Doctrine onFlush + postFlush split (inventory pattern, R-FLUSH-ORDER)

Register via PHP attribute (R-DOCTRINE-LISTENER-ATTRIBUTE) - canonical, no yaml needed:

```php
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ProductBackInStockListener
{
    private array $collected = [];

    public function __construct(private MessageBusInterface $bus) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof ProductVariantInterface) {
                continue;
            }
            $changes = $uow->getEntityChangeSet($entity);
            if (!isset($changes['onHand'])) {
                continue;
            }
            [$old, $new] = $changes['onHand'];
            if ($old <= 0 && $new > 0) {
                $this->collected[] = $entity->getCode();
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        foreach ($this->collected as $code) {
            $this->bus->dispatch(new ProductBackInStock($code));
        }
        $this->collected = [];
    }
}
```

Do NOT also add `doctrine.event_listener` yaml tags - with `autoconfigure: true`, attribute + yaml tag together fires the listener twice per event.

Fallback yaml-only form (rare, only when class is in autowire-excluded dir and attribute can't be used):

```yaml
App\EventListener\ProductBackInStockListener:
    autoconfigure: false
    tags:
        - { name: doctrine.event_listener, event: onFlush }
        - { name: doctrine.event_listener, event: postFlush }
```

**Why split:**

- `onFlush` runs mid-UoW. Dispatching there means the message handler (sync transport in dev) flushes inside an already-open UoW → corrupts state or silently no-ops.
- `register_shutdown_function` from a listener is worse: fires after PHP teardown when EM may be gone.
- `postFlush` runs after the UoW is closed. Safe to dispatch / trigger downstream writes.

**Why `onFlush` (not `postUpdate`) for the detection half:**

- Catches inventory increment from any code path (admin grid update, stock adjustment, hold release, direct repo set).
- UnitOfWork exposes precise change set (old → new). `postUpdate` lacks that.

## When NOT to dispatch synchronously inside listener

Heavy work (email loop, external API, bulk DB) - wrap as Messenger message and route to async transport. Listener becomes a thin tripwire.

## Side-effect safety

- Don't `persist`/`flush` new entities from inside `preUpdate`. Use `postFlush` if you must.
- Don't modify the changing entity from `onFlush` without recomputing change set (`$uow->recomputeSingleEntityChangeSet`).
- Messenger dispatch in `onFlush` is safe - bus sends after flush via outbox or directly via transport.
