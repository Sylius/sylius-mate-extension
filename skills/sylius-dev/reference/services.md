# Reference - Services Configuration

Patterns for `config/services.yaml`, per-feature service files, repository aliases, and form type registration.

## File layout (R-IMPORTS-SERVICES-DIR)

Top-level `config/services.yaml` stays stable. Per-feature service defs live in `config/services/app_<feature>.yaml`:

```yaml
# config/services.yaml
imports:
    - { resource: 'services/' }

services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    App\:
        resource: '../src/'
        exclude:
            - '../src/Entity/'
            - '../src/Kernel.php'
            - '../src/Form/'           # AbstractResourceType form types - explicit defs
            - '../src/EventListener/'  # explicit Doctrine / kernel listener tags
            - '../src/Message/'        # DTOs, never services
            - '../src/Repository/'     # Sylius resource bundle wires repos
            - '../src/Factory/'        # Sylius resource bundle wires factories
```

Per-feature file example:

```yaml
# config/services/app_<feature>.yaml
services:
    App\Form\Type\<Feature>\<Name>Type:
        arguments:
            - 'App\Entity\<Feature>\<Name>'
            - ['sylius']
        tags:
            - { name: form.type }
```

A filled-in instance (`app_back_in_stock.yaml`) is in `reference/worked-example.md`.

Every time the skill emits an explicit service def in a directory not currently on the `exclude:` list, that directory must be appended to `exclude:` in the same write (R-EXCLUDE-EXPLICIT-DIRS). Autoregister silently clobbers the explicit def - `arguments` dropped, `tags` dropped, `debug:container` still shows the class but wired wrong.

## Form types extending `AbstractResourceType` (R-FORM-SVC)

Sylius-Standard sets `_instanceof: AbstractResourceType: { autowire: false }`. Symfony Form factory falls back to `new <FQCN>()` and dies on the missing `__construct(string $dataClass, array $validationGroups)` args. `debug:container` looks clean.

Single FQCN-keyed service - no alias indirection. Symfony Form factory resolves `createForm(<X>Type::class)` directly via FQCN service id:

```yaml
# config/services/app_<feature>.yaml
services:
    App\Form\Type\<Feature>\<X>Type:
        arguments:
            - 'App\Entity\<Feature>\<X>'
            - ['sylius']
        tags:
            - { name: form.type }
```

Old `app.form.type.<x>` id + FQCN alias dual pattern is dropped - one trap door fewer.

Verify: `bin/console debug:container --show-arguments App\Form\Type\<Feature>\<X>Type` - every constructor arg resolves.

## Core Sylius repository aliases (R-CORE-REPO-ALIASES)

When injecting a Sylius core repository FQCN interface, declare aliases to the canonical repository services. Add once per project, idempotent:

```yaml
# config/services.yaml
services:
    Sylius\Component\Core\Repository\ProductVariantRepositoryInterface:
        alias: sylius.repository.product_variant

    Sylius\Component\Core\Repository\ProductRepositoryInterface:
        alias: sylius.repository.product

    Sylius\Component\Core\Repository\OrderRepositoryInterface:
        alias: sylius.repository.order

    Sylius\Component\Channel\Repository\ChannelRepositoryInterface:
        alias: sylius.repository.channel

    Sylius\Component\Customer\Repository\CustomerRepositoryInterface:
        alias: sylius.repository.customer
```

Notes:

- Channel repo lives in `Sylius\Component\Channel\Repository\` - `Sylius\Component\Core\Repository\ChannelRepositoryInterface` does **not** exist. Wrong import = container compile error.
- App repos registered via `sylius_resource` are auto-aliased by the resource-bundle compiler pass (FQCN interface → `app.repository.<alias>`). Do NOT add manual `App\Repository\<X>RepositoryInterface: alias: app.repository.<x>` - duplicate (R-NO-MANUAL-REPO-ALIAS).

## Controllers in autowire-excluded dir (R-GLOB-EXCLUDED-DIR-AUTOWIRE)

If a controller dir is excluded from `App\:` glob (manual defs only), the explicit service entry MUST set `autowire: true, autoconfigure: true`. `_defaults` inheritance is opaque when neighbouring defs override:

```yaml
App\Controller\Shop\<X>Controller:
    autowire: true
    autoconfigure: true
    tags: ['controller.service_arguments']
```

Verify via `bin/console debug:container --show-arguments <FQCN>` - no missing-args runtime failure.

## Doctrine listener registration

See `reference/events.md` for the canonical attribute-only pattern + the forbidden hybrid (attribute + yaml tag) that fires the listener twice per event.
