---
name: sylius-dev
description: Build Sylius 2.x features idiomatically. Use for any task that adds or modifies persisted data, frontend UI on Sylius pages, admin CRUD, emails, async work, inventory listeners, back-in-stock notifications, product badges, admin grids, fixtures, migrations, Doctrine listeners, or twig hooks in a Sylius project. Triggers on phrases like "Sylius feature", "Sylius resource", "add to product page", "back-in-stock", "admin grid", "Sylius email", "Sylius listener", "Sylius fixture", "Sylius twig hook", "Sylius migration".
---

# Sylius Vibe Feature

You are building a feature in a Sylius 2.x project. Follow this brief. Never skip the MCP-first protocol or hard rules.

## Mental Model

- **Resource-first.** New persisted thing → register `sylius_resource`. Never plain Doctrine entity for app domain data.
- **Hook-first.** Frontend changes → TwigHook entry. Never `{% extends '@SyliusShop/...' %}` override.
- **Event-first.** React to domain change → Sylius event or domain message. Doctrine `preUpdate` last resort. For inventory mutations use Doctrine `onFlush` UnitOfWork.
- **Factory + Repository, never EntityManager.** Controllers/handlers inject `FactoryInterface` + `RepositoryInterface`. Use `$repository->add($x)`. No `EntityManagerInterface` in controllers.
- **Interfaces, never concretes.** Type-hint `ChannelInterface`, `ProductInterface`, `CustomerInterface`. Never `App\Entity\Channel\Channel`.

## MCP-First Protocol (non-negotiable)

Before writing ANY file, you MUST complete this discovery checklist (MCP calls where one exists):

- `sylius_project_profile` - **first call, always.** Returns `app_namespace` (PSR-4 root, never hardcode `App\`), `locales` (enabled list for translation file emission), and feature flags. R-NAMESPACE-FROM-COMPOSER + R-MULTI-LOCALE depend on this.
- `sylius_installed_plugins` - inventory installed Sylius plugins (MSI, wishlist, refund, multi-currency, b2b) before designing any listener / inventory checker / price service / channel resolver. R-PLUGIN-AWARENESS.
- `sylius_domain_list_resources` - does target resource exist? Learn shape.
- `sylius_hooks_find_for_template` - for UI placement.
- **Domain event check.** Grep `vendor/sylius/*/src/**/SyliusEvents.php` for an existing event before reaching for a Doctrine listener. No MCP tool for this - these are compile-time constants, not kernel state, so a tool would just wrap the same grep.
- `sylius_twig_list_functions` - verify any `sylius_*` Twig function before use.
- **Mailer code check.** Read `sylius_mailer.emails.*` keys from `config/packages/_sylius_mailer.yaml` to confirm existing mailer code shape. No dedicated MCP list tool; `sylius_mailer_verify_template` confirms the final code+template pair in the verify pass.
- `sylius_domain_list_grids` - mirror existing grid for admin CRUD.

Before declaring DONE, you MUST execute this verify script. Every command output empty/passing. Any failure → STOP, fix, re-run. Do not report task complete with non-empty error output.

```bash
# 1. PHP syntax - every touched file
for f in <touched_php_files>; do php -l "$f" || exit 1; done

# 2. YAML lint - config dirs touched
bin/console lint:yaml config/ --parse-tags

# 3. Twig lint - every touched template + email dir
bin/console lint:twig templates/

# 4. Container - services resolve, no ambiguous bindings
bin/console debug:container app.repository.<alias>
bin/console debug:container app.factory.<alias>
# MANDATORY: run for EVERY class added or modified.
# Output must show concrete services, NOT "no matching" or "multiple".
# Catches: bare RepositoryInterface, missing aliases, AbstractResourceType form types
# silently un-registered, autowire=false instanceof traps.
for fqcn in <every_new_or_modified_FQCN>; do
    bin/console debug:container --show-arguments "$fqcn" || exit 1
done

# 5. Schema - mapping consistent
bin/console doctrine:schema:validate --skip-sync

# 6. Routes - every new route resolves
bin/console debug:router | grep <route_name>

# 7. Messenger - handler registered (if async)
bin/console messenger:debug | grep <MessageClass>

# 8. MCP verify (mandatory):
#    sylius_resource_inspect <alias>          - every new resource
#    sylius_routes_show <route_name>          - every new route
#    sylius_mailer_verify_template <code>     - every new email code
#    sylius_hooks_find_for_template <path>    - confirm hook still resolves
```

If the [Sylius Mate Extension](https://github.com/Sylius/sylius-mate-extension) MCP is not loaded:

1. Tell user once: "Install `sylius/mate-extension` for full guidance. Continuing best-effort."
2. Fall back to filesystem reads:
   - `sylius_project_profile` → read `composer.json` (`autoload.psr-4` first entry pointing at `src/` → app namespace) + `config/packages/_sylius.yaml` (`sylius_locale.locales`) + `.env` (`APP_DEFAULT_URI`).
   - `sylius_installed_plugins` → grep `composer.json` `require` for `sylius/*-plugin` entries.
   - `sylius_domain_list_resources` → grep `config/packages/_sylius.yaml` for `sylius_resource.resources.*` keys.
   - `sylius_hooks_find_for_template` → grep `vendor/sylius/sylius/src/Sylius/Bundle/{Shop,Admin}Bundle/Resources/views/` for `{% hook %}` tags.
   - `sylius_twig_list_functions` → grep `vendor/sylius/*/src/**/Twig/*Extension.php` for `new TwigFunction(...)`.
   - `sylius_domain_list_grids` → grep `config/packages/_sylius_grid.yaml` + `vendor/sylius/*/Resources/config/grids/`.
3. Mark every fact derived this way with `⚠ unverified` so the user knows to double-check.

## Workflow

Full checklist in `workflow.md`. Summary:

1. Discover (list_resources, find_for_template, grep domain events).
2. **Resource Bundle (all-or-nothing).** Single phase, mandatory artifacts ship together:
   - entity + interface, repo + interface
   - **No custom factory** by default. Sylius default `Sylius\Resource\Factory\Factory` wires automatically when `classes.factory:` is omitted. When a controller / handler needs the factory instance, inject by service id via Autowire attribute, not via a custom interface: `#[Autowire(service: 'app.factory.<alias>')] private FactoryInterface $factory`. Add a custom factory CLASS only when feature needs pre-construction behavior (set defaults, attach related entity). If you do add one, constructor MUST be `__construct(string $className)` - Sylius compiler pass injects the entity FQCN string into that slot.
   - form extending `AbstractResourceType`
   - `sylius_resource` yaml registration (omit `classes.factory:` for plain resources)
   - `sylius_grid` admin grid yaml + admin route

   Conditional artifacts:
   - Fixture - only if feature needs new seed data beyond `sylius:fixtures:load` defaults. Otherwise skip.
   - Behat scenario - optional; Playwright acceptance (step 11) is the required acceptance gate. Add Behat only when user requests or feature has pure-domain logic better expressed as Gherkin.

3. `bin/console doctrine:migrations:diff` - never hand-write migration.
4. Form rendered via `form_start(form, {action: path(...)})` + `form_row` + `form_end(form)`. Never hand-roll `<form>` tag. Never manually render `form._token`. Inject form via controller or Twig function.
5. Frontend = TwigHook entry + template.
6. Controller invokable. Inject `Factory`, Core-package repo interface, `ChannelContext`, `LocaleContext`. No EM.
7. Listener via Sylius event; for inventory use Doctrine `onFlush` (collect) + `postFlush` (dispatch). Never dispatch from `onFlush`. Never `register_shutdown_function` from listener.
8. Async handler `#[AsMessageHandler]` + Sylius `SenderInterface` for email. Send context MUST include `channel` + `localeCode` + resource.
9. Mailer config under `sylius_mailer.emails.<code>` + template `templates/email/<code>.html.twig` extending `@SyliusCore/Email/layout.html.twig` with `subject` + `content` blocks.
9.5. **Email template gate.** If feature dispatches email: scaffold template from skeleton (extends layout, two blocks, helper `set` inside block). Verify mailer-config `template:` path matches file path.
9.6. **Translation gate.** Any new `translations/messages.*.yaml` filename MUST match exact shop locale (e.g. `en_US`, not `en`). Cache rewarm is handled by MCP `sylius_cache_clear` or project setup, not by this skill.
10. Verify pass - run the verify script above. STOP on any failure.
11. **Playwright acceptance (mandatory).** Author a repeatable spec file under `tests/Playwright/<feature>.spec.ts`, run it via Playwright MCP, refuse "done" if spec fails. No exploratory one-shot calls. Protocol below.

Never run `bin/console cache:clear` automatically - ask user (CLAUDE.md rule). Never run `fos:elastica:populate` automatically.

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
- ❌ **R-TRANS-LOCALE.** Translation filename using locale shorthand when shop runs a locale variant. If `framework.default_locale` / `sylius_locale` = `en_US`, file MUST be `translations/messages.en_US.yaml`, not `messages.en.yaml`. (Pre-Playwright `cache:clear` - see R-CACHE-CLEAR-PRE-VERIFY - handles cache rewarm; no separate user reminder needed.)
- ❌ **R-NO-CUSTOM-FACTORY (legacy code).** `classes.factory: App\Factory\<X>Factory` registered without `__construct(string $className)` signature. Sylius resource-bundle compiler pass injects entity FQCN string into the factory constructor slot; mismatched signature crashes at first `createNew()` (`TypeError: must be of type FactoryInterface, string given`). `debug:container` clean. Either drop the custom factory (use default Factory) or fix the constructor.
- ❌ **R-ROUTE-PREFIX.** Outer `prefix:` in a `type: sylius.resource` import containing the kebab-cased resource alias plural. The resource loader auto-derives the path segment from the alias plural - including it manually duplicates: `/admin/back-in-stock-notifications/back-in-stock-notifications/`. Outer prefix carries ONLY the admin/shop path: `'/%sylius_admin.path_name%'`.
- ❌ **R-LISTENER-CODE-NOT-ID.** Doctrine listener collecting `$entity->getId()` for downstream Messenger dispatch when entity has a stable `code` / UUID. IDs change across env restores, fixture reloads, snapshots. Collect codes; handler does `findOneBy(['code' => $code])`. Exception: entities without natural codes (`Adjustment`, `OrderItemUnit`) - use id, document why.
- ❌ **R-APP-EMAIL-PREFIX.** App-level mailer code without `app_` prefix. Sylius core ships `order_confirmation`, `password_reset_token`, `customer_registration`. Bare `back_in_stock` risks future collision. Always: `app_back_in_stock`.
- ❌ **R-MIGRATION-CLEANUP.** Committed migration with scaffold stubs: `/** * Auto-generated Migration: Please modify to your needs! */` class docblock, `// this up() migration is auto-generated, ...` / `// this down() migration is auto-generated, ...` comments, or empty `getDescription()`. After `doctrine:migrations:diff`, strip stubs and fill description with one-line change summary.
- ❌ **R-TWIG-COMPONENT-PATH.** App-level Twig Components at `App\Twig\Components\` (Symfony UX default). Use `App\TwigComponent\<Section>\` to match Sylius bundle convention (`Sylius\Bundle\<X>Bundle\TwigComponent\`).
- ❌ **R-EXCLUDE-EXPLICIT-DIRS.** Writing an explicit service def in a directory NOT on the `<AppNs>\:` glob `exclude` list. Autoregister silently clobbers the explicit def - `arguments` dropped, `tags` dropped. `debug:container` may still show the class (tag from autoconfigure) but wired wrong. Append the dir to `exclude:` in the same write. See `reference/services.md` for the standard exclude block.
- ❌ **R-IMPORTS-SERVICES-DIR violation.** Explicit service defs inlined into `config/services.yaml`. Use `imports: { resource: 'services/' }` + per-feature file under `config/services/app_<feature>.yaml`. `services.yaml` stays stable; feature files isolated.
- ❌ **R-PLAYWRIGHT-NO-RAW-SQL.** Playwright spec triggering changes to an entity observed by a Doctrine listener via raw SQL (`bin/console doctrine:query:sql "UPDATE ..."`). Raw SQL bypasses UnitOfWork → listener never fires → handler never runs → assertion against side-effect (`notified_at`, dispatched message, sent email) fails. Use CLI command that goes through ORM (`bin/console app:variant:restock <code> <qty>`), admin UI flow via Playwright, or API call.
- ❌ **R-HOOK-VISIBILITY.** Selecting a `sylius_twig_hooks` leaf target without confirming the parent template renders it in the feature's target state. Leaf hooks under `*.add_to_cart`, `*.add_to_cart.variants.*` live inside `{% if %}` branches that short-circuit when variant unavailable. For out-of-stock-only features (back-in-stock widget), the `add_to_cart` sub-tree is dead. Use the parent hook (`sylius_shop.product.show.content.info.summary`) that fires unconditionally. Call MCP `sylius_hooks_resolve_for_visibility` with feature visibility state (`oos` / `in_stock` / `always`) to get valid targets - do not guess from hook name suffix.
- ❌ **R-EMAIL-PROOF.** Playwright asserting `notified_at IS NOT NULL` (or any handler-written DB column) as proof of email delivery. Handler reaches end-of-loop even when `null://null` swallows the message - false positive. Acceptable proofs: (1) capture transport (mailpit/mailhog) - scrape `http://localhost:8025/api/v1/messages`, (2) profiler mailer collector - works ONLY when restock triggered via HTTP. CLI restock bypasses profiler. If neither available, spec prints `// TODO: assert email via mailpit/profiler` rather than passing on a `notified_at` check it can't trust.
- ❌ **R-NOT-FOUND-EXCEPTION.** Throwing `\RuntimeException` (or any non-HTTP exception) from controller for resource-not-found. Renders HTTP 500 stack trace to shopper. Always `throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();` → HTTP 404 through Sylius error templates.
- ❌ **R-MAILER-CONFIG-TRANSLATION-KEY.** `sylius_mailer.emails.<code>.subject:` as literal English. Always a translation key: `subject: app.email.<code>.subject`. Template `{% block subject %}` overrides at render time, but config-level key keeps the indirection consistent.
- ❌ **R-FORM-PARENT-BUILDFORM.** Form type extending `AbstractResourceType` whose `buildForm()` does not call `parent::buildForm($builder, $options)` first. Future-proofs against Sylius adding parent behavior.
- ❌ **R-EM scope (tightened).** `EntityManagerInterface` injection in any feature service (controller, handler, listener, helper) when a Resource exists. Use `RepositoryInterface::add($x)` - Sylius `EntityRepository::add()` does persist+flush idempotently for both new and existing managed entities. Exception: bulk operations where DBAL/QueryBuilder beats per-row flush.
- ❌ **Ad-hoc `bin/console cache:clear` via Bash.** Project rule (CLAUDE.md) forbids it. The harness's auto-mode classifier intercepts even MCP-tool Bash invocations of it, so there's no carve-out. Cache:clear is owned by MCP `sylius_cache_clear` tool impl (must bypass Bash entirely) or project CLAUDE.md preset, not by this skill.
- ❌ **R-CONTROLLER-INVOKABLE.** `extends AbstractController` in a feature controller. Use `final` invokable services. Inject `FormFactoryInterface` (call `$this->formFactory->create(...)`, not `$this->createForm(...)`), `UrlGeneratorInterface` (`$this->urlGenerator->generate(...)`, not `$this->generateUrl(...)`). Flash via `$request->getSession()->getFlashBag()->add(...)`, not `$this->addFlash(...)`. `App\Controller\` glob w/ `controller.service_arguments` tag covers DI.
- ❌ **R-DOCTRINE-LISTENER-ATTRIBUTE hybrid.** `#[AsDoctrineListener]` (or `#[Autoconfigure(tags:[...])]`) on class AND explicit `doctrine.event_listener` yaml tag together. With `autoconfigure: true`, both registrations apply → listener fires twice per event. Attribute-only is canonical. See `reference/events.md` for accepted patterns + rare yaml-only fallback.
- ❌ **R-FORM-SVC dual pattern.** `app.form.type.<x>` id + FQCN alias dual declaration. Collapse to single FQCN-keyed service. See `reference/services.md`.
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
- ❌ **R-NAMESPACE-FROM-COMPOSER.** Hardcoding `App\` in any scaffold. Always read `composer.json` `autoload.psr-4` → derive app root namespace (`App\\`, `Elesto\\`, `Acme\\Shop\\`, etc.). Use first PSR-4 entry pointing at `src/`. Parameterize every scaffold input on it. MCP `sylius_project_profile.app_namespace` returns it.
- ❌ **R-PLUGIN-AWARENESS.** Designing inventory / pricing / order / availability / channel logic without first inventorying installed Sylius plugins that decorate the relevant service. Critical detections:
  - `sylius/multi-source-inventory-plugin` → stock lives in `InventorySourceStockInterface` rows, NOT `ProductVariant.onHand`. Doctrine listener watching `onHand` is dead under MSI.
  - `sylius/wishlist-plugin` → custom wishlist resource.
  - `sylius/refund-plugin` → refund flow alters order workflow.
  - `sylius/multi-currency-pricing-plugin` → channel pricing changed.
  - `sylius/b2b-plugin` → customer permissions altered.

  Call MCP `sylius_installed_plugins` before listener / inventory checker / price service design.
- ❌ **R-GLOB-EXCLUDED-DIR-AUTOWIRE.** Manual service def in a dir excluded from `<AppNs>\:` glob without explicit `autowire: true, autoconfigure: true`. `_defaults` inheritance is opaque - explicit beats implicit. See `reference/services.md`. Verify via `bin/console debug:container --show-arguments <FQCN>`.
- ❌ **R-MULTI-LOCALE.** Single `messages.<one_locale>.yaml` when project has multiple enabled locales. Read `sylius_locale.locales` (or `framework.default_locale` fallback) - emit one translation file per enabled locale. MCP `sylius_project_profile.locales` returns the list.
- ❌ **R-DEFAULT-URI.** Feature that generates URLs from Messenger handlers / CLI / non-HTTP contexts without `framework.router.default_uri` set. Detect via `bin/console debug:config framework.router`; if absent, add to feature yaml or `framework.yaml`. Without it, `url()` in email template renders empty/wrong host.

## Event Source Decision

For features watching field changes (stock, status):

- **Doctrine `onFlush` + `postFlush`** (Pattern A, attribute-only) - catches all paths: admin grid mutations, API, order-cancel restocks, bulk imports. Preferred for cross-path coverage like stock.
- **Sylius Resource event (`<host>.post_update`)** - admin/API only; order-workflow side effects bypass Resource controller. Idiomatic for admin-only-mutation features.

Stock is a field on `ProductVariant`, NOT a separate resource. No dedicated Sylius stock event surface exists. Doctrine listener is the simplest reliable signal for stock change detection.

## Core Repo Aliases (R-CORE-REPO-ALIASES)

When injecting a Sylius core repository FQCN interface, ensure `config/services.yaml` declares aliases to the canonical repository services. Add the aliases relevant to the feature, idempotent.

Full yaml block + notes (incl. `Sylius\Component\Channel\Repository\ChannelRepositoryInterface` lives in `Channel` component, NOT `Core`) in `reference/services.md`.

## Cache Clear

Not the skill's responsibility. CLAUDE.md forbids `bin/console cache:clear`; the harness auto-mode classifier denies even MCP tools that shell out to it. Cache:clear is owned by:

- MCP `sylius_cache_clear` tool impl - must bypass Bash entirely (FS ops + cache warmup via Symfony Kernel API), OR
- Project CLAUDE.md preset that grants the exception.

Skill assumes the cache is fresh enough or that the MCP tool / project setup handles it. Never invoke `cache:clear` via Bash.

## Code Style

Sylius-idiomatic style only - not personal preferences. Match the host project's coding standard (`vendor/bin/ecs` / `php-cs-fixer` if configured) for everything else.

- snake_case for Twig variables (Symfony / Sylius convention).
- Pass `template:` explicitly to Twig hooks and Twig components. Do not rely on Symfony UX auto-template paths - Sylius hooks need explicit paths (R-COMP-TPL).
- Form types extending `AbstractResourceType` must be registered via explicit yaml service def (R-FORM-SVC) - Sylius-Standard sets `_instanceof: AbstractResourceType: { autowire: false }`.

## Playwright Acceptance Protocol (mandatory step 11)

End-of-workflow live run. Author a **repeatable spec file** at `tests/Playwright/<feature>.spec.ts` (or project's equivalent path) - not exploratory one-shot tool calls. AI runs the file via Playwright MCP and refuses "done" if it fails. Refuse "done" without green pass on every step.

**Coverage rule:** spec must drive ALL observable user paths in the feature, not just the happy entry point. For back-in-stock that means: setup, subscribe, restock, email assertion, AND post-restock UI state (widget hidden once variant in stock). Single-step specs are rejected.

**Pre-req:** if MCP tool `sylius_cache_clear` available, call it once before the spec. Otherwise rely on project setup. NEVER `bin/console cache:clear` via Bash.

0. **Pre-verify cache clear.** If MCP `sylius_cache_clear` available, call once. Else rely on project setup. NEVER `bin/console cache:clear` via Bash.
1. Ensure dev server up. Project context provides URL (default `http://localhost:8000`). If down, ask user to start it - do not silently skip.
2. **Setup state.** Force the precondition variant out of stock: `bin/console app:variant:restock <variant_code> 0` (or `sylius:inventory:adjust` if app's command differs) or fixture preset.
3. `browser_navigate` → product show page for the variant. `browser_snapshot` → assert feature widget visible (form, button, badge).
4. `browser_type` / `browser_fill_form` → fill required inputs (e.g. email).
5. `browser_click` → submit. `browser_snapshot` → assert success flash / state change.
6. Trigger downstream condition through ORM: `bin/console app:variant:restock <variant_code> <qty>`. NEVER raw SQL (`doctrine:query:sql "UPDATE ..."`) - R-PLAYWRIGHT-NO-RAW-SQL: bypasses UoW → Doctrine listener never fires → handler never runs → assertion fails.
7. Mate profiler MCP → `profiler_list_requests` filtered by URL / method / recency → pick latest matching token. Sync Messenger transport in dev ⇒ handler ran in same request ⇒ same token covers email dispatch.
8. **Email proof (R-EMAIL-PROOF).** Assert email via inspectable target - NOT a DB column:
   - Capture transport (mailpit/mailhog) - scrape `http://localhost:8025/api/v1/messages` for matching subject + recipient + locale-correct body.
   - OR profiler mailer collector - works ONLY if restock triggered via HTTP request (admin form / API). CLI restock bypasses profiler.
   - If neither available: print `// TODO: assert email via mailpit/profiler` and report acceptance INCOMPLETE - do not pass on a `notified_at`-style DB check.
9. **Post-state assertion.** `browser_navigate` → product page again. `browser_snapshot` → assert widget NO LONGER visible (variant now in stock). Catches stale-cache bugs and listener idempotency failures.
10. Any step fails → fix root cause, re-run from step 0. Do not declare done until full sequence green.

If feature has no email leg: stop at step 5. If feature has no async leg: skip steps 6–8. Surface assertions (steps 2–5, 9) always mandatory.

## Linked Files

- `workflow.md` - 13-step build checklist with MCP calls + verify commands.
- `anti-patterns.md` - ❌/✅ pairs per hard rule with "Why" line.
- `reference/resource.md`, `reference/twig-hooks.md`, `reference/mailer.md`, `reference/events.md`, `reference/services.md` - deep dives, fetch on demand.
