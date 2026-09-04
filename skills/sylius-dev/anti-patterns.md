# Anti-Patterns - cross-cutting

Rules that apply to every step of a Sylius feature build: DI layout, namespaces, repositories, EntityManager scope, plugins, cache.

## 1. EntityManager in controller

❌ Wrong:

```php
public function __construct(private EntityManagerInterface $em) {}

public function __invoke(Request $request): Response
{
    $entity = new <Name>(...);
    $this->em->persist($entity);
    $this->em->flush();
}
```

✅ Right:

```php
public function __construct(
    private <Name>FactoryInterface $factory,
    private <Name>RepositoryInterface $repository,
) {
}

public function __invoke(Request $request): Response
{
    $entity = $this->factory->createNew();
    $this->repository->add($entity);
}
```

**Why:** Factory enforces invariants. Repo is the resource's persistence boundary. EM in controller bypasses both, blocks override via DI alias.

## 2. Concrete entity type-hints

❌ Wrong:

```php
public function setChannel(Channel $channel): void
{
    $this->channel = $channel;
}
```

✅ Right:

```php
public function setChannel(ChannelInterface $channel): void
{
    $this->channel = $channel;
}
```

**Why:** Sylius lets users swap `Channel` for their own subclass via resource override. Concrete type-hints break that. Same for `ProductInterface`, `CustomerInterface`, etc.

## 3. Bare RepositoryInterface for core resource

❌ Wrong:

```php
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

public function __construct(
    private RepositoryInterface $productRepository,
) {
}
```

✅ Right:

```php
use Sylius\Component\Core\Repository\ProductRepositoryInterface;

public function __construct(
    private ProductRepositoryInterface $productRepository,
) {
}
```

Applies to all Sylius core resources: `Product`, `ProductVariant`, `Channel`, `Customer`, `Order`, `Taxon`, `Shipment`, `Payment`, `Address`. Each has a Core-package repository interface with domain-specific finder methods (`findOneByChannelAndSlug`, `findByCustomer`, etc.).

**Why:** Bare `RepositoryInterface` is registered for every resource - autowiring sees ≥10 candidates, container build fails with "multiple services" or silently picks wrong one. Core-package interfaces are uniquely bound. Also unlocks the domain finder methods you actually need.

## 4. Wrong ChannelRepositoryInterface import

❌ Wrong:

```php
use Sylius\Component\Core\Repository\ChannelRepositoryInterface;
```

Class does not exist. Container build fails with "class not found".

✅ Right:

```php
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
```

**Why:** `Channel` is a standalone component; its repository interface lives in `Sylius\Component\Channel\Repository`, not in Core. Core has overrides for some interfaces (Product, Order, Customer) but not Channel.

## 5. Explicit service def in non-excluded dir

❌ Wrong: `config/services/app_<feature>.yaml` declares a form type + listener with tags, but `config/services.yaml` has:

```yaml
<AppNs>\:
    resource: '../src/'
    exclude:
        - '../src/Entity/'
        - '../src/Kernel.php'
```

Symptoms (silent):
- Form type: `new <Name>Type()` → `Too few arguments to function AbstractResourceType::__construct()` at first render.
- Listener: `doctrine.event_listener` tag dropped. The triggering event fires, listener never runs. No downstream dispatch.

`debug:container` may show the class with a `form.type` tag (autoconfigure from `_instanceof`), masking the failure.

✅ Right:

```yaml
<AppNs>\:
    resource: '../src/'
    exclude:
        - '../src/Entity/'
        - '../src/Kernel.php'
        - '../src/Form/'
        - '../src/EventListener/'
        - '../src/Message/'
        - '../src/Repository/'
        - '../src/Factory/'
```

**Why:** `<AppNs>\:` glob autoregister loads after `imports:` and silently re-binds every class found under `resource:`, dropping any explicit `arguments` / `tags`. Adding the dir to `exclude:` keeps the autoregister out of that subtree. Always pair an explicit-def write with an exclude-list update.

## 6. Ad-hoc cache:clear via Bash

❌ Wrong:

```bash
bin/console cache:clear
```

…called during exploration, debugging, or as part of verify.

✅ Right:

Call the Mate tool `sylius_cache_clear` exactly once before Playwright. The Mate tool is the boundary gate.

**Why:** Host projects commonly forbid shell `cache:clear` and agent harnesses may block the command outright, with no scoped exception for verify or Playwright prep. The Mate tool is PHP-native (Kernel API + FS), so it works regardless and is the single owner of that action.

## 7. EntityManager in feature handler

❌ Wrong:

```php
#[AsMessageHandler]
final class <X>Handler
{
    public function __construct(
        private EntityManagerInterface $em,
        private <Name>RepositoryInterface $entities,
        private SenderInterface $sender,
    ) {
    }

    public function __invoke(<X>Triggered $m): void
    {
        foreach ($this->entities->findByCode($m->code) as $entity) {
            $this->sender->send(...);
            $entity->markNotified();
            $this->em->flush();
        }
    }
}
```

✅ Right:

```php
public function __construct(
    private <Name>RepositoryInterface $entities,
    private SenderInterface $sender,
) {
}

public function __invoke(<X>Triggered $m): void
{
    foreach ($this->entities->findByCode($m->code) as $entity) {
        $this->sender->send(...);
        $entity->markNotified();
        $this->entities->add($entity);  // persist+flush idempotent for managed entities
    }
}
```

**Why:** "No `EntityManagerInterface` in feature code when a Resource exists" applies to controllers, handlers, listeners, services - anywhere the rule of resource-first composition holds. `EntityRepository::add()` is idempotent for both new and managed entities. Exception: bulk operations where DBAL/QueryBuilder beats per-row flush.

## 8. App\Repository sub-namespace

❌ Wrong: `<AppNs>\Repository\<Feature>\<Name>Repository`.

✅ Right: `<AppNs>\Repository\<Name>Repository`.

Pinned convention:

| Dir | Sub-NS? |
|---|---|
| `<AppNs>\Entity\<Feature>\<X>` | ✅ |
| `<AppNs>\Form\Type\<Feature>\<X>Type` | ✅ |
| `<AppNs>\Repository\<X>Repository` | ❌ FLAT |
| `<AppNs>\Factory\<X>Factory` | ❌ FLAT |
| `<AppNs>\EventListener\<X>Listener` | ❌ FLAT |
| `<AppNs>\Message\<X>` | ❌ FLAT |
| `<AppNs>\MessageHandler\<X>Handler` | ❌ FLAT |

**Why:** Repositories/factories/listeners/messages/handlers are infrastructure leaves - one per resource, no taxonomy benefit from nesting. Entities and form types have multiple variants per feature (Translation, Variant, Type), so sub-namespacing earns its keep there.

## 9. Hardcoded App\ namespace

❌ Wrong (a project whose root namespace isn't `App\`, e.g. Elesto uses `Elesto\`):

```php
namespace App\Entity\<Feature>;

class <Name> { }
```

Or in yaml:

```yaml
sylius_resource:
    resources:
        elesto.<alias>:
            classes:
                model: App\Entity\<Feature>\<Name>
```

`composer.json`:

```json
"autoload": {
    "psr-4": {
        "Elesto\\": "src/"
    }
}
```

Symbols don't resolve. Container build fails.

✅ Right:

```php
namespace Elesto\Entity\<Feature>;

class <Name> { }
```

```yaml
sylius_resource:
    resources:
        elesto.<alias>:
            classes:
                model: Elesto\Entity\<Feature>\<Name>
```

**Why:** Sylius-Standard is one project. `App\` is not universal. Always read `composer.json` `autoload.psr-4` first matching `src/` entry. The Mate tool `sylius_project_profile` returns it as `app_namespace`. Parameterize every scaffold.

## 10. Manual service in excluded dir without explicit autowire

❌ Wrong:

```yaml
# config/services.yaml
<AppNs>\:
    resource: '../src/'
    exclude: ['../src/Controller/']

<AppNs>\Controller\Shop\<X>Controller:
    tags: ['controller.service_arguments']
```

Sibling controllers in `services.yaml` set `autowire: false`. This controller inherits silently → runtime `Too few arguments`.

✅ Right:

```yaml
<AppNs>\Controller\Shop\<X>Controller:
    autowire: true
    autoconfigure: true
    tags: ['controller.service_arguments']
```

**Why:** `_defaults` inheritance is opaque when neighboring defs override. Always explicit on manual defs in excluded dirs. `symfony-service-detail --id=<FQCN>` is the catch - every `constructor` entry must resolve.
