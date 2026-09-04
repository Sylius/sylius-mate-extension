# Workflow - Sylius Feature Build

13 steps. Each step: goal, Mate tool (if any; invoke as `vendor/bin/mate tools:call <tool> --<param>=<value>`), output, verification.

## 1. Discover

**Goal:** Map existing surface before generating anything.

**Discovery calls (in order, Mate tool where one exists):**

- `sylius_project_profile` - **first**. Returns `app_namespace` (PSR-4 root from composer.json; never assume `<AppNs>\`), `enabled_locales` (emit one translation file per), `framework_router_default_uri` (R-DEFAULT-URI gate), `symfony_mate_bridge` (container lookup - see SKILL.md).
- `sylius_installed_plugins` + `sylius_service_decorators` - what is installed and which `sylius.*` services are decorated. A decorator on the service you plan to hook means the data may live elsewhere (MSI moves stock to `InventorySourceStockInterface`). Adjust listener / query target accordingly (R-PLUGIN-AWARENESS).
- `sylius_domain_list_resources` - does target resource already exist?
- `sylius_hooks_find_for_template` - which TwigHook entry slot fits the UI change?
- `sylius_hooks_resolve_for_visibility` - given feature visibility state (`oos` / `in_stock` / `always` / `logged_in_only`), returns hook targets whose parent template renders in that state. **Mandatory** before selecting a leaf hook (R-HOOK-VISIBILITY). Leaf hooks like `*.add_to_cart.*` live inside `{% if %}` branches that short-circuit when variant unavailable; for an out-of-stock-only feature they are dead.
- **Domain event check.** Grep `vendor/sylius/*/src/**/SyliusEvents.php` for an existing event covering the trigger. No Mate tool for this - these are compile-time constants, not kernel state.
- **Mailer code check.** Read `sylius_mailer.emails.*` keys from `config/packages/_sylius_mailer.yaml` for existing mailer codes. No dedicated Mate list tool; `sylius_mailer_verify_template` confirms the final code+template pair later (step 9.5).
- `sylius_domain_list_grids` - closest existing grid to mirror.

**Output:** Notes in scratch - chosen hook name, chosen event class, chosen mailer code, grid template to mirror.

**Verify:** None.

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

## 5. Frontend

**Goal:** UI placed via TwigHook, not template override.

**Hook target selection (R-HOOK-VISIBILITY):**

- Resolve target via `sylius_hooks_resolve_for_visibility` Mate tool with feature state. Never guess from hook name suffix.
- Out-of-stock-only widgets → parent hook (`sylius_shop.product.show.content.info.summary`), NOT `add_to_cart` sub-tree (dead branch when variant unavailable).
- In-stock-only features → `add_to_cart` and children OK.
- Always-visible → any hook fires.

**Output:**

- Hook entry in `config/packages/_sylius_twig_hooks.yaml` - pass explicit `template:` path.
- Template under `templates/shop/...` - render via `{{ form_start(form, {action: path('<route>')}) }}` / `form_row` / `form_end(form)`. No hand-rolled `<form>` open tag. No hand-rolled `<input>`. Never manually render `form._token` - `form_end` emits it.
- Hook template **always** starts by destructuring context (R-HOOKABLE-METADATA):

  ```twig
  {% set variant = hookable_metadata.context.variant ?? null %}
  {% set product = hookable_metadata.context.product ?? null %}
  ```

  Never reference `variant` / `product` as bare vars - they may not be auto-passed depending on hook position.
- Preferred for stateless widgets: plain `template:` hook + Twig Extension to compute view data. Avoid Twig Components unless feature needs Live behavior.
- If using `#[AsTwigComponent]` for a hook target - bare attribute alone does NOT bind props from hook config (Sylius hooks need the `sylius.twig_component` tag + custom compiler pass). Use Sylius's `#[AsLiveComponent]` / `sylius.twig_component` registration, or fall back to `template:` hook + Twig Extension.
- Component class location: `src/TwigComponent/<Section>/<X>Component.php` (matches Sylius bundle convention `Sylius\Bundle\<X>Bundle\TwigComponent\`). NOT `src/Twig/Components/` (Symfony UX default).

**Verify:** `bin/console lint:twig templates/`.

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

## 7. Listener

**Goal:** React to domain change.

**Decision tree:**

1. Sylius event exists? → tag listener on it.
2. No event but Doctrine mutation? → for a field-level state change use `onFlush` UnitOfWork (catches direct updates, bulk mutations, order-workflow side effects, etc.). `preUpdate` only as last resort.
3. Side effect non-trivial (email, external call) → dispatch Messenger message in listener, do work in handler.

**R-FLUSH-ORDER (mandatory split):**

- `onFlush` - read change set, collect **codes / UUIDs** (R-LISTENER-CODE-NOT-ID - never autoincrement ids; codes survive env restores / fixture reloads / snapshots) into a private array on the listener. NO dispatch, NO writes, NO `register_shutdown_function`. Exception: entities w/o natural codes (`Adjustment`, `OrderItemUnit`) - use id, document why.
- `postFlush` - iterate the collected array, dispatch Messenger messages / call downstream services. UoW guaranteed clean here.

```php
private array $collected = [];

public function onFlush(OnFlushEventArgs $args): void
{
    $uow = $args->getObjectManager()->getUnitOfWork();
    foreach ($uow->getScheduledEntityUpdates() as $entity) {
        if (!$entity instanceof <Entity>Interface) continue;
        $changes = $uow->getEntityChangeSet($entity);
        if (!isset($changes['<watched_field>'])) continue;
        [$old, $new] = $changes['<watched_field>'];
        if (/* $old no longer satisfies the trigger condition, $new does */) {
            $this->collected[] = (string) $entity->getCode();
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

A filled-in instance of this exact pattern (stock crossing 0, dispatching a back-in-stock message) is in `reference/worked-example.md`.

**Plugin gate (R-PLUGIN-AWARENESS):** before designing the listener target, confirm `sylius_installed_plugins` results. If `sylius/multi-source-inventory-plugin` present, stock lives in `InventorySourceStockInterface` rows - a Doctrine listener on `ProductVariant.onHand` is dead. Target MSI stock rows instead. Same applies to wishlist / refund / multi-currency / b2b plugin decorations.

**Output:** Listener registered via attribute (R-DOCTRINE-LISTENER-ATTRIBUTE). Two accepted forms:

Pattern A - `#[AsDoctrineListener]` per event:

```php
use Doctrine\ORM\Events;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class <X>Listener
{
}
```

Pattern A' - single `#[Autoconfigure]` w/ inline tags (cleaner on Symfony 6.4+):

```php
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(tags: [
    ['name' => 'doctrine.event_listener', 'event' => 'onFlush'],
    ['name' => 'doctrine.event_listener', 'event' => 'postFlush'],
])]
final class <X>Listener
{
}
```

Pick one. Never both. Never combine with yaml `doctrine.event_listener` tag (fires twice).

services.yaml - only declare for explicit DI args, NO `doctrine.event_listener` tag (autoconfigure + attribute already register; adding the tag fires twice):

```yaml
<AppNs>\EventListener\<X>Listener: ~
```

Forbidden hybrid: attribute + yaml tag together + `autoconfigure: true` → fires twice per event.

Pattern B fallback (rare): no attribute + `autoconfigure: false` + explicit yaml tags. Only when class lives in autowire-excluded dir AND attribute can't be used. With `<AppNs>\:` `exclude:` containing `'../src/EventListener/'`, Pattern B requires both the def and the tags in yaml.

**Choosing the source:** Doctrine `onFlush`/`postFlush` catches all paths (admin, API, order-workflow side effects, bulk imports). Sylius Resource event (`<host>.post_update`) covers admin/API only. For cross-path field watching, Doctrine is the simplest reliable signal when the field lives on an existing resource with no dedicated domain event - stock on `ProductVariant` is the canonical case, worked end to end in `reference/worked-example.md`.

**Verify:** `bin/console debug:event-dispatcher` (Sylius events) / `bin/console doctrine:event:list`.

## 8. Async Handler

**Goal:** Heavy work off request path.

**Output:**

- `#[AsMessageHandler] final class <X>Handler`.
- Email send via Sylius `SenderInterface::send($code, [$recipient], $context)`. `$context` MUST include `channel` (resolved via `Sylius\Component\Channel\Repository\ChannelRepositoryInterface::findOneByCode($code)` - NOT `Sylius\Component\Core\Repository\ChannelRepositoryInterface`, which does not exist) and `localeCode` (from persisted resource, not current request) plus resource-specific keys (`variant`, `product`, `notification`, etc.).
- Messenger transport configured (`async` or `failed`).

**Verify:** `bin/console messenger:debug`.

## 9. Mailer

**Goal:** Email registered + template present.

**Output:**

- `config/packages/_sylius_mailer.yaml` - `sylius_mailer.emails.app_<code>` (R-APP-EMAIL-PREFIX - always `app_` prefix to avoid colliding with Sylius core codes like `order_confirmation`, `password_reset_token`) with subject as a **translation key** (R-MAILER-CONFIG-TRANSLATION-KEY: `subject: app.email.<code>.subject`, never literal English) + template path.
- `templates/email/<code>.html.twig` - MUST follow this skeleton (R-EMAIL-LAYOUT):

  ```twig
  {% extends '@SyliusCore/Email/layout.html.twig' %}

  {% block subject %}
      {% set translation_locale = <resource>.localeCode %}
      {{ 'app.email.<code>.subject'|trans({}, 'messages', translation_locale) }}
  {% endblock %}

  {% block content %}
      {% set translation_locale = <resource>.localeCode %}
      <p>{{ 'app.email.<code>.body'|trans({}, 'messages', translation_locale) }}</p>
  {% endblock %}
  ```

  A filled-in instance (with a product-name interpolation and a link back to
  the product page) is in `reference/worked-example.md`.

  URL rule (R-URL-IN-EMAIL): always Twig `url()` with the full `localeCode`. Never hand-roll URL concatenation (`sylius_channel_url(asset(''), channel) ~ '/products/' ~ slug`) and never `localeCode|split('_')[0]` (strips region - Sylius URLs use full locale). Messenger async handlers have no Request context → `framework.router.default_uri` MUST be set (R-DEFAULT-URI). Verify via `bin/console debug:config framework.router`; if absent, add:

  ```yaml
  # config/packages/framework.yaml (or messenger.yaml)
  framework:
      router:
          default_uri: '%env(APP_DEFAULT_URI)%'
  ```

  Rules:
  - `{% extends '@SyliusCore/Email/layout.html.twig' %}` required.
  - Both `{% block subject %}` and `{% block content %}` defined.
  - Helper `{% set %}` declared **inside** each block - Sylius mailer renders `subject` standalone, top-level `set` does not propagate.
- Translations under `translations/messages.<EXACT_locale>.yaml` if subject uses translation key. Filename must match shop locale exactly (`en_US`, not `en`).

## 9.5. Email template gate

If feature dispatches email:

- Scaffold template from skeleton above.
- Verify `sylius_mailer.emails.<code>.template` value equals the actual file path under `templates/`.

**Verify:** `sylius_mailer_verify_template --code=<code>`.

## 9.6. Translation gate (R-MULTI-LOCALE)

If feature uses any translation key:

- Read `sylius_locale.locales` (fallback: `framework.default_locale`). The Mate tool `sylius_project_profile` returns the list as `enabled_locales`.
- Emit ONE `translations/messages.<locale>.yaml` per enabled locale. Sylius-Standard ships `en_US`; Elesto ships `en_US` + `pl_PL`; per-project varies.
- Filename matches exact locale string (variant included). `messages.en_US.yaml`, NOT `messages.en.yaml`.
- No separate cache reminder - verify-step targeted translation cache wipe handles it.

## 10. Verify Pass

Run every command. Output must be empty/passing. Any failure → STOP, fix, re-run. Do not report task complete with non-empty error output.

```bash
# 1. Targeted translation cache wipe - only if translations changed.
#    NOT a full cache:clear (see 10.5). Surgical rm of one dir.
[ -d var/cache/dev/translations ] && rm -f var/cache/dev/translations/*

# 2. PHP syntax
for f in <touched_php_files>; do php -l "$f" || exit 1; done

# 3. YAML
bin/console lint:yaml config/ --parse-tags

# 4. Twig
bin/console lint:twig templates/

# 5. Container - services + ambiguous-binding check.
# Compile gate first: `sylius_cache_clear` drops the stale container, the next
# kernel-booting sylius_* tool recompiles it, and an autowiring failure
# ("no matching" / "multiple") surfaces there. Lookup = Symfony Mate bridge when
# installed (pack), `bin/console debug:container` otherwise - see SKILL.md.
vendor/bin/mate tools:call sylius_cache_clear || exit 1
vendor/bin/mate tools:call sylius_project_profile || exit 1
service_detail() {
    if [ -d vendor/symfony/ai-symfony-mate-extension ]; then
        vendor/bin/mate tools:call symfony-service-detail --id="$1" --format=json
    else
        bin/console debug:container "$1" --show-arguments
    fi
}
# MANDATORY: run for EVERY class added or modified (controllers, handlers,
# listeners, form types, components, factories, services, etc.). Exact FQCN as id;
# "Service ... not found" = not registered, `constructor` must list concrete services.
# Form types extending AbstractResourceType MUST appear with the explicit
# FQCN-keyed service - autowire is off for them.
for fqcn in <every_new_or_modified_FQCN>; do
    service_detail "$fqcn" || exit 1
done

# 6. Schema
bin/console doctrine:schema:validate --skip-sync

# 7. Routes - every new route + admin index route mandatory
bin/console debug:router | grep <route_name>
bin/console debug:router | grep app_admin_<feature>_index   # R-FEATURE-DONE-INCLUDES-ADMIN

# 8. Messenger (if async)
bin/console messenger:debug | grep <MessageClass>

# 9. Mailpit cleanup before Playwright (R-EMAIL-PROOF prep)
curl -sX DELETE http://localhost:8025/api/v1/messages

# 10. Behat dry-run (only if .feature authored)
vendor/bin/behat features/<area>/<name>.feature --dry-run

# 11. Mate verify (mandatory), via vendor/bin/mate tools:call
#   sylius_resource_inspect --alias=<alias>
#   sylius_routes_show --name=<route_name>
#   sylius_mailer_verify_template --code=<code>
#   sylius_hooks_find_for_template --template_path=<template_path>

# 12. Feature-done gate (R-FEATURE-DONE-INCLUDES-ADMIN) - no single pass/fail
#     tool; composed from checks already run above:
#   - step 7's `debug:router | grep app_admin_<feature>_index` returned a hit
#   - `sylius_grid.grids.app_admin_<feature>` exists in config/packages/_sylius_grid.yaml
#   - step 11's `sylius_resource_inspect --alias=<alias>` passed
```

Do **not** run `bin/console fos:elastica:populate` automatically - slow and project-specific. Tell the user.

## 10.5. Pre-Playwright Cache Clear

Call the Mate tool `sylius_cache_clear` once (PHP-native, no shell). Never `bin/console cache:clear` from the shell - host projects commonly forbid it and agent harnesses may block it.

## 11. Playwright Acceptance (mandatory)

**Goal:** Live end-to-end run proves the feature works. Refuse "done" without green pass.

**Pre-req:** Dev server up. Project context tells AI URL (default `http://localhost:8000`); if down, ask the user to start it - do not silently skip. Email assertion needs mailpit or a readable profiler (bridge tools or `var/cache/dev/profiler`).

**Authoring rule:** Write a repeatable spec file at `tests/Playwright/<feature>.spec.ts` (or the project's configured Playwright spec location). Do NOT run the steps as one-shot exploratory tool calls. Then execute the spec via Playwright MCP. Spec must be committable, re-runnable, deterministic.

**Coverage rule:** spec drives ALL observable user paths in the feature, not just the entry point. Single-step specs rejected. `reference/worked-example.md` walks this exact protocol against a concrete feature.

**Steps (encoded in the spec):**

1. **Setup state.** Force the feature's precondition via a project CLI command or fixture preset - not a hand-rolled `UPDATE`. Spec self-prepares - do not assume DB state.
2. `browser_navigate` → the page the feature's UI lives on.
3. `browser_snapshot` → assert feature widget visible (form / button / badge - feature-specific).
4. `browser_type` or `browser_fill_form` → fill required inputs (email, etc.).
5. `browser_click` → submit.
6. `browser_snapshot` → assert success flash / state change.
7. Trigger the downstream condition via an ORM-aware path: a CLI command, admin UI flow via Playwright, or API call. NEVER raw SQL (`doctrine:query:sql "UPDATE ..."`) - R-PLAYWRIGHT-NO-RAW-SQL: bypasses UoW → Doctrine listener never fires → handler never runs → mailer assertion fails.
8. Profiler token (bridge installed) → `symfony-profiler-list` filtered by URL / method / recency → pick latest matching token; without the bridge read the latest token from `var/cache/dev/profiler/index.csv`. Sync Messenger transport in dev ⇒ handler ran in same request ⇒ same token covers email dispatch.
9. **Email proof (R-EMAIL-PROOF).** Assert via inspectable target - NOT a DB column written by the handler:
   - **Mailpit/mailhog capture transport** (preferred): scrape `http://localhost:8025/api/v1/messages` for matching subject + recipient + locale-correct body.
   - **Profiler mailer collector**: `vendor/bin/mate resources:read symfony-profiler://profile/<token>/mailer` (`symfony-profiler-get` does not list collectors; `symfony-profiler://profile/<token>` does), or `/_profiler/<token>?panel=mailer` over HTTP without the bridge. Works ONLY when the triggering mutation happened via HTTP (admin form / API) - a CLI-triggered mutation bypasses profiler.
   - If `MAILER_DSN` is `null://null` and neither inspectable target is available: print `// TODO: assert email via mailpit/profiler` and report acceptance INCOMPLETE. Do NOT pass on a handler-written DB flag check - handler reaches end-of-loop even when `null://null` swallows the message. False positive.
10. **Post-state assertion.** `browser_navigate` → the feature's page again. `browser_snapshot` → assert widget NO LONGER visible (precondition no longer holds). Catches stale cache + listener idempotency failures.
11. Any step fails → fix root cause, re-run from step 1. Do not skip with "good enough".

**Adapt:**

- No email leg → stop at step 6, still run step 10 if UI state changes.
- No async leg → skip steps 7–9.
- Surface assertions (steps 2–6, 10) always mandatory.
