---
name: sylius-resource
description: Register and wire a Sylius 2.x resource the idiomatic way - entity + interface, repository + interface, form type extending AbstractResourceType, sylius_resource yaml, admin grid + CRUD route, migration via doctrine:migrations:diff, optional fixture, invokable controller with Factory + Repository injection. Part of the sylius-dev skill family; for a whole feature start from sylius-dev. Triggers on "Sylius resource", "new entity in Sylius", "admin grid", "admin CRUD", "Sylius form type", "Sylius migration", "Sylius fixture", "Sylius controller".
---

# Sylius Resource

Steps 2-4 and 6 of the `sylius-dev` build map: everything that turns a persisted thing into a Sylius resource with its mandatory kit. Read `sylius-dev` first for discovery and the cross-cutting rules (namespace from composer, interfaces not concretes, no EntityManager, DI file layout). Paths like `sylius-dev/reference/services.md` point at a sibling skill, installed next to this one (directory `<name>` in the extension, `mate-<name>` under `.agents/skills/`).

**Mate tools used here:** `sylius_domain_resource_template`, `sylius_domain_list_grids`, `sylius_resource_inspect`, `sylius_grid_actions_audit`, `sylius_route_inspect`, `sylius_routes_show`, `sylius_services_yaml_profile` / `sylius_services_yaml_audit` / `sylius_services_yaml_patch_exclude`.

## 2. Resource Bundle (all-or-nothing)

**Goal:** Register persisted thing as Sylius resource with mandatory surrounding kit.

**Mate tool calls:**

- `sylius_domain_resource_template` - entity/repo/factory/form scaffold shape, including an admin grid yaml stub (no separate grid-template call needed).
- `sylius_domain_list_grids` - mirror the closest existing grid's fields/filters/actions onto that stub.

**Mandatory output:**

Resource core:

- `src/Entity/<Name>.php` - `class X implements XInterface` with Sylius `*Interface` type-hints on getters/setters. Dates as `\DateTimeImmutable` w/ `#[ORM\Column(name: 'snake_case_name', type: 'datetime_immutable')]`. Every camelCase property → explicit `name:` snake_case on `#[ORM\Column]`. Persist `localeCode` (length 16) and `channelCode` (or `channel` relation) for user-scoped resources.
- `src/Entity/<Name>Interface.php` - `extends ResourceInterface`.
- `src/Repository/<Name>Repository.php` - `extends EntityRepository implements <Name>RepositoryInterface`.
- `src/Repository/<Name>RepositoryInterface.php` - `extends RepositoryInterface`.
- **No custom factory by default.** Omit `classes.factory:` - Sylius default `Sylius\Resource\Factory\Factory` wires automatically. Add `<AppNs>\Factory\<Name>Factory` + interface ONLY when feature needs pre-construction behavior. If added, constructor MUST be `__construct(string $className)` - Sylius compiler pass injects the entity FQCN string into that slot. Mismatched signature crashes at first `createNew()`.
- `src/Form/Type/<Name>Type.php` - **`extends AbstractResourceType`**.
- `config/packages/_sylius.yaml` - `sylius_resource.resources.app.<alias>` entry. Omit `classes.factory:` unless custom factory genuinely needed.

Admin grid:

- `config/packages/_sylius_grid.yaml` - `sylius_grid.grids.app_admin_<alias>` w/ fields, filters, actions.
- `config/routes/admin/<alias>.yaml` - CRUD route group via `type: sylius.resource`. Outer `prefix:` carries ONLY `'/%sylius_admin.path_name%'` - DO NOT append the kebab-cased resource alias plural; the resource loader auto-derives it (R-ROUTE-PREFIX). Including the segment manually yields `/admin/<plural>/<plural>/`.
- Admin menu entry (twig hook or yaml).

**Conditional output:**

Fixture - only if feature genuinely needs new seed data beyond `sylius:fixtures:load` defaults:

- `src/Fixture/<Name>Fixture.php` - `extends AbstractFixture`, `configureOptionsNode`, `load()` via factory + repo.
- `config/packages/_sylius_fixtures.yaml` - suite entry under `default`.

Behat - optional. Playwright acceptance (step 11) is the required acceptance gate. Add Behat only when user requests or feature is pure-domain logic better expressed as Gherkin.

**Verify:**

- `symfony-service-detail --id=app.repository.<alias>` (no bridge: `bin/console debug:container app.repository.<alias>`)
- `symfony-service-detail --id=app.factory.<alias>`
- `bin/console debug:router | grep admin_<alias>`
- `bin/console lint:yaml config/`
- `sylius_resource_inspect --alias=<alias>`

## 3. Migration

**Goal:** Schema change captured.

**Output:** Run `bin/console doctrine:migrations:diff`. Review generated migration. Never hand-write `CREATE TABLE`.

**Cleanup (R-MIGRATION-CLEANUP, mandatory):**

- Remove `/** * Auto-generated Migration: Please modify to your needs! */` class docblock.
- Fill `getDescription()` with one-line table/change summary.
- Remove `// this up() migration is auto-generated, ...` inline comment.
- Remove `// this down() migration is auto-generated, ...` inline comment.

**Verify:** `bin/console doctrine:schema:validate`.

## 4. Form

**Goal:** Form reusable via FormFactory.

**Output:**

- Form Type extends `AbstractResourceType`.
- `buildForm()` calls `parent::buildForm($builder, $options)` first (R-FORM-PARENT-BUILDFORM). Future-proofs against Sylius adding parent behavior.
- `getBlockPrefix()` returns alias.
- Controller or Twig function builds `FormView` via `FormFactoryInterface`.
- **Explicit service registration (R-FORM-SVC + R-IMPORTS-SERVICES-DIR + R-EXCLUDE-EXPLICIT-DIRS).** Sylius-Standard sets `_instanceof: AbstractResourceType: { autowire: false }`. Symfony Form factory then falls back to `new <FQCN>()` and dies on missing constructor args. Always:

  1. Write the def in a per-feature file: `config/services/app_<feature>.yaml`.
  2. Ensure `config/services.yaml` has `imports: [{ resource: 'services/' }]`.
  3. Ensure `<AppNs>\:` `exclude:` contains `'../src/Form/'` (and any other dir holding explicit defs).
  4. Use the **FQCN as service id directly** - single declaration, no alias indirection (R-FORM-SVC collapse):

  ```yaml
  # config/services/app_<feature>.yaml
  services:
      <AppNs>\Form\Type\<Feature>\<X>Type:
          arguments:
              - '<AppNs>\Entity\<Feature>\<X>'
              - ['sylius']
          tags:
              - { name: form.type }
  ```

  Symfony Form factory resolves `createForm(<X>Type::class)` directly via FQCN service id. The old `app.form.type.<x>` + alias dual pattern is dropped - one trap door fewer.

**Verify:**

- `php -l` on form file.
- `symfony-service-detail --id=<AppNs>\Form\Type\<Ns>\<X>Type` - must be found under the explicit FQCN-keyed def, `constructor` args resolved.

## 6. Controller

**Goal:** Invokable controller, no `AbstractController`, no `EntityManager`.

**Output:**

- `final class <Name>Action` with `__invoke(Request)`. **Never `extends AbstractController`** (R-CONTROLLER-INVOKABLE) - use explicit DI:
  - `FormFactoryInterface` → `$this->formFactory->create(...)`, not `$this->createForm(...)`.
  - `UrlGeneratorInterface` → `$this->urlGenerator->generate(...)`, not `$this->generateUrl(...)`.
  - Flash via `$request->getSession()->getFlashBag()->add(...)`, not `$this->addFlash(...)`.
  - `<AppNs>\Controller\` glob in `services.yaml` w/ `controller.service_arguments` tag covers DI.
- If controller dir is excluded from `<AppNs>\:` glob (manual defs only), the explicit service entry MUST set `autowire: true, autoconfigure: true` (R-GLOB-EXCLUDED-DIR-AUTOWIRE) - `_defaults` inheritance is opaque when neighboring defs override:

  ```yaml
  <AppNs>\Controller\Shop\<X>Controller:
      autowire: true
      autoconfigure: true
      tags: ['controller.service_arguments']
  ```

  Verify via `symfony-service-detail --id=<FQCN>` - no missing `constructor` args.
- Inject `FactoryInterface` by service id via Autowire attribute when no custom factory exists: `#[Autowire(service: 'app.factory.<alias>')] private FactoryInterface $factory`. For app resources w/ a custom factory class, inject `<Name>FactoryInterface` instead. Inject repository **Core-package interface** (`Sylius\Component\Core\Repository\ProductRepositoryInterface` etc. for core resources; app-package `<Name>RepositoryInterface` for app resources). Never bare `Sylius\Resource\Doctrine\Persistence\RepositoryInterface` - ambiguous binding, container can't resolve.
- For **Sylius core repos** (Product, ProductVariant, Channel, Customer, Order, etc.) declare aliases in `config/services.yaml` (R-CORE-REPO-ALIASES):

  ```yaml
  Sylius\Component\Core\Repository\ProductVariantRepositoryInterface:
      alias: sylius.repository.product_variant
  Sylius\Component\Channel\Repository\ChannelRepositoryInterface:
      alias: sylius.repository.channel
  ```

  For **app repos** registered via `sylius_resource`: do NOT declare a manual `<AppNs>\Repository\<X>RepositoryInterface: alias: app.repository.<x>` (R-NO-MANUAL-REPO-ALIAS). Sylius 2.x resource-bundle compiler pass auto-aliases interface FQCN → `app.repository.<alias>`. Manual alias = duplicate; verify via `symfony-services --query=<X>RepositoryInterface` if unsure.
- Inject `ChannelContextInterface`, `LocaleContextInterface`.
- Persist via `$repository->add($x)`. Never `$em->persist/flush`. Rule extends to handlers, listeners, helpers - no `EntityManagerInterface` injection anywhere in feature code when a Resource exists. `EntityRepository::add()` does persist+flush idempotently for both new and managed entities. Exception: bulk operations where DBAL/QueryBuilder beats per-row flush.
- Resource-not-found → `throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();` (R-NOT-FOUND-EXCEPTION). Never `\RuntimeException` or other non-HTTP exception (renders HTTP 500 stack trace to shopper).
- Don't catch `ChannelNotFoundException` if `getChannel()` always returns on path.

**Verify:**

- `bin/console debug:router | grep <route>`
- `symfony-service-detail --id=<FQCN>` - every `constructor` entry must resolve to a concrete service. A "no matching" / "multiple" autowiring failure surfaces earlier, when a kernel-booting `sylius_*` tool errors out after `sylius_cache_clear`.
- `sylius_routes_show --name=<route_name>`

## Hard Rules (refuse if violated)

Details + ✅ replacements in `anti-patterns.md`.

- ❌ Plain `#[ORM\Entity]` without `sylius_resource` registration.
- ❌ `extends AbstractType` for a Sylius resource form (must `extends AbstractResourceType`).
- ❌ Hand-rolled `<input name="...">` mirroring Form Type fields.
- ❌ Hand-written `CREATE TABLE` migration (`doctrine:migrations:diff` only).
- ❌ User-facing resource without admin grid.
- ❌ Hand-rolled `<form method="post" action="...">` open tag. Use `{{ form_start(form, {action: path('...')}) }}`. Never manually render `form._token`.
- ❌ Doctrine `#[ORM\Column]` on camelCase property without explicit `name:` snake_case. Example: `private \DateTimeImmutable $createdAt` → `#[ORM\Column(name: 'created_at', type: 'datetime_immutable')]`.
- ❌ Catching exceptions never thrown on the path (e.g. `ChannelNotFoundException` after `ChannelContext::getChannel()` always returns).
- ❌ `\DateTime` / `type: 'datetime'`. Use `\DateTimeImmutable` + `type: 'datetime_immutable'`. Property type `\DateTimeImmutable`, not `\DateTimeInterface`.
- ❌ User-scoped subscription/notification resource without persisted `localeCode` (length 16) and `channelCode` or `channel` relation. Mailer must render in the stored locale.
- ❌ **R-FORM-SVC.** Form type extending `AbstractResourceType` without explicit FQCN-keyed yaml service def. Sylius-Standard `_instanceof: AbstractResourceType: { autowire: false }` makes Symfony Form factory fall back to `new <FQCN>()` and die on missing constructor args. `debug:container` looks clean. See `sylius-dev/reference/services.md` for the canonical yaml shape.
- ❌ **R-NO-CUSTOM-FACTORY.** `classes.factory: App\Factory\<X>Factory` registered without `__construct(string $className)` signature. Sylius resource-bundle compiler pass injects entity FQCN string into the factory constructor slot; mismatched signature crashes at first `createNew()` (`TypeError: must be of type FactoryInterface, string given`). `debug:container` clean. Either drop the custom factory (use default Factory) or fix the constructor.
- ❌ **R-ROUTE-PREFIX.** Outer `prefix:` in a `type: sylius.resource` import containing the kebab-cased resource alias plural. The resource loader auto-derives the path segment from the alias plural - including it manually duplicates it, e.g. `/admin/<alias-plural>/<alias-plural>/`. Outer prefix carries ONLY the admin/shop path: `'/%sylius_admin.path_name%'`.
- ❌ **R-MIGRATION-CLEANUP.** Committed migration with scaffold stubs: `/** * Auto-generated Migration: Please modify to your needs! */` class docblock, `// this up() migration is auto-generated, ...` / `// this down() migration is auto-generated, ...` comments, or empty `getDescription()`. After `doctrine:migrations:diff`, strip stubs and fill description with one-line change summary.
- ❌ **R-NOT-FOUND-EXCEPTION.** Throwing `\RuntimeException` (or any non-HTTP exception) from controller for resource-not-found. Renders HTTP 500 stack trace to shopper. Always `throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();` → HTTP 404 through Sylius error templates.
- ❌ **R-FORM-PARENT-BUILDFORM.** Form type extending `AbstractResourceType` whose `buildForm()` does not call `parent::buildForm($builder, $options)` first. Future-proofs against Sylius adding parent behavior.
- ❌ **R-CONTROLLER-INVOKABLE.** `extends AbstractController` in a feature controller. Use `final` invokable services. Inject `FormFactoryInterface` (call `$this->formFactory->create(...)`, not `$this->createForm(...)`), `UrlGeneratorInterface` (`$this->urlGenerator->generate(...)`, not `$this->generateUrl(...)`). Flash via `$request->getSession()->getFlashBag()->add(...)`, not `$this->addFlash(...)`. `App\Controller\` glob w/ `controller.service_arguments` tag covers DI.
- ❌ **R-FORM-SVC-DUAL.** `app.form.type.<x>` id + FQCN alias dual declaration. Collapse to single FQCN-keyed service. See `sylius-dev/reference/services.md`.
- ❌ **R-NO-MANUAL-REPO-ALIAS.** Manual `App\Repository\<X>RepositoryInterface: alias: app.repository.<alias>` for a resource-registered repo. Sylius 2.x resource-bundle compiler pass auto-aliases interface FQCN → `app.repository.<alias>`. Duplicate. Sylius core repo aliases (`Sylius\Component\Core\Repository\ProductVariantRepositoryInterface: alias: sylius.repository.product_variant`) are a different concern - keep those, core repos not auto-aliased.
- ❌ **R-FEATURE-DONE-INCLUDES-ADMIN.** Declaring a persisted-state feature "done" without admin grid + admin route. Verify `sylius_grid.grids.app_admin_<feature>` exists AND `debug:router | grep app_admin_<feature>_index` returns a hit before marking done.

## Linked Files

- `reference.md` - the resource pattern: required pieces, registration, what you get for free, translatable resources.
- `anti-patterns.md` - ❌/✅ pairs for the rules above.
- `sylius-dev/reference/services.md` - yaml shapes for form-type registration, `exclude:` list, core repository aliases.
