---
name: sylius-resource
description: Register and wire a Sylius 2.x resource the idiomatic way - entity + interface, repository + interface, form type extending AbstractResourceType, sylius_resource yaml, admin grid + CRUD route, migration via doctrine:migrations:diff, optional fixture, invokable controller with Factory + Repository injection. Part of the sylius-dev skill family; for a whole feature start from sylius-dev. Triggers on "Sylius resource", "new entity in Sylius", "admin grid", "admin CRUD", "Sylius form type", "Sylius migration", "Sylius fixture", "Sylius controller".
---

# Sylius Resource

Steps 2-4 and 6 of the `sylius-dev` build map: everything that turns a persisted thing into a Sylius resource with its mandatory kit. Read `sylius-dev` first for discovery and the cross-cutting rules (namespace from composer, interfaces not concretes, no EntityManager, DI file layout). Paths like `sylius-dev/reference/services.md` point at a sibling skill, installed next to this one (directory `<name>` in the extension, `mate-<name>` under `.agents/skills/`).

**Mate tools used here:** `sylius_domain_resource_template`, `sylius_domain_list_grids`, `sylius_resource_inspect`, `sylius_grid_actions_audit`, `sylius_route_inspect`, `sylius_routes_show`, `sylius_services_yaml_profile` / `sylius_services_yaml_audit` / `sylius_services_yaml_patch_exclude`.

## Build (steps 2-4, 6)

Full procedure - tool calls, mandatory outputs, per-step verification - in `workflow.md`. Read it before writing files. In short:

- **2. Resource bundle, all-or-nothing:** entity + interface, repo + interface, form extending `AbstractResourceType`, `sylius_resource.resources.app.<alias>`, admin grid + CRUD route (outer prefix `'/%sylius_admin.path_name%'` only), menu entry. No custom factory unless pre-construction behavior is needed. Fixture only for seed data the feature needs; Behat optional.
- **3. Migration:** `doctrine:migrations:diff`, then strip the scaffold stubs and fill `getDescription()`.
- **4. Form:** `parent::buildForm()` first, `getBlockPrefix()` = alias, explicit FQCN-keyed service in `config/services/app_<feature>.yaml` with `src/Form/` on the `exclude:` list.
- **6. Controller:** `final` invokable, explicit DI (`FormFactoryInterface`, `UrlGeneratorInterface`, `ChannelContextInterface`, `LocaleContextInterface`), factory via `#[Autowire(service: 'app.factory.<alias>')]`, Core-package repository interfaces with aliases, `$repository->add()`, `NotFoundHttpException` for not-found.

## Hard Rules (refuse if violated)

Rationale and ✅ replacements in `anti-patterns.md`.

- ❌ Plain `#[ORM\Entity]` without `sylius_resource` registration.
- ❌ `extends AbstractType` for a Sylius resource form → `extends AbstractResourceType`.
- ❌ Hand-rolled `<input name="...">` mirroring Form Type fields → `form_row`.
- ❌ Hand-written `CREATE TABLE` migration → `doctrine:migrations:diff` only.
- ❌ User-facing resource without admin grid.
- ❌ Hand-rolled `<form method="post">` open tag or manual `form._token` → `form_start(form, {action: path(...)})` / `form_end(form)`.
- ❌ `#[ORM\Column]` on a camelCase property without explicit snake_case `name:`.
- ❌ Catching exceptions never thrown on the path (e.g. `ChannelNotFoundException` after `ChannelContext::getChannel()` always returns).
- ❌ `\DateTime` / `type: 'datetime'` → `\DateTimeImmutable` + `type: 'datetime_immutable'`.
- ❌ User-scoped subscription/notification resource without persisted `localeCode` (length 16) and `channelCode` or `channel` relation → the mailer must render in the stored locale.
- ❌ **R-FORM-SVC.** `AbstractResourceType` form without an explicit FQCN-keyed yaml service def → Sylius-Standard sets `autowire: false` for them, Symfony falls back to `new <FQCN>()` and dies; shape in `sylius-dev/reference/services.md`.
- ❌ **R-FORM-SVC-DUAL.** `app.form.type.<x>` id + FQCN alias for one form type → single FQCN-keyed service.
- ❌ **R-FORM-PARENT-BUILDFORM.** `buildForm()` in an `AbstractResourceType` form not calling `parent::buildForm($builder, $options)` first.
- ❌ **R-NO-CUSTOM-FACTORY.** `classes.factory:` pointing at a factory whose constructor is not `__construct(string $className)` → drop the custom factory or fix the signature; Sylius injects the entity FQCN there.
- ❌ **R-NO-MANUAL-REPO-ALIAS.** Manual `<X>RepositoryInterface: alias: app.repository.<alias>` for a resource-registered repo → the resource bundle auto-aliases it; core repo aliases (R-CORE-REPO-ALIASES) are a different concern and stay.
- ❌ **R-ROUTE-PREFIX.** Outer `prefix:` of a `type: sylius.resource` import containing the alias plural → `'/%sylius_admin.path_name%'` only; the loader adds the segment (else `/admin/<plural>/<plural>/`).
- ❌ **R-MIGRATION-CLEANUP.** Committed migration with scaffold stubs (auto-generated docblock and comments, empty `getDescription()`) → strip them, describe the change.
- ❌ **R-CONTROLLER-INVOKABLE.** `extends AbstractController` in a feature controller → `final` invokable service with `FormFactoryInterface`, `UrlGeneratorInterface`, flash via the session.
- ❌ **R-NOT-FOUND-EXCEPTION.** `\RuntimeException` from a controller for not-found → `NotFoundHttpException`.
- ❌ **R-FEATURE-DONE-INCLUDES-ADMIN.** Persisted-state feature declared done without `sylius_grid.grids.app_admin_<feature>` and a hit for `debug:router | grep app_admin_<feature>_index`.

## Linked Files

- `workflow.md` - the build steps above in full: tool calls, outputs, per-step verification.
- `reference.md` - the resource pattern: required pieces, registration, what you get for free, translatable resources.
- `anti-patterns.md` - ❌/✅ pairs for the rules above.
- `sylius-dev/reference/services.md` - yaml shapes for form-type registration, `exclude:` list, core repository aliases.
