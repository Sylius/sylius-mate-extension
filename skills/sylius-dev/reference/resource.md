# Reference - Sylius Resource Pattern

Deep dive. Fetch when uncertain about resource registration.

## What a Resource is

A Sylius Resource = an entity + interface + repository + factory + form, registered under a unique alias in `sylius_resource.resources.<alias>`. The alias becomes the contract: every override, grid, route, fixture references it.

## Required pieces

| Piece | Class | Base |
|---|---|---|
| Model | `App\Entity\X` | `implements XInterface` |
| Interface | `App\Entity\XInterface` | `extends ResourceInterface` |
| Repository | `App\Repository\XRepository` | `extends EntityRepository implements XRepositoryInterface` |
| Repository interface | `App\Repository\XRepositoryInterface` | `extends RepositoryInterface` |
| Factory | **default - omit `classes.factory:`** | Sylius wires `Sylius\Resource\Factory\Factory` automatically |
| Form | `App\Form\Type\XType` | `extends AbstractResourceType` |

**Custom factory only when needed.** If the feature requires pre-construction behavior (set defaults on create, attach related entity), add `App\Factory\XFactory implements XFactoryInterface` with constructor signature **`__construct(string $className)`** - Sylius resource-bundle compiler pass injects the entity FQCN string into that slot. Any other signature crashes at first `createNew()`.

## Registration

```yaml
sylius_resource:
    resources:
        app.x:
            classes:
                model: App\Entity\X
                interface: App\Entity\XInterface
                repository: App\Repository\XRepository
                # factory: only when custom factory needed
                form: App\Form\Type\XType
```

## What you get for free

- `app.repository.x` service.
- `app.factory.x` service.
- `app.manager.x` (object manager) service.
- Override hook: a downstream project can swap model class without touching consumers.
- Grid driver auto-detection.
- API Platform integration (if `sylius/api-bundle` present).

## Common mistakes

- Forgetting the interface. Resource consumers should depend on the interface, not the concrete.
- Adding `final` to the model. Sylius models must remain extensible.
- Hand-writing the repository service in DI yaml. Resource bundle registers it.
- Declaring a custom `classes.factory:` whose constructor isn't `__construct(string $className)`. Sylius injects entity FQCN string into the first arg; mismatched signature crashes at first `createNew()`. `debug:container` clean - only runtime fails.

## Translatable resource

If the resource has localized fields, model implements `TranslatableInterface` + uses `TranslatableTrait`; a sibling `XTranslation` model implements `TranslationInterface` + `TranslationTrait`. Register both under separate aliases (`app.x` + `app.x_translation`).
