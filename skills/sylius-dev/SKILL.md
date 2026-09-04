---
name: sylius-dev
description: Build Sylius 2.x features idiomatically. Use for any task that adds or modifies persisted data, frontend UI on Sylius pages, admin CRUD, emails, async work, inventory listeners, back-in-stock notifications, product badges, admin grids, fixtures, migrations, Doctrine listeners, or twig hooks in a Sylius project. Triggers on phrases like "Sylius feature", "Sylius resource", "add to product page", "back-in-stock", "admin grid", "Sylius email", "Sylius listener", "Sylius fixture", "Sylius twig hook", "Sylius migration".
---

# Sylius Feature Build

You are building a feature in a Sylius 2.x project. This file is the brief: mental model, discovery protocol, hard rules. Procedures (build checklist, verify pass, Playwright protocol) live in `workflow.md`; read it before step 2. Never skip the Mate-first protocol or hard rules.

## Mental Model

- **Resource-first.** New persisted thing → register `sylius_resource`. Never plain Doctrine entity for app domain data.
- **Hook-first.** Frontend changes → TwigHook entry. Never `{% extends '@SyliusShop/...' %}` override.
- **Event-first.** React to domain change → Sylius event or domain message. Doctrine `preUpdate` last resort. For inventory mutations use Doctrine `onFlush` UnitOfWork.
- **Factory + Repository, never EntityManager.** Controllers/handlers inject `FactoryInterface` + `RepositoryInterface`. Use `$repository->add($x)`. No `EntityManagerInterface` in controllers.
- **Interfaces, never concretes.** Type-hint `ChannelInterface`, `ProductInterface`, `CustomerInterface`. Never `App\Entity\Channel\Channel`.

## Mate-First Protocol (non-negotiable)

The `sylius_*` tools below are Mate CLI tools — invoke each as `vendor/bin/mate tools:call <tool> --<param>=<value>` (nested values via `--json`). Before writing ANY file, you MUST complete this discovery checklist (Mate tool calls where one exists):

- `sylius_project_profile` - **first call, always.** Returns `app_namespace` (PSR-4 root, never hardcode `App\`), `enabled_locales` (one translation file per entry), `framework_router_default_uri` (R-DEFAULT-URI gate), `symfony_mate_bridge` (see container lookups below). R-NAMESPACE-FROM-COMPOSER + R-MULTI-LOCALE depend on this.
- `sylius_installed_plugins` + `sylius_service_decorators` - what is installed and which `sylius.*` services are already decorated, before designing any listener / inventory checker / price service / channel resolver. R-PLUGIN-AWARENESS.
- `sylius_domain_list_resources` - does target resource exist? Learn shape.
- `sylius_hooks_find_for_template` - for UI placement.
- **Domain event check.** Grep `vendor/sylius/*/src/**/SyliusEvents.php` for an existing event before reaching for a Doctrine listener. No Mate tool for this - these are compile-time constants, not kernel state, so a tool would just wrap the same grep.
- `sylius_twig_list_functions` - verify any `sylius_*` Twig function before use.
- **Mailer code check.** Read `sylius_mailer.emails.*` keys from `config/packages/_sylius_mailer.yaml` to confirm existing mailer code shape. No dedicated Mate list tool; `sylius_mailer_verify_template` confirms the final code+template pair in the verify pass.
- `sylius_domain_list_grids` - mirror existing grid for admin CRUD.
- **Container lookups** - the Symfony Mate bridge (`symfony/ai-symfony-mate-extension`) is optional for this extension; the `sylius/sylius-ai-dev-tools` pack installs it, `sylius_project_profile.symfony_mate_bridge` tells you. With the bridge: `symfony-services --query=<fragment>` (matches id or class), `symfony-service-detail --id=<exact id>` (returns `class`, `tags`, `calls`, `constructor`) - and then never `bin/console debug:container`. Without it: `bin/console debug:container <id> --show-arguments` / `--filter=<fragment>`. Every `symfony-service-detail` mention in this skill means "the container lookup for your setup".

Before declaring DONE, run the verify pass in `workflow.md` §10 (syntax + yaml + twig lint, compile gate + container lookup for **every** new or modified class, schema, routes, messenger, Mate verify tools). Every command output empty/passing. Any failure → STOP, fix, re-run.

The skill ships with the extension, so the `sylius_*` tools are present whenever the skill is. If `vendor/bin/mate tools:list` shows none, the install is broken: tell the user and stop - do not reconstruct project facts from filesystem greps.

## Workflow

Full checklist with tool calls, outputs and per-step verification in `workflow.md`. The map:

1. Discover - profile, plugins + decorators, resources, hooks (visibility check for leaf hooks), domain events, mailer codes, grids.
2. **Resource Bundle (all-or-nothing)** - entity + interface, repo + interface, form extending `AbstractResourceType`, `sylius_resource` yaml, admin grid + route. No custom factory by default (inject `#[Autowire(service: 'app.factory.<alias>')]`); if you add one, its constructor is `__construct(string $className)`. Fixture only for seed data the feature needs; Behat optional.
3. Migration - `doctrine:migrations:diff`, then strip scaffold stubs.
4. Form - `form_start(form, {action: path(...)})` + `form_row` + `form_end`. Never a hand-rolled `<form>` or `_token`.
5. Frontend - TwigHook entry + template.
6. Controller - `final` invokable service; `Factory`, Core-package repo interface, `ChannelContext`, `LocaleContext`. No EM.
7. Listener - Sylius event first; field-watching via Doctrine `onFlush` (collect) + `postFlush` (dispatch).
8. Async handler - `#[AsMessageHandler]`, email via Sylius `SenderInterface` with `channel` + `localeCode` + resource in context.
9. Mailer - `sylius_mailer.emails.app_<code>` + `templates/email/app_<code>.html.twig` extending `@SyliusCore/Email/layout.html.twig`; 9.5 template gate, 9.6 translation gate (exact locale filenames).
10. Verify pass - STOP on any failure.
11. **Playwright acceptance (mandatory)** - repeatable spec, protocol below.

## Hard Rules (refuse if violated)

Details + ✅ replacements in `anti-patterns.md`. Refuse-list:

- ❌ Plain `#[ORM\Entity]` without `sylius_resource` registration.
- ❌ `EntityManagerInterface` injected in controller when Resource exists.
- ❌ `extends AbstractType` for a Sylius resource form (must `extends AbstractResourceType`).
- ❌ Hand-rolled `<input name="...">` mirroring Form Type fields.
- ❌ Concrete `App\Entity\...` type-hint on entity getter/setter or service signature.
- ❌ `{% extends '@SyliusShop/...' %}` template override (use TwigHooks).
- ❌ Hand-written `CREATE TABLE` migration (`doctrine:migrations:diff` only).
- ❌ User-facing resource without admin grid.
- ❌ For Sylius core entities (product, product_variant, channel, customer, order, taxon, shipment, payment, address): injecting bare `Sylius\Resource\Doctrine\Persistence\RepositoryInterface`. Always inject the Core-package interface: `Sylius\Component\Core\Repository\ProductRepositoryInterface`, `…\ChannelRepositoryInterface`, etc. Bare interface = ambiguous binding, runtime resolve fail or wrong service.
- ❌ Hand-rolled `<form method="post" action="...">` open tag. Use `{{ form_start(form, {action: path('...')}) }}`. Never manually render `form._token`.
- ❌ Doctrine `#[ORM\Column]` on camelCase property without explicit `name:` snake_case. Example: `private \DateTimeImmutable $createdAt` → `#[ORM\Column(name: 'created_at', type: 'datetime_immutable')]`.
- ❌ Doctrine `preUpdate` when Sylius event covers the case.
- ❌ Sync mail send loop inside controller/listener (use Messenger async).
- ❌ Catching exceptions never thrown on the path (e.g. `ChannelNotFoundException` after `ChannelContext::getChannel()` always returns).
- ❌ `\DateTime` / `type: 'datetime'`. Use `\DateTimeImmutable` + `type: 'datetime_immutable'`. Property type `\DateTimeImmutable`, not `\DateTimeInterface`.
- ❌ User-scoped subscription/notification resource without persisted `localeCode` (length 16) and `channelCode` or `channel` relation. Mailer must render in the stored locale.
- ❌ Backslashes in Sylius template path strings. Always forward slashes: `'@SyliusAdmin/shared/crud/index.html.twig'`. Never `'@SyliusAdmin\shared\crud'`.
- ❌ **R-FORM-SVC.** Form type extending `AbstractResourceType` without explicit FQCN-keyed yaml service def. Sylius-Standard `_instanceof: AbstractResourceType: { autowire: false }` makes Symfony Form factory fall back to `new <FQCN>()` and die on missing constructor args. `debug:container` looks clean. See `reference/services.md` for the canonical yaml shape.
- ❌ **R-COMP-TPL.** `#[AsTwigComponent]` without explicit `template:` argument. Hook config passing `template:` as a prop binds to a public property - does NOT redirect renderer. UX TwigComponent auto-resolution does not match hook-prop path. Sylius core's `sylius.twig_component` tag uses a custom compiler pass; plain `#[AsTwigComponent]` lacks it. Always: `#[AsTwigComponent('app_x', template: 'shop/.../x.html.twig')]`.
- ❌ **R-FLUSH-ORDER.** Dispatching Messenger / doing downstream writes directly from `onFlush`. UoW state shifts mid-`onFlush`; handler's flush corrupts or no-ops. Split: `onFlush` collects ids/keys into private array, `postFlush` iterates + dispatches. Also forbidden: `register_shutdown_function(...)` from a Doctrine listener (EM gone by then).
- ❌ **R-EMAIL-LAYOUT.** Email template under `sylius_mailer.emails.*` without all of: (1) `{% extends '@SyliusCore/Email/layout.html.twig' %}`, (2) both `{% block subject %}` and `{% block content %}` defined, (3) helper `{% set %}` declared INSIDE the block that uses it (Sylius mailer renders `subject` block standalone - top-level `set` runs only for parent render). Plain HTML or one-block templates fail with "Block subject does not exist".
- ❌ **R-MAILER-CTX.** `SenderInterface::send($code, $recipients, $context)` without `channel` (resolved via `ChannelRepositoryInterface::findOneByCode`) AND `localeCode` (from the persisted resource, not the current request) in `$context`. `@SyliusCore/Email/layout.html.twig` calls `sylius_channel_url(asset(...), channel)` - missing channel = render error.
- ❌ **Channel repo wrong import.** `Sylius\Component\Core\Repository\ChannelRepositoryInterface` **does not exist**. Always use `Sylius\Component\Channel\Repository\ChannelRepositoryInterface`. Wrong import = container compile error.
- ❌ **R-TRANS-LOCALE.** Translation filename using locale shorthand when shop runs a locale variant. If `framework.default_locale` / `sylius_locale` = `en_US`, file MUST be `translations/messages.en_US.yaml`, not `messages.en.yaml`. (Cache rewarm is `sylius_cache_clear`'s job - see Cache Clear.)
- ❌ **R-NO-CUSTOM-FACTORY.** `classes.factory: App\Factory\<X>Factory` registered without `__construct(string $className)` signature. Sylius resource-bundle compiler pass injects entity FQCN string into the factory constructor slot; mismatched signature crashes at first `createNew()` (`TypeError: must be of type FactoryInterface, string given`). `debug:container` clean. Either drop the custom factory (use default Factory) or fix the constructor.
- ❌ **R-ROUTE-PREFIX.** Outer `prefix:` in a `type: sylius.resource` import containing the kebab-cased resource alias plural. The resource loader auto-derives the path segment from the alias plural - including it manually duplicates it, e.g. `/admin/<alias-plural>/<alias-plural>/`. Outer prefix carries ONLY the admin/shop path: `'/%sylius_admin.path_name%'`.
- ❌ **R-LISTENER-CODE-NOT-ID.** Doctrine listener collecting `$entity->getId()` for downstream Messenger dispatch when entity has a stable `code` / UUID. IDs change across env restores, fixture reloads, snapshots. Collect codes; handler does `findOneBy(['code' => $code])`. Exception: entities without natural codes (`Adjustment`, `OrderItemUnit`) - use id, document why.
- ❌ **R-APP-EMAIL-PREFIX.** App-level mailer code without `app_` prefix. Sylius core ships `order_confirmation`, `password_reset_token`, `customer_registration`. A bare app-chosen code risks future collision with a core one. Always: `app_<code>`.
- ❌ **R-MIGRATION-CLEANUP.** Committed migration with scaffold stubs: `/** * Auto-generated Migration: Please modify to your needs! */` class docblock, `// this up() migration is auto-generated, ...` / `// this down() migration is auto-generated, ...` comments, or empty `getDescription()`. After `doctrine:migrations:diff`, strip stubs and fill description with one-line change summary.
- ❌ **R-TWIG-COMPONENT-PATH.** App-level Twig Components at `App\Twig\Components\` (Symfony UX default). Use `App\TwigComponent\<Section>\` to match Sylius bundle convention (`Sylius\Bundle\<X>Bundle\TwigComponent\`).
- ❌ **R-EXCLUDE-EXPLICIT-DIRS.** Writing an explicit service def in a directory NOT on the `<AppNs>\:` glob `exclude` list. Autoregister silently clobbers the explicit def - `arguments` dropped, `tags` dropped. `debug:container` may still show the class (tag from autoconfigure) but wired wrong. Append the dir to `exclude:` in the same write. See `reference/services.md` for the standard exclude block.
- ❌ **R-IMPORTS-SERVICES-DIR violation.** Explicit service defs inlined into `config/services.yaml`. Use `imports: { resource: 'services/' }` + per-feature file under `config/services/app_<feature>.yaml`. `services.yaml` stays stable; feature files isolated.
- ❌ **R-PLAYWRIGHT-NO-RAW-SQL.** Playwright spec triggering changes to an entity observed by a Doctrine listener via raw SQL (`bin/console doctrine:query:sql "UPDATE ..."`). Raw SQL bypasses UnitOfWork → listener never fires → handler never runs → assertion against the side-effect (a DB flag, dispatched message, sent email) fails. Use a CLI command that goes through ORM, an admin UI flow via Playwright, or an API call - see `reference/worked-example.md` for a concrete restock command.
- ❌ **R-HOOK-VISIBILITY.** Selecting a `sylius_twig_hooks` leaf target without confirming the parent template renders it in the feature's target state. Leaf hooks under `*.add_to_cart`, `*.add_to_cart.variants.*` live inside `{% if %}` branches that short-circuit when variant unavailable. For a widget that only makes sense while its precondition doesn't hold (e.g. out-of-stock-only), the `add_to_cart` sub-tree is dead. Use the parent hook (`sylius_shop.product.show.content.info.summary`) that fires unconditionally. Call the Mate tool `sylius_hooks_resolve_for_visibility` with feature visibility state (`oos` / `in_stock` / `always`) to get valid targets - do not guess from hook name suffix.
- ❌ **R-EMAIL-PROOF.** Playwright asserting a handler-written DB flag (e.g. `notifiedAt IS NOT NULL`) as proof of email delivery. Handler reaches end-of-loop even when `null://null` swallows the message - false positive. Acceptable proofs: (1) capture transport (mailpit/mailhog) - scrape `http://localhost:8025/api/v1/messages`, (2) profiler mailer collector - works ONLY when the triggering mutation happened via HTTP. A CLI-triggered mutation bypasses profiler. If neither available, spec prints `// TODO: assert email via mailpit/profiler` rather than passing on a DB-flag check it can't trust.
- ❌ **R-NOT-FOUND-EXCEPTION.** Throwing `\RuntimeException` (or any non-HTTP exception) from controller for resource-not-found. Renders HTTP 500 stack trace to shopper. Always `throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();` → HTTP 404 through Sylius error templates.
- ❌ **R-MAILER-CONFIG-TRANSLATION-KEY.** `sylius_mailer.emails.<code>.subject:` as literal English. Always a translation key: `subject: app.email.<code>.subject`. Template `{% block subject %}` overrides at render time, but config-level key keeps the indirection consistent.
- ❌ **R-FORM-PARENT-BUILDFORM.** Form type extending `AbstractResourceType` whose `buildForm()` does not call `parent::buildForm($builder, $options)` first. Future-proofs against Sylius adding parent behavior.
- ❌ **R-EM-SCOPE.** `EntityManagerInterface` injection in any feature service (controller, handler, listener, helper) when a Resource exists. Use `RepositoryInterface::add($x)` - Sylius `EntityRepository::add()` does persist+flush idempotently for both new and existing managed entities. Exception: bulk operations where DBAL/QueryBuilder beats per-row flush.
- ❌ **R-CACHE-CLEAR.** `bin/console cache:clear` (or `fos:elastica:populate`) run from the shell, at any point. Cache clearing goes through the Mate tool `sylius_cache_clear` only - see Cache Clear.
- ❌ **R-CONTROLLER-INVOKABLE.** `extends AbstractController` in a feature controller. Use `final` invokable services. Inject `FormFactoryInterface` (call `$this->formFactory->create(...)`, not `$this->createForm(...)`), `UrlGeneratorInterface` (`$this->urlGenerator->generate(...)`, not `$this->generateUrl(...)`). Flash via `$request->getSession()->getFlashBag()->add(...)`, not `$this->addFlash(...)`. `App\Controller\` glob w/ `controller.service_arguments` tag covers DI.
- ❌ **R-DOCTRINE-LISTENER-ATTRIBUTE hybrid.** `#[AsDoctrineListener]` (or `#[Autoconfigure(tags:[...])]`) on class AND explicit `doctrine.event_listener` yaml tag together. With `autoconfigure: true`, both registrations apply → listener fires twice per event. Attribute-only is canonical. See `reference/events.md` for accepted patterns + rare yaml-only fallback.
- ❌ **R-FORM-SVC-DUAL.** `app.form.type.<x>` id + FQCN alias dual declaration. Collapse to single FQCN-keyed service. See `reference/services.md`.
- ❌ **R-NO-MANUAL-REPO-ALIAS.** Manual `App\Repository\<X>RepositoryInterface: alias: app.repository.<alias>` for a resource-registered repo. Sylius 2.x resource-bundle compiler pass auto-aliases interface FQCN → `app.repository.<alias>`. Duplicate. Sylius core repo aliases (`Sylius\Component\Core\Repository\ProductVariantRepositoryInterface: alias: sylius.repository.product_variant`) are a different concern - keep those, core repos not auto-aliased.
- ❌ **R-HOOKABLE-METADATA bare.** Hook template referencing bare `variant` / `product` without destructuring from `hookable_metadata.context.*`. Always start the template with:

  ```twig
  {% set variant = hookable_metadata.context.variant ?? null %}
  {% set product = hookable_metadata.context.product ?? null %}
  ```

- ❌ **R-HOOK-COMPONENT-TAG.** Using bare `#[AsTwigComponent]` as a hook `component:` target - Sylius hooks need the `sylius.twig_component` tag + custom compiler pass to bind props from hook config. For stateless widgets prefer `template:` hook + Twig Extension. For Live behavior use `#[AsLiveComponent]` + Sylius tag.
- ❌ **R-URL-IN-EMAIL.** Hand-rolled URL concatenation in email templates (`sylius_channel_url(asset(''), channel) ~ '/products/' ~ ...`) or `localeCode|split('_')[0]` (strips region; Sylius URLs use full locale). Always Twig `url()`:

  ```twig
  <a href="{{ url('sylius_shop_product_show', {slug: product.translation(localeCode).slug, _locale: localeCode}) }}">
  ```

  For Messenger async handlers (no Request context), `framework.router.default_uri` must be set in `messenger.yaml` so `url()` resolves absolute. Sylius-Standard should ship this.
- ❌ **R-REPO-NAMESPACE violation.** Sub-namespacing classes that are pinned FLAT:
  - Sub-namespace OK: `App\Entity\<Feature>\<X>`, `App\Form\Type\<Feature>\<X>Type`.
  - FLAT only: `App\Repository\<X>Repository`, `App\Factory\<X>Factory`, `App\EventListener\<X>Listener`, `App\Message\<X>`, `App\MessageHandler\<X>Handler`.
- ❌ **R-FEATURE-DONE-INCLUDES-ADMIN.** Declaring a persisted-state feature "done" without admin grid + admin route. Verify `sylius_grid.grids.app_admin_<feature>` exists AND `debug:router | grep app_admin_<feature>_index` returns a hit before marking done.
- ❌ **R-NAMESPACE-FROM-COMPOSER.** Hardcoding `App\` in any scaffold. Always read `composer.json` `autoload.psr-4` → derive app root namespace (`App\\`, `Elesto\\`, `Acme\\Shop\\`, etc.). Use first PSR-4 entry pointing at `src/`. Parameterize every scaffold input on it. The Mate tool `sylius_project_profile` returns it as `app_namespace`.
- ❌ **R-PLUGIN-AWARENESS.** Designing inventory / pricing / order / availability / channel logic without first checking what is installed (`sylius_installed_plugins`) and which `sylius.*` service is already decorated (`sylius_service_decorators`). A decorator on the service you are about to hook means the data may live elsewhere - e.g. under `sylius/multi-source-inventory-plugin` stock lives in `InventorySourceStockInterface` rows, not `ProductVariant.onHand`, so a listener watching `onHand` is dead. Read the decorator class when unsure.
- ❌ **R-GLOB-EXCLUDED-DIR-AUTOWIRE.** Manual service def in a dir excluded from `<AppNs>\:` glob without explicit `autowire: true, autoconfigure: true`. `_defaults` inheritance is opaque - explicit beats implicit. See `reference/services.md`. Verify via `symfony-service-detail --id=<FQCN>` - every `constructor` entry resolves.
- ❌ **R-MULTI-LOCALE.** Single `messages.<one_locale>.yaml` when project has multiple enabled locales. Read `sylius_locale.locales` (or `framework.default_locale` fallback) - emit one translation file per enabled locale. The Mate tool `sylius_project_profile` returns the list as `enabled_locales`.
- ❌ **R-DEFAULT-URI.** Feature that generates URLs from Messenger handlers / CLI / non-HTTP contexts without `framework.router.default_uri` set. Detect via `bin/console debug:config framework.router`; if absent, add to feature yaml or `framework.yaml`. Without it, `url()` in email template renders empty/wrong host.

## Event Source Decision

For features watching a field change (a threshold crossing, a status flip):

- **Doctrine `onFlush` + `postFlush`** (Pattern A, attribute-only) - catches all paths: admin grid mutations, API, order-workflow side effects, bulk imports. Preferred for cross-path coverage.
- **Sylius Resource event (`<host>.post_update`)** - admin/API only; order-workflow side effects bypass the Resource controller. Idiomatic for admin-only-mutation features.

Many fields worth watching (stock, order state) live on an existing resource rather than being a resource of their own, and have no dedicated Sylius domain event. Doctrine listener is then the simplest reliable signal - `reference/worked-example.md` walks the stock case end to end.

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

## Playwright Acceptance (mandatory step 11)

End-of-workflow live run. Author a **repeatable spec file** at `tests/Playwright/<feature>.spec.ts` (or the project's configured location) - not exploratory one-shot tool calls - run it via Playwright MCP, refuse "done" until every step is green.

**Coverage rule:** the spec drives ALL observable user paths - setup, the user action, the downstream trigger (through ORM, never raw SQL - R-PLAYWRIGHT-NO-RAW-SQL), any email assertion (R-EMAIL-PROOF: mailpit or profiler, never a DB flag), AND the post-change UI state (e.g. a widget disappearing once its precondition no longer holds). Single-step specs are rejected.

Step-by-step protocol, profiler/mailer lookups and the no-email / no-async variants: `workflow.md` §11. `reference/worked-example.md` walks it against a concrete feature.

## Linked Files

- `workflow.md` - 13-step build checklist with Mate tool calls, the verify-pass script (§10) and the Playwright protocol (§11). Read before step 2.
- `anti-patterns.md` - ❌/✅ pairs per hard rule with "Why" line.
- `reference/resource.md`, `reference/twig-hooks.md`, `reference/mailer.md`, `reference/events.md`, `reference/services.md` - deep dives, fetch on demand.
- `reference/worked-example.md` - one feature (back-in-stock notifications) built end to end, concretely, tagged against the rule IDs above. Every other file in this skill is written generically; this is the file that proves it out on a real case.
