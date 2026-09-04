---
name: sylius-dev
description: Build Sylius 2.x features idiomatically - the entry point for any task that adds or modifies persisted data, admin CRUD, frontend UI on Sylius pages, emails, async work, Doctrine listeners, twig hooks, fixtures or migrations in a Sylius project. Holds the mental model, the Mate discovery protocol and the cross-cutting rules; hands the domain work to the sibling skills sylius-resource, sylius-frontend, sylius-events, sylius-mailer and sylius-verify. Triggers on phrases like "Sylius feature", "Sylius resource", "add to product page", "back-in-stock", "admin grid", "Sylius email", "Sylius listener", "Sylius fixture", "Sylius twig hook", "Sylius migration".
---

# Sylius Feature Build

You are building a feature in a Sylius 2.x project. This skill is the brief: mental model, discovery protocol, cross-cutting hard rules and the map of the build. The domain work lives in sibling skills - load the ones your task touches, in build order:

| Step | Skill | Covers |
|---|---|---|
| 1 | `sylius-dev` (this file) | discover: profile, plugins + decorators, resources, hooks, events, mailer codes, grids |
| 2-4, 6 | `sylius-resource` | entity + repo + form + `sylius_resource` yaml, admin grid + route, migration, fixture, controller |
| 5 | `sylius-frontend` | twig hooks, hook templates, twig components |
| 7-8 | `sylius-events` | Sylius events, Doctrine `onFlush`/`postFlush` listeners, Messenger handlers |
| 9 | `sylius-mailer` | `sylius_mailer` config, email templates, send context, translations + locales |
| 10-11 | `sylius-verify` | verify pass, cache clear, Playwright acceptance - the definition of DONE |

Step numbers are shared across the skills. A whole feature walks every row; a narrow task ("add an email", "move this widget to another hook") loads this file plus the one row it needs. Paths like `sylius-dev/reference/services.md` point at a sibling skill, installed next to this one (directory `<name>` in the extension, `mate-<name>` under `.agents/skills/`).

## Mental Model

- **Resource-first.** New persisted thing → register `sylius_resource`. Never plain Doctrine entity for app domain data.
- **Hook-first.** Frontend changes → TwigHook entry. Never `{% extends '@SyliusShop/...' %}` override.
- **Event-first.** React to domain change → Sylius event or domain message. Doctrine `preUpdate` last resort. For inventory mutations use Doctrine `onFlush` UnitOfWork.
- **Factory + Repository, never EntityManager.** Controllers/handlers inject `FactoryInterface` + `RepositoryInterface`. Use `$repository->add($x)`. No `EntityManagerInterface` in controllers.
- **Interfaces, never concretes.** Type-hint `ChannelInterface`, `ProductInterface`, `CustomerInterface`. Never `App\Entity\Channel\Channel`.

## Mate-First Protocol (non-negotiable)

The `sylius_*` tools are Mate CLI tools - invoke each as `vendor/bin/mate tools:call <tool> --<param>=<value>` (nested values via `--json`). Before writing ANY file, complete this discovery checklist (Mate tool call where one exists):

- `sylius_project_profile` - **first**. Returns `app_namespace` (PSR-4 root from composer.json; never assume `<AppNs>\`), `enabled_locales` (emit one translation file per), `framework_router_default_uri` (R-DEFAULT-URI gate), `symfony_mate_bridge` (container lookup - see below).
- `sylius_installed_plugins` + `sylius_service_decorators` - what is installed and which `sylius.*` services are decorated. A decorator on the service you plan to hook means the data may live elsewhere (MSI moves stock to `InventorySourceStockInterface`). Adjust listener / query target accordingly (R-PLUGIN-AWARENESS).
- `sylius_domain_list_resources` - does target resource already exist?
- `sylius_hooks_find_for_template` - which TwigHook entry slot fits the UI change?
- `sylius_hooks_resolve_for_visibility` - given feature visibility state (`oos` / `in_stock` / `always` / `logged_in_only`), returns hook targets whose parent template renders in that state. **Mandatory** before selecting a leaf hook (R-HOOK-VISIBILITY). Leaf hooks like `*.add_to_cart.*` live inside `{% if %}` branches that short-circuit when variant unavailable; for an out-of-stock-only feature they are dead.
- **Domain event check.** Grep `vendor/sylius/*/src/**/SyliusEvents.php` for an existing event covering the trigger. No Mate tool for this - these are compile-time constants, not kernel state.
- **Mailer code check.** Read `sylius_mailer.emails.*` keys from `config/packages/_sylius_mailer.yaml` for existing mailer codes. No dedicated Mate list tool; `sylius_mailer_verify_template` confirms the final code+template pair later (step 9.5).
- `sylius_domain_list_grids` - closest existing grid to mirror.
- **Container lookups** - the Symfony Mate bridge (`symfony/ai-symfony-mate-extension`) is optional for this extension; the `sylius/sylius-ai-dev-tools` pack installs it, `sylius_project_profile.symfony_mate_bridge` tells you. With the bridge: `symfony-services --query=<fragment>` (matches id or class), `symfony-service-detail --id=<exact id>` (returns `class`, `tags`, `calls`, `constructor`) - and then never `bin/console debug:container`. Without it: `bin/console debug:container <id> --show-arguments` / `--filter=<fragment>`. Every `symfony-service-detail` mention in this skill means "the container lookup for your setup".

Note the choices (hook name, event class, mailer code, grid to mirror) before moving on.

The skill ships with the extension, so the `sylius_*` tools are present whenever the skill is. If `vendor/bin/mate tools:list` shows none, the install is broken: tell the user and stop - do not reconstruct project facts from filesystem greps.

## Hard Rules (cross-cutting, refuse if violated)

These apply to every step. Each domain skill carries its own refuse-list on top. Details + ✅ replacements in `anti-patterns.md`.

- ❌ Concrete `App\Entity\...` type-hint on entity getter/setter or service signature.
- ❌ For Sylius core entities (product, product_variant, channel, customer, order, taxon, shipment, payment, address): injecting bare `Sylius\Resource\Doctrine\Persistence\RepositoryInterface`. Always inject the Core-package interface: `Sylius\Component\Core\Repository\ProductRepositoryInterface`, `…\ChannelRepositoryInterface`, etc. Bare interface = ambiguous binding, runtime resolve fail or wrong service.
- ❌ **Channel repo wrong import.** `Sylius\Component\Core\Repository\ChannelRepositoryInterface` **does not exist**. Always use `Sylius\Component\Channel\Repository\ChannelRepositoryInterface`. Wrong import = container compile error.
- ❌ **R-EXCLUDE-EXPLICIT-DIRS.** Writing an explicit service def in a directory NOT on the `<AppNs>\:` glob `exclude` list. Autoregister silently clobbers the explicit def - `arguments` dropped, `tags` dropped. `debug:container` may still show the class (tag from autoconfigure) but wired wrong. Append the dir to `exclude:` in the same write. See `reference/services.md` for the standard exclude block.
- ❌ **R-IMPORTS-SERVICES-DIR violation.** Explicit service defs inlined into `config/services.yaml`. Use `imports: { resource: 'services/' }` + per-feature file under `config/services/app_<feature>.yaml`. `services.yaml` stays stable; feature files isolated.
- ❌ **R-EM-SCOPE.** `EntityManagerInterface` injection in any feature service (controller, handler, listener, helper) when a Resource exists. Use `RepositoryInterface::add($x)` - Sylius `EntityRepository::add()` does persist+flush idempotently for both new and existing managed entities. Exception: bulk operations where DBAL/QueryBuilder beats per-row flush.
- ❌ **R-CACHE-CLEAR.** `bin/console cache:clear` (or `fos:elastica:populate`) run from the shell, at any point. Cache clearing goes through the Mate tool `sylius_cache_clear` only - see Cache Clear.
- ❌ **R-REPO-NAMESPACE violation.** Sub-namespacing classes that are pinned FLAT:
  - Sub-namespace OK: `App\Entity\<Feature>\<X>`, `App\Form\Type\<Feature>\<X>Type`.
  - FLAT only: `App\Repository\<X>Repository`, `App\Factory\<X>Factory`, `App\EventListener\<X>Listener`, `App\Message\<X>`, `App\MessageHandler\<X>Handler`.
- ❌ **R-NAMESPACE-FROM-COMPOSER.** Hardcoding `App\` in any scaffold. Always read `composer.json` `autoload.psr-4` → derive app root namespace (`App\\`, `Elesto\\`, `Acme\\Shop\\`, etc.). Use first PSR-4 entry pointing at `src/`. Parameterize every scaffold input on it. The Mate tool `sylius_project_profile` returns it as `app_namespace`.
- ❌ **R-PLUGIN-AWARENESS.** Designing inventory / pricing / order / availability / channel logic without first checking what is installed (`sylius_installed_plugins`) and which `sylius.*` service is already decorated (`sylius_service_decorators`). A decorator on the service you are about to hook means the data may live elsewhere - e.g. under `sylius/multi-source-inventory-plugin` stock lives in `InventorySourceStockInterface` rows, not `ProductVariant.onHand`, so a listener watching `onHand` is dead. Read the decorator class when unsure.
- ❌ **R-GLOB-EXCLUDED-DIR-AUTOWIRE.** Manual service def in a dir excluded from `<AppNs>\:` glob without explicit `autowire: true, autoconfigure: true`. `_defaults` inheritance is opaque - explicit beats implicit. See `reference/services.md`. Verify via `symfony-service-detail --id=<FQCN>` - every `constructor` entry resolves.

## Core Repo Aliases (R-CORE-REPO-ALIASES)

When injecting a Sylius core repository FQCN interface, ensure `config/services.yaml` declares aliases to the canonical repository services. Add the aliases relevant to the feature, idempotent.

Full yaml block + notes (incl. `Sylius\Component\Channel\Repository\ChannelRepositoryInterface` lives in `Channel` component, NOT `Core`) in `reference/services.md`.

## Cache Clear

Owned by the Mate tool `sylius_cache_clear` (PHP-native, no shell): call it once before the verify pass and once before Playwright. Never `bin/console cache:clear` from the shell - host projects commonly forbid it and agent harnesses may block it. Never run `fos:elastica:populate` automatically; tell the user.

## Code Style

Sylius-idiomatic style only - not personal preferences. Match the host project's coding standard (`vendor/bin/ecs` / `php-cs-fixer` if configured) for everything else.

- snake_case for Twig variables (Symfony / Sylius convention).
- Pass `template:` explicitly to Twig hooks and Twig components. Do not rely on Symfony UX auto-template paths - Sylius hooks need explicit paths (R-COMP-TPL).
- Form types extending `AbstractResourceType` must be registered via explicit yaml service def (R-FORM-SVC) - Sylius-Standard sets `_instanceof: AbstractResourceType: { autowire: false }`.

## Definition of DONE

A feature is done when the `sylius-verify` skill says so: verify pass green for every new or modified class, and a repeatable Playwright spec covering every observable user path passes. Never report completion before both.

## Linked Files

- `anti-patterns.md` - ❌/✅ pairs for the cross-cutting rules above.
- `reference/services.md` - DI shapes: per-feature service files, `exclude:` list, core repository aliases, form-type registration, listener registration.
- `reference/worked-example.md` - one feature (back-in-stock notifications) built end to end across all six skills, tagged with the rule IDs. Every other file is generic; this one proves it on a real case.
- Sibling skills: `sylius-resource`, `sylius-frontend`, `sylius-events`, `sylius-mailer`, `sylius-verify`.
