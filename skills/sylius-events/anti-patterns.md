# Anti-Patterns - events

Event source, Doctrine listener and Messenger traps. Each entry: ❌ wrong, ✅ right, why.

## 1. Doctrine preUpdate for a field-level trigger

❌ Wrong:

```php
public function preUpdate(PreUpdateEventArgs $args): void
{
    if (!$args->hasChangedField('<field>')) return;
    // side effect...
}
```

✅ Right (Sylius event, when one exists):

```php
public static function getSubscribedEvents(): array
{
    return ['sylius.<host>.post_update' => 'onUpdate'];
}
```

✅ Right (no event, needs UoW - `onFlush`):

```php
public function onFlush(OnFlushEventArgs $args): void
{
    $uow = $args->getObjectManager()->getUnitOfWork();
    foreach ($uow->getScheduledEntityUpdates() as $entity) {
        if (!$entity instanceof <Entity>Interface) continue;
        $changes = $uow->getEntityChangeSet($entity);
        if (!isset($changes['<field>'])) continue;
        [$old, $new] = $changes['<field>'];
        if (/* trigger condition on $old -> $new */) {
            $this->messageBus->dispatch(new <X>Triggered($entity->getCode()));
        }
    }
}
```

**Why:** `preUpdate` misses cases (indirect updates, direct UPDATE via custom code). `onFlush` + UoW catches all paths. Sylius event preferred when one exists. `sylius-dev/reference/worked-example.md` has this pattern filled in for the stock-crossing-zero case.

## 2. Sync mail loop

❌ Wrong:

```php
foreach ($recipients as $r) {
    $this->sender->send('app_<code>', [$r->getEmail()], [...]);
}
```

…inside a Doctrine listener or controller.

✅ Right:

Listener dispatches a message. Handler:

```php
#[AsMessageHandler]
final class <X>Handler
{
    public function __invoke(<X>Triggered $message): void
    {
        foreach ($this->repository->findByCode($message->code) as $r) {
            $this->sender->send('app_<code>', [$r->getEmail()], [...]);
        }
    }
}
```

**Why:** Sync send in flush path = transaction lock + slow request + flaky on SMTP outage. Messenger isolates.

## 3. Dispatching from onFlush

❌ Wrong:

```php
public function onFlush(OnFlushEventArgs $args): void
{
    // ... detect change ...
    $this->bus->dispatch(new <X>Triggered($code));
}
```

Or:

```php
public function onFlush(OnFlushEventArgs $args): void
{
    register_shutdown_function(function () use ($code) {
        $this->bus->dispatch(new <X>Triggered($code));
    });
}
```

✅ Right - split `onFlush` (collect) + `postFlush` (dispatch):

```php
private array $collected = [];

public function onFlush(OnFlushEventArgs $args): void
{
    $uow = $args->getObjectManager()->getUnitOfWork();
    foreach ($uow->getScheduledEntityUpdates() as $entity) {
        if (!$entity instanceof <Entity>Interface) continue;
        $changes = $uow->getEntityChangeSet($entity);
        if (!isset($changes['<field>'])) continue;
        [$old, $new] = $changes['<field>'];
        if (/* trigger condition */) {
            $this->collected[] = $entity->getCode();
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

**Why:** UoW is mid-flush during `onFlush` - any downstream code that flushes (e.g. message handler with sync transport) corrupts the active UoW or no-ops. `register_shutdown_function` fires after PHP starts tearing down - EM may already be gone. Only `postFlush` is safe.

## 4. Listener collecting autoincrement ids

❌ Wrong:

```php
public function onFlush(OnFlushEventArgs $args): void
{
    foreach ($uow->getScheduledEntityUpdates() as $entity) {
        if ($entity instanceof <Entity>Interface) {
            $this->collected[] = $entity->getId();
        }
    }
}

public function postFlush(PostFlushEventArgs $args): void
{
    foreach ($this->collected as $id) {
        $this->bus->dispatch(new <X>Triggered($id));
    }
}
```

✅ Right:

```php
$this->collected[] = (string) $entity->getCode();
// ...
$this->bus->dispatch(new <X>Triggered($code));
```

Handler:

```php
$entity = $this->repository->findOneBy(['code' => $message->code]);
```

**Why:** IDs change across env restores, fixture reloads, snapshots, replays. Code/UUID is the stable business identifier. Indexed-column `findOneBy(['code' => …])` is the same cost as `find($id)`. **Exception:** entities without natural codes (`Adjustment`, `OrderItemUnit`) - use id, document why.

## 5. Doctrine listener attribute + yaml tag hybrid

❌ Wrong:

```php
#[AsDoctrineListener(event: Events::onFlush)]
final class <X>Listener { }
```

```yaml
<AppNs>\EventListener\<X>Listener:
    tags:
        - { name: doctrine.event_listener, event: onFlush }
```

With `autoconfigure: true`, both registrations apply → listener fires twice per `onFlush`.

✅ Right (Pattern A - attribute only):

```php
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class <X>Listener { }
```

```yaml
<AppNs>\EventListener\<X>Listener: ~
```

**Why:** Attribute already declares the binding; yaml tag duplicates. Once you have the attribute, never re-tag in yaml. Pattern B fallback (no attribute + `autoconfigure: false` + tag) is for autowire-excluded dirs where the attribute can't fire.

## 6. Field-watching listener under a decorating plugin

❌ Wrong (project has `sylius/multi-source-inventory-plugin`):

```php
#[AsDoctrineListener(event: Events::onFlush)]
final class <X>Listener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof ProductVariantInterface) continue;
            $changes = $uow->getEntityChangeSet($entity);
            if (!isset($changes['onHand'])) continue;   // ← never fires under MSI
        }
    }
}
```

`ProductVariant.onHand` column is dead under MSI; stock changes happen on `InventorySourceStock` rows instead.

✅ Right: target the MSI entity:

```php
foreach ($uow->getScheduledEntityUpdates() as $entity) {
    if (!$entity instanceof InventorySourceStockInterface) continue;
    $changes = $uow->getEntityChangeSet($entity);
    if (!isset($changes['onHand'])) continue;
    [$old, $new] = $changes['onHand'];
    if ($old <= 0 && $new > 0) {
        $this->collected[] = (string) $entity->getProductVariant()->getCode();
    }
}
```

**Why:** Plugin decorations move the source of truth - this is one concrete, always-true instance (MSI moves stock off `ProductVariant.onHand`), not specific to any one feature you might be building on top of it. Always call the Mate tool `sylius_installed_plugins` before designing a listener / inventory checker / price service / channel resolver. Skip → ship dead code.
