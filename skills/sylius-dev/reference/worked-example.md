# Worked Example - Back-in-Stock Notifications

The rest of this skill (`SKILL.md`, `workflow.md`, `anti-patterns.md`,
other `reference/*.md`) is written generically - placeholders like
`<Name>`, `<X>`, `<alias>`, `<TriggerField>` stand for whatever feature
you're actually building. This file is the one place that stays fully
concrete: a real feature, built start to finish, so every abstract rule
has somewhere to point when "show me an actual example" is the question.

**Feature:** a shopper on an out-of-stock product variant's page can leave
their email to get notified when it's back in stock. Chosen because it
touches nearly every hard rule in one small feature: a resource, a
Doctrine-detected state transition, an async email, a hook that has to
disappear once the precondition changes, and a Playwright acceptance gate
that has to prove all of that end to end.

Rule IDs are tagged in parens - cross-reference `anti-patterns.md` for the
❌/✅ pair and "Why".

## 1. Resource

`sylius_domain_resource_template(alias: "app.back_in_stock_notification", model_short_name: "BackInStockNotification", mailer_code: "back_in_stock")` scaffolds:

- `App\Entity\BackInStockNotification` implements `BackInStockNotificationInterface` - persists `variant` (`ProductVariantInterface`), `email`, `localeCode` (length 16), `channelCode`, `notifiedAt` (nullable `\DateTimeImmutable`).
- `App\Repository\BackInStockNotificationRepository` + interface.
- `App\Form\Type\BackInStockNotificationType` extends `AbstractResourceType`.
- `sylius_resource.resources.app.back_in_stock_notification` entry, no `classes.factory:` (default factory).
- Admin grid stub for `app_admin_back_in_stock_notification`.

No custom factory - nothing to pre-construct.

## 2. Migration

`bin/console doctrine:migrations:diff`, then cleanup per R-MIGRATION-CLEANUP: strip the auto-generated docblock/comments, description → `"Add back_in_stock_notification table"`.

## 3. Form

`BackInStockNotificationType::buildForm()` calls `parent::buildForm()` first (R-FORM-PARENT-BUILDFORM), adds an `email` field. Registered FQCN-keyed in `config/services/app_back_in_stock.yaml` (R-FORM-SVC):

```yaml
services:
    App\Form\Type\BackInStockNotificationType:
        arguments:
            - 'App\Entity\BackInStockNotification'
            - ['sylius']
        tags:
            - { name: form.type }
```

## 4. Frontend

Widget must render on an **out-of-stock** variant and disappear once restocked. `sylius_hooks_resolve_for_visibility(intent: "oos")` resolves the target - NOT `*.add_to_cart.*` (dead branch when the variant is unavailable, R-HOOK-VISIBILITY), but the parent hook `sylius_shop.product.show.content.info.summary`.

```yaml
# config/packages/_sylius_twig_hooks.yaml
sylius_shop.product.show.content.info.summary:
    back_in_stock_widget:
        template: 'shop/product/back_in_stock_widget.html.twig'
```

```twig
{# templates/shop/product/back_in_stock_widget.html.twig #}
{% set variant = hookable_metadata.context.variant ?? null %}
{% set product = hookable_metadata.context.product ?? null %}

{% if variant and not variant.inStock %}
    {{ form_start(form, {action: path('app_shop_back_in_stock_subscribe', {code: variant.code})}) }}
        {{ form_row(form.email) }}
    {{ form_end(form) }}
{% endif %}
```

## 5. Controller

`final class BackInStockSubscribeAction` (R-CONTROLLER-INVOKABLE - no `AbstractController`). Injects `FormFactoryInterface`, `#[Autowire(service: 'app.factory.back_in_stock_notification')] FactoryInterface $factory`, `BackInStockNotificationRepositoryInterface`, core `ProductVariantRepositoryInterface`, `ChannelContextInterface`, `LocaleContextInterface`. Persists via `$repository->add($notification)`, never `EntityManagerInterface`.

## 6. Listener - the R-FLUSH-ORDER example

Stock is a field (`onHand`) on `ProductVariant`, not a separate Sylius resource - no dedicated domain event exists for it. Doctrine `onFlush` + `postFlush` catches every path that can change it (admin grid edit, API, order-cancel restock, bulk import) where a Resource `post_update` event would only catch admin/API:

```php
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ProductVariantRestockListener
{
    private array $collected = [];

    public function __construct(private MessageBusInterface $bus) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof ProductVariantInterface) {
                continue;
            }
            $changes = $uow->getEntityChangeSet($entity);
            if (!isset($changes['onHand'])) {
                continue;
            }
            [$old, $new] = $changes['onHand'];
            if ($old <= 0 && $new > 0) {
                // R-LISTENER-CODE-NOT-ID: collect the code, not getId() -
                // survives env restores / fixture reloads / snapshots.
                $this->collected[] = $entity->getCode();
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        foreach ($this->collected as $code) {
            $this->bus->dispatch(new ProductBackInStock($code));
        }
        $this->collected = [];
    }
}
```

**Plugin gate (R-PLUGIN-AWARENESS):** `sylius_installed_plugins` checked first
- if `sylius/multi-source-inventory-plugin` is installed, stock lives in
`InventorySourceStockInterface` rows, not `ProductVariant.onHand`, and this
listener target is dead. Retarget accordingly.

## 7. Async Handler

```php
#[AsMessageHandler]
final class ProductBackInStockHandler
{
    public function __invoke(ProductBackInStock $message): void
    {
        $variant = $this->variantRepository->findOneByCode($message->variantCode);
        foreach ($this->notificationRepository->findPendingFor($variant) as $notification) {
            $this->sender->send('back_in_stock', [$notification->getEmail()], [
                'channel' => $this->channelRepository->findOneByCode($notification->getChannelCode()),
                'localeCode' => $notification->getLocaleCode(),
                'variant' => $variant,
            ]);
            $notification->markNotified();
        }
    }
}
```

`$context` carries `channel` + `localeCode` from the *persisted notification*, not the current request (R-MAILER-CTX) - the handler runs outside any HTTP context.

## 8. Mailer - the R-EMAIL-LAYOUT / R-URL-IN-EMAIL example

```yaml
# config/packages/_sylius_mailer.yaml
sylius_mailer:
    emails:
        app_back_in_stock:
            subject: app.email.back_in_stock.subject
            template: 'email/back_in_stock.html.twig'
```

`app_` prefix (R-APP-EMAIL-PREFIX) avoids colliding with core codes like
`order_confirmation`.

```twig
{# templates/email/back_in_stock.html.twig #}
{% extends '@SyliusCore/Email/layout.html.twig' %}

{% block subject %}
    {% set translation_locale = localeCode %}
    {{ 'app.email.back_in_stock.subject'|trans({}, 'messages', translation_locale) }}
{% endblock %}

{% block content %}
    {% set translation_locale = localeCode %}
    <p>{{ 'app.email.back_in_stock.body'|trans({'%product%': variant.product.name}, 'messages', translation_locale) }}</p>
    <p>
        <a href="{{ url('sylius_shop_product_show', {slug: variant.product.translation(translation_locale).slug, _locale: translation_locale}) }}">
            {{ 'app.email.back_in_stock.view_product'|trans({}, 'messages', translation_locale) }}
        </a>
    </p>
{% endblock %}
```

Both `subject` and `content` blocks, `{% set %}` **inside** each block (Sylius
renders `subject` standalone). URL via `url()`, full `localeCode` - never
`sylius_channel_url(asset(''), channel) ~ '/products/' ~ slug` and never
`localeCode|split('_')[0]`. Messenger handlers have no Request context, so
`framework.router.default_uri` must be set (R-DEFAULT-URI) or `url()` renders
empty/wrong host.

## 9. Playwright Acceptance - the full 11-step protocol applied

`tests/Playwright/back-in-stock.spec.ts`:

1. **Setup.** `bin/console app:variant:restock TSHIRT_S 0` (this project's
   own restock command - substitute whatever CLI/fixture your project uses
   to force a variant out of stock; there's no universal Sylius command for
   this).
2. `browser_navigate` → product show page for `TSHIRT_S`.
3. `browser_snapshot` → assert the subscribe form is visible.
4. `browser_fill_form` → email field.
5. `browser_click` → submit. `browser_snapshot` → assert success flash.
6. Trigger restock through ORM: `bin/console app:variant:restock TSHIRT_S 10`.
   **Never** `doctrine:query:sql "UPDATE ..."` (R-PLAYWRIGHT-NO-RAW-SQL) -
   raw SQL bypasses UnitOfWork, so the listener in step 6 above never fires.
7. Mate Symfony profiler tools → `symfony-profiler-list` → latest token for the
   restock request. Sync Messenger in dev ⇒ handler ran in the same request.
8. **Email proof (R-EMAIL-PROOF).** Scrape `http://localhost:8025/api/v1/messages`
   (mailpit) for the matching subject + recipient + locale-correct body, OR
   read the profiler mailer collector for that token. **Not** a
   `notifiedAt IS NOT NULL` check - the handler reaches end-of-loop even when
   `MAILER_DSN=null://null` swallows the message silently.
9. **Post-state.** `browser_navigate` back to the product page. `browser_snapshot`
   → assert the subscribe widget is **gone** (variant now in stock). Catches
   stale-cache bugs and non-idempotent listeners.
10. Any step fails → fix root cause, re-run from step 1.

This is the spec that a `sylius-dev` behavioral test run replays end to end -
if you change the abstract rules elsewhere in this skill, re-run this exact
scenario before calling the change safe.
