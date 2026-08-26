# Anti-Patterns

❌/✅ pairs per hard rule. Lazy-load when refusing.

## 1. Plain Doctrine entity

❌ Wrong:

```php
#[ORM\Entity]
class BackInStockNotification
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
}
```

✅ Right:

```php
interface BackInStockNotificationInterface extends ResourceInterface
{
}

#[ORM\Entity]
class BackInStockNotification implements BackInStockNotificationInterface
{
}
```

```yaml
sylius_resource:
    resources:
        app.back_in_stock_notification:
            classes:
                model: App\Entity\BackInStockNotification
                interface: App\Entity\BackInStockNotificationInterface
                repository: App\Repository\BackInStockNotificationRepository
                form: App\Form\Type\BackInStockNotificationType
```

**Why:** Resource registration unlocks factory, repo, admin grid, events, API auto-wiring. Plain Doctrine = manual everything + breaks plugin extension points.

`classes.factory:` omitted - Sylius default `Sylius\Resource\Factory\Factory` wires automatically. Declare a custom factory only when pre-construction behavior is needed; constructor MUST be `__construct(string $className)`.

## 2. EntityManager in controller

❌ Wrong:

```php
public function __construct(private EntityManagerInterface $em) {}

public function __invoke(Request $request): Response
{
    $notif = new BackInStockNotification(...);
    $this->em->persist($notif);
    $this->em->flush();
}
```

✅ Right:

```php
public function __construct(
    private BackInStockNotificationFactoryInterface $factory,
    private BackInStockNotificationRepositoryInterface $repository,
) {
}

public function __invoke(Request $request): Response
{
    $notif = $this->factory->createNew();
    $this->repository->add($notif);
}
```

**Why:** Factory enforces invariants. Repo is the resource's persistence boundary. EM in controller bypasses both, blocks override via DI alias.

## 3. AbstractType for resource form

❌ Wrong:

```php
final class BackInStockNotificationType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'app_back_in_stock_notification';
    }
}
```

✅ Right:

```php
final class BackInStockNotificationType extends AbstractResourceType
{
    protected function getBlockPrefix(): string
    {
        return 'app_back_in_stock_notification';
    }
}
```

**Why:** `AbstractResourceType` wires `data_class` from resource registry, supports validation groups per resource. Plain `AbstractType` reinvents this and breaks resource override.

## 4. Hand-rolled `<input>`

❌ Wrong:

```twig
<form method="post" action="{{ path('app_shop_back_in_stock_subscribe') }}">
    <input type="email" name="email" required>
    <button>Notify me</button>
</form>
```

✅ Right:

```twig
{{ form_start(form) }}
    {{ form_row(form.email) }}
    <button>{{ 'app.ui.notify_me'|trans }}</button>
{{ form_end(form) }}
```

**Why:** Hand-rolled inputs bypass CSRF, validation, theme, translation. Form rendering helpers integrate all of it.

## 5. Concrete entity type-hints

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

## 6. Template override

❌ Wrong:

`templates/bundles/SyliusShopBundle/Product/show.html.twig`:

```twig
{% extends '@!SyliusShop/Product/show.html.twig' %}
{% block main %}
    {{ parent() }}
    <button>Notify me</button>
{% endblock %}
```

✅ Right:

```yaml
sylius_twig_hooks:
    hooks:
        sylius_shop.product.show.content.info:
            back_in_stock_button:
                template: 'shop/product/back_in_stock_button.html.twig'
                priority: 100
```

**Why:** TwigHooks are additive, survive theme + Sylius upgrades. Template extends locks the whole file to one consumer; breaks plugin co-existence.

## 7. Hand-written CREATE TABLE migration

❌ Wrong:

```php
public function up(Schema $schema): void
{
    $this->addSql('CREATE TABLE app_back_in_stock_notification (id INT NOT NULL, ...)');
}
```

✅ Right:

```bash
bin/console doctrine:migrations:diff
```

Then review the generated file. Adjust only if diff is wrong.

**Why:** Hand-written migrations drift from mapping. `diff` is authoritative.

## 8. Missing admin grid

❌ Wrong: user-facing resource with no grid.

✅ Right:

```yaml
sylius_grid:
    grids:
        app_admin_back_in_stock_notification:
            driver:
                name: doctrine/orm
                options:
                    class: App\Entity\BackInStockNotification
            fields:
                email: { type: string, label: app.ui.email }
                product: { type: twig, options: { template: 'admin/grid/field/product.html.twig' } }
                createdAt: { type: datetime, label: sylius.ui.created_at }
            actions:
                main:
                    create: { type: create }
                item:
                    delete: { type: delete }
```

**Why:** Admin needs to view/manage. Grid yaml + route is 30 lines vs custom CRUD = hundreds.

## 9. Missing fixture

❌ Wrong: no fixture for new resource.

✅ Right:

```php
final class BackInStockNotificationFixture extends AbstractFixture
{
    public function getName(): string
    {
        return 'app_back_in_stock_notification';
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->integerNode('amount')->defaultValue(20)->end()
            ->end()
        ;
    }

    public function load(array $options): void
    {
        for ($i = 0; $i < $options['amount']; ++$i) {
            $notif = $this->factory->createNew();
            $this->repository->add($notif);
        }
    }
}
```

**Why:** Sylius demo + CI runs depend on fixtures. New resource without fixture = blank in `sylius:fixtures:load`.

## 10. Acceptance test (Playwright required; Behat optional)

Playwright spec is the mandatory acceptance gate (see SKILL.md workflow step 11). Behat is optional - add only when user requests or when feature is pure-domain logic better expressed as Gherkin.

✅ Optional Behat example:

```gherkin
Feature: Subscribing to back-in-stock notification
    In order to know when product returns
    As a Customer
    I want to subscribe to a notification

    Background:
        Given the store operates on a single channel in "United States"
        And there is a product "T-Shirt" that is out of stock

    Scenario: Subscribing with email
        When I am browsing the product "T-Shirt"
        And I subscribe to back-in-stock with email "buyer@example.com"
        Then I should be notified that subscription was created
```

**Why:** Behat is Sylius's classic regression net, but Playwright covers UI + listener + email end-to-end. Pick the one that fits the feature shape; do not require both.

## 11. Doctrine preUpdate for inventory

❌ Wrong:

```php
public function preUpdate(PreUpdateEventArgs $args): void
{
    if (!$args->hasChangedField('onHand')) return;
    // notify...
}
```

✅ Right (Sylius event):

```php
public static function getSubscribedEvents(): array
{
    return ['sylius.product_variant.post_update' => 'onUpdate'];
}
```

✅ Right (inventory needs UoW - `onFlush`):

```php
public function onFlush(OnFlushEventArgs $args): void
{
    $uow = $args->getObjectManager()->getUnitOfWork();
    foreach ($uow->getScheduledEntityUpdates() as $entity) {
        if (!$entity instanceof ProductVariantInterface) continue;
        $changes = $uow->getEntityChangeSet($entity);
        if (!isset($changes['onHand'])) continue;
        [$old, $new] = $changes['onHand'];
        if ($old <= 0 && $new > 0) {
            $this->messageBus->dispatch(new ProductBackInStock($entity->getCode()));
        }
    }
}
```

**Why:** `preUpdate` misses cases (hold release, direct UPDATE via custom code). `onFlush` + UoW catches all paths. Sylius event preferred when one exists.

## 12. Sync mail loop

❌ Wrong:

```php
foreach ($subscribers as $s) {
    $this->sender->send('back_in_stock', [$s->getEmail()], ['variant' => $variant]);
}
```

…inside a Doctrine listener or controller.

✅ Right:

Listener dispatches `ProductBackInStock` message. Handler:

```php
#[AsMessageHandler]
final class ProductBackInStockHandler
{
    public function __invoke(ProductBackInStock $message): void
    {
        foreach ($this->repository->findByVariantCode($message->variantCode) as $s) {
            $this->sender->send('back_in_stock', [$s->getEmail()], [...]);
        }
    }
}
```

**Why:** Sync send in flush path = transaction lock + slow request + flaky on SMTP outage. Messenger isolates.

## 13. Mutable DateTime

❌ Wrong:

```php
#[ORM\Column(type: 'datetime')]
private ?\DateTimeInterface $createdAt = null;

public function setCreatedAt(\DateTime $createdAt): void
{
    $this->createdAt = $createdAt;
}
```

✅ Right:

```php
#[ORM\Column(type: 'datetime_immutable')]
private ?\DateTimeImmutable $createdAt = null;

public function setCreatedAt(\DateTimeImmutable $createdAt): void
{
    $this->createdAt = $createdAt;
}
```

**Why:** Mutable `\DateTime` leaks aliasing bugs (caller mutates entity state via shared ref). `\DateTimeImmutable` + `datetime_immutable` column type lock value semantics. Property type concrete `\DateTimeImmutable`, not `\DateTimeInterface` - interface allows both, defeats the rule.

## 14. Missing locale + channel on user-scoped resource

❌ Wrong:

```php
class BackInStockSubscription
{
    #[ORM\Column]
    private string $email;

    #[ORM\ManyToOne(targetEntity: ProductVariantInterface::class)]
    private ProductVariantInterface $variant;
}
```

✅ Right:

```php
class BackInStockSubscription
{
    #[ORM\Column]
    private string $email;

    #[ORM\Column(length: 16)]
    private string $localeCode;

    #[ORM\ManyToOne(targetEntity: ChannelInterface::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ChannelInterface $channel;

    #[ORM\ManyToOne(targetEntity: ProductVariantInterface::class)]
    private ProductVariantInterface $variant;
}
```

Mailer dispatch:

```php
$this->sender->send(
    'back_in_stock',
    [$subscription->getEmail()],
    [
        'subscription' => $subscription,
        'channel' => $subscription->getChannel(),
        'locale' => $subscription->getLocaleCode(),
    ],
);
```

Email template renders strings via `'...'|trans({}, 'messages', subscription.localeCode)`.

**Why:** User signed up in locale `pl_PL` on channel `FASHION_WEB`. Mailer rendered later (often async, often on different request) has no implicit locale/channel context. Persisting both pins render fidelity. `localeCode` length 16 matches Sylius core convention (`en_US`, `pt_BR_xx`).

## 15. Backslash in template path

❌ Wrong:

```yaml
sylius_grid:
    grids:
        app_admin_back_in_stock_subscription:
            fields:
                createdAt:
                    type: twig
                    options:
                        template: '@SyliusAdmin\shared\grid\field\datetime.html.twig'
```

✅ Right:

```yaml
sylius_grid:
    grids:
        app_admin_back_in_stock_subscription:
            fields:
                createdAt:
                    type: twig
                    options:
                        template: '@SyliusAdmin/shared/grid/field/datetime.html.twig'
```

**Why:** Twig namespaces always use `/`. `\` is PHP namespace separator; copy/pasting it into a yaml template string silently breaks resolution at runtime. Lint won't catch.

## 16. Bare RepositoryInterface for core resource

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

## 17. Hand-rolled form open tag

❌ Wrong:

```twig
<form method="post" action="{{ path('app_shop_back_in_stock_subscribe') }}">
    {{ form_row(form.email) }}
    <input type="hidden" name="{{ form._token.vars.full_name }}" value="{{ form._token.vars.value }}">
    <button>Notify me</button>
</form>
```

✅ Right:

```twig
{{ form_start(form, {action: path('app_shop_back_in_stock_subscribe')}) }}
    {{ form_row(form.email) }}
    <button>{{ 'app.ui.notify_me'|trans }}</button>
{{ form_end(form) }}
```

**Why:** `form_start` emits correct `method`, `action`, `enctype` (multipart on file fields), and theme-driven classes. `form_end` emits CSRF token + any unrendered fields. Manual `<form>` skips theme; manual `_token` render breaks if the form template changes its token field name.

## 18. Doctrine column without explicit snake_case name

❌ Wrong:

```php
#[ORM\Column(type: 'datetime_immutable')]
private \DateTimeImmutable $createdAt;

#[ORM\Column(length: 16)]
private string $localeCode;
```

✅ Right:

```php
#[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
private \DateTimeImmutable $createdAt;

#[ORM\Column(name: 'locale_code', length: 16)]
private string $localeCode;
```

**Why:** Doctrine's default naming strategy yields inconsistent column names across mappers and SQL drivers, and Sylius core uses snake_case columns everywhere. Mixing produces joins that fail at schema-validate time only on specific drivers. Always pin `name:` explicitly when property is camelCase.

## 19. AbstractResourceType without explicit service registration

❌ Wrong (file present, no yaml):

```php
final class BackInStockNotificationType extends AbstractResourceType
{
    protected function getBlockPrefix(): string
    {
        return 'app_back_in_stock_notification';
    }
}
```

`services.yaml`: untouched. Sylius-Standard already declares:

```yaml
_instanceof:
    Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType:
        autowire: false
```

Symfony Form factory falls back to `new BackInStockNotificationType()` → fatal: "Too few arguments to function AbstractResourceType::__construct(), 0 passed".

✅ Right:

```yaml
# config/services.yaml
services:
    app.form.type.back_in_stock_notification:
        class: App\Form\Type\BackInStock\BackInStockNotificationType
        arguments:
            - 'App\Entity\BackInStock\BackInStockNotification'
            - ['sylius']
        tags:
            - { name: form.type }

    App\Form\Type\BackInStock\BackInStockNotificationType:
        alias: app.form.type.back_in_stock_notification
```

**Why:** `_instanceof: autowire: false` silently disables autowire for every `AbstractResourceType` descendant. `debug:container` looks normal - there is no service to fail. Symfony's fallback to `new <FQCN>()` then dies at first form render. Static checks all green.

## 20. AsTwigComponent without template attribute

❌ Wrong:

```php
#[AsTwigComponent('app_back_in_stock')]
final class BackInStockSubscribeComponent
{
    public ?string $template = null;
}
```

Hook config:

```yaml
sylius_twig_hooks:
    hooks:
        sylius_shop.product.show.content.info:
            back_in_stock:
                component: 'App\Twig\Component\BackInStockSubscribeComponent'
                template: 'shop/product/back_in_stock.html.twig'
```

The `template:` in hook config binds to the component's public `$template` prop - does NOT redirect the renderer. UX TwigComponent then tries to auto-resolve `templates/components/BackInStockSubscribeComponent.html.twig` and 404s.

✅ Right:

```php
#[AsTwigComponent('app_back_in_stock', template: 'shop/product/back_in_stock.html.twig')]
final class BackInStockSubscribeComponent
{
}
```

**Why:** Sylius core uses a custom `sylius.twig_component` tag + compiler pass to honor hook-passed `template:`. Plain UX `#[AsTwigComponent]` does not. Always declare `template:` on the attribute itself.

## 21. Dispatching from onFlush

❌ Wrong:

```php
public function onFlush(OnFlushEventArgs $args): void
{
    // ... detect change ...
    $this->bus->dispatch(new ProductBackInStock($code));
}
```

Or:

```php
public function onFlush(OnFlushEventArgs $args): void
{
    register_shutdown_function(function () use ($code) {
        $this->bus->dispatch(new ProductBackInStock($code));
    });
}
```

✅ Right - split `onFlush` (collect) + `postFlush` (dispatch):

```php
private array $collected = [];

public function onFlush(OnFlushEventArgs $args): void
{
    $uow = $args->getObjectManager()->getUnitOfWork();
    foreach ($uow->getScheduledEntityUpdates() as $entity) {
        if (!$entity instanceof ProductVariantInterface) continue;
        $changes = $uow->getEntityChangeSet($entity);
        if (!isset($changes['onHand'])) continue;
        [$old, $new] = $changes['onHand'];
        if ($old <= 0 && $new > 0) {
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
```

**Why:** UoW is mid-flush during `onFlush` - any downstream code that flushes (e.g. message handler with sync transport) corrupts the active UoW or no-ops. `register_shutdown_function` fires after PHP starts tearing down - EM may already be gone. Only `postFlush` is safe.

## 22. Email template without layout + blocks

❌ Wrong:

```twig
<h1>Back in stock</h1>
<p>{{ variant.product.name }} is back.</p>
```

Or extends layout but missing a block:

```twig
{% extends '@SyliusCore/Email/layout.html.twig' %}
{% block content %}<p>...</p>{% endblock %}
```

Or top-level `set`:

```twig
{% set translation_locale = notification.localeCode %}
{% extends '@SyliusCore/Email/layout.html.twig' %}

{% block subject %}
    {{ 'app.email.x.subject'|trans({}, 'messages', translation_locale) }}
{% endblock %}
```

Sylius mailer renders `subject` block standalone → top-level `set` never runs → "Variable translation_locale does not exist".

✅ Right:

```twig
{% extends '@SyliusCore/Email/layout.html.twig' %}

{% block subject %}
    {% set translation_locale = notification.localeCode %}
    {{ 'app.email.x.subject'|trans({}, 'messages', translation_locale) }}
{% endblock %}

{% block content %}
    {% set translation_locale = notification.localeCode %}
    <p>{{ 'app.email.x.body'|trans({'%product%': variant.product.name}, 'messages', translation_locale) }}</p>
{% endblock %}
```

**Why:** Sylius mailer renders `subject` and `content` blocks separately (different rendering passes). Plain HTML or missing-block templates produce "Block subject does not exist". Top-level `set` runs only when the template is rendered as a whole, which mailer doesn't do.

## 23. Mailer send without channel + localeCode

❌ Wrong:

```php
$this->sender->send('back_in_stock', [$notification->getEmail()], [
    'notification' => $notification,
]);
```

✅ Right:

```php
$channel = $this->channelRepository->findOneByCode($notification->getChannelCode());

$this->sender->send('back_in_stock', [$notification->getEmail()], [
    'notification' => $notification,
    'variant' => $variant,
    'channel' => $channel,
    'localeCode' => $notification->getLocaleCode(),
]);
```

**Why:** `@SyliusCore/Email/layout.html.twig` calls `sylius_channel_url(asset(...), channel)` and uses `channel` for logo/footer. Missing `channel` = render error. Missing `localeCode` (and pulling from current request instead of the persisted resource) = email rendered in worker's default locale, not the subscriber's.

## 24. Wrong ChannelRepositoryInterface import

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

## 25. Translation file with locale shorthand

❌ Wrong (shop runs `en_US`):

```
translations/messages.en.yaml
```

Until cache is rewarmed, raw keys render in emails / templates.

✅ Right:

```
translations/messages.en_US.yaml
```

Plus print to user: "Run `bin/console cache:clear` manually - new translation catalog requires rewarmed cache."

**Why:** Symfony's translator falls back through the locale chain, but a freshly created catalog file isn't picked up until cache rebuild. Project rule forbids auto `cache:clear`, so the fallback never kicks in for a new file with a non-matching name. Match the exact locale string from `framework.default_locale` / `sylius_locale`.

## 26. Custom factory with wrong constructor signature

❌ Wrong:

```yaml
sylius_resource:
    resources:
        app.back_in_stock_notification:
            classes:
                factory: App\Factory\BackInStockNotificationFactory
```

```php
final class BackInStockNotificationFactory implements BackInStockNotificationFactoryInterface
{
    public function __construct(
        private BackInStockNotificationRepositoryInterface $repository,
    ) {
    }
}
```

At runtime: `TypeError: must be of type FactoryInterface, string given`. `debug:container` clean.

✅ Right (default factory - drop `classes.factory:`):

```yaml
sylius_resource:
    resources:
        app.back_in_stock_notification:
            classes:
                model: App\Entity\BackInStock\BackInStockNotification
                # no factory: Sylius wires default Factory automatically
```

✅ Right (custom factory genuinely needed):

```php
final class BackInStockNotificationFactory implements BackInStockNotificationFactoryInterface
{
    public function __construct(private string $className) {}

    public function createNew(): BackInStockNotificationInterface
    {
        return new ($this->className)();
    }

    public function createForVariant(ProductVariantInterface $variant): BackInStockNotificationInterface
    {
        $notification = $this->createNew();
        $notification->setVariant($variant);
        return $notification;
    }
}
```

**Why:** Sylius resource-bundle compiler pass injects the entity FQCN string into the factory constructor's first arg. Custom constructors with non-`__construct(string $className)` signatures crash at first `createNew()`. Default factory avoids the trap entirely - only add a custom one for pre-construction behavior.

## 27. Duplicate resource segment in route prefix

❌ Wrong (`config/routes/admin/back_in_stock_notification.yaml`):

```yaml
app_admin_back_in_stock_notification:
    resource: |
        alias: app.back_in_stock_notification
        section: admin
        ...
    type: sylius.resource
    prefix: '/%sylius_admin.path_name%/back-in-stock-notifications'
```

Resulting routes: `/admin/back-in-stock-notifications/back-in-stock-notifications/`, `/admin/back-in-stock-notifications/back-in-stock-notifications/new`, …

✅ Right:

```yaml
app_admin_back_in_stock_notification:
    resource: |
        alias: app.back_in_stock_notification
        section: admin
        ...
    type: sylius.resource
    prefix: '/%sylius_admin.path_name%'
```

**Why:** The `sylius.resource` route loader auto-derives the path segment from the resource alias plural (`back_in_stock_notification` → `back-in-stock-notifications`). Outer `prefix:` must contain ONLY the admin/shop root, never the resource segment.

## 28. Listener collecting autoincrement ids

❌ Wrong:

```php
public function onFlush(OnFlushEventArgs $args): void
{
    foreach ($uow->getScheduledEntityUpdates() as $entity) {
        if ($entity instanceof ProductVariantInterface) {
            $this->collected[] = $entity->getId();
        }
    }
}

public function postFlush(PostFlushEventArgs $args): void
{
    foreach ($this->collected as $id) {
        $this->bus->dispatch(new ProductBackInStock($id));
    }
}
```

✅ Right:

```php
$this->collected[] = (string) $entity->getCode();
// ...
$this->bus->dispatch(new ProductBackInStock($code));
```

Handler:

```php
$variant = $this->variants->findOneBy(['code' => $message->variantCode]);
```

**Why:** IDs change across env restores, fixture reloads, snapshots, replays. Code/UUID is the stable business identifier. Indexed-column `findOneBy(['code' => …])` is the same cost as `find($id)`. **Exception:** entities without natural codes (`Adjustment`, `OrderItemUnit`) - use id, document why.

## 29. Mailer code without app_ prefix

❌ Wrong:

```yaml
sylius_mailer:
    emails:
        back_in_stock:
            subject: app.email.back_in_stock.subject
            template: 'email/back_in_stock.html.twig'
```

✅ Right:

```yaml
sylius_mailer:
    emails:
        app_back_in_stock:
            subject: app.email.back_in_stock.subject
            template: 'email/back_in_stock.html.twig'
```

`$this->sender->send('app_back_in_stock', ...)`.

**Why:** Sylius core ships codes like `order_confirmation`, `password_reset_token`, `customer_registration`, `contact_request`. Bare app code risks future collision when Sylius adds a new built-in. `app_` prefix isolates the namespace cheaply.

## 30. Migration with scaffold stubs

❌ Wrong:

```php
/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ...');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE ...');
    }
}
```

✅ Right:

```php
final class Version20260519120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create app_back_in_stock_notification table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ...');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ...');
    }
}
```

**Why:** Stubs are noise in committed history. `getDescription()` is what `doctrine:migrations:list` shows - empty = useless. Cosmetic but trivially fixable; always strip.

## 31. Twig Component in Symfony-UX default path

❌ Wrong:

```
src/Twig/Components/BackInStockSubscribeComponent.php
namespace App\Twig\Components;
```

✅ Right:

```
src/TwigComponent/BackInStock/BackInStockSubscribeComponent.php
namespace App\TwigComponent\BackInStock;
```

**Why:** Sylius bundle convention is `Sylius\Bundle\<X>Bundle\TwigComponent\`. App-level mirrors as `App\TwigComponent\<Section>\`. Plugin authors and core devs look in the Sylius-convention path; UX default breaks the contract.

## 32. Single-step Playwright spec

❌ Wrong: spec drives only the subscribe step, asserts only the success flash. Misses restock + email + post-state.

✅ Right: spec covers the whole user journey - setup → subscribe → success flash → restock → email assertion via profiler MCP → post-state widget hidden.

**Why:** Bugs surface across step boundaries (listener idempotency, stale cache after state change, mailer ctx wrong, locale mismatch on async render). A spec that stops at step 3 of a 6-step flow rubber-stamps regressions.

## 33. Explicit service def in non-excluded dir

❌ Wrong: `config/services/app_back_in_stock_notification.yaml` declares form type + listener with tags, but `config/services.yaml` has:

```yaml
App\:
    resource: '../src/'
    exclude:
        - '../src/Entity/'
        - '../src/Kernel.php'
```

Symptoms (silent):
- Form type: `new BackInStockNotificationType()` → `Too few arguments to function AbstractResourceType::__construct()` at first render.
- Listener: `doctrine.event_listener` tag dropped. Restock event fires, listener never runs. No mailer dispatch.

`debug:container` may show the class with a `form.type` tag (autoconfigure from `_instanceof`), masking the failure.

✅ Right:

```yaml
App\:
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

**Why:** `App\:` glob autoregister loads after `imports:` and silently re-binds every class found under `resource:`, dropping any explicit `arguments` / `tags`. Adding the dir to `exclude:` keeps the autoregister out of that subtree. Always pair an explicit-def write with an exclude-list update.

## 34. Playwright spec mutating via raw SQL

❌ Wrong (inside a Playwright spec):

```ts
execSync(`bin/console doctrine:query:sql "UPDATE sylius_product_variant SET on_hand = 10 WHERE code = 'TSHIRT_S'"`);
```

Spec asserts `notified_at IS NOT NULL` later. Fails: listener never fired, handler never dispatched, mailer never sent.

✅ Right:

```ts
execSync(`bin/console app:variant:restock TSHIRT_S 10`);
```

Or drive the admin UI flow via Playwright (login → variant edit → save). Or hit an API endpoint that mutates through ORM.

**Why:** Doctrine listeners (`onFlush`, `postFlush`, `preUpdate`, etc.) hook into the UnitOfWork. Raw SQL goes straight to the DB driver, never touches UoW, listeners never see the change. Iter-7 documented this in a postmortem; iter-8 spec violated it. Lesson now baked in as rule.

## 35. Ad-hoc cache:clear via Bash

❌ Wrong:

```bash
bin/console cache:clear
```

…called during exploration, debugging, or as part of verify. CLAUDE.md explicitly forbids.

✅ Right:

Call MCP tool `sylius_cache_clear` exactly once before Playwright. The MCP tool is the boundary gate.

**Why:** Iter-7 tried to carve a Bash exception scoped to the verify step. Iter-8 showed the global rule won anyway - Claude refused the scoped clear. Moving the action to an MCP tool sidesteps the rule entirely; the tool is a mechanical override, not a shell call.

## 36. Leaf hook target without parent visibility check

❌ Wrong: picking `sylius_shop.product.show.content.info.summary.add_to_cart` for a back-in-stock widget because the name suggests product page placement.

Parent template:

```twig
{% if product.enabledVariants.empty() or product.simple and not sylius_inventory_is_available(...) %}
    <div>Out of stock</div>
{% else %}
    {% hook 'add_to_cart' %}   {# ← never fires for OOS #}
{% endif %}
```

Widget for out-of-stock variants → hook never renders. Silent failure.

✅ Right:

Call MCP `sylius_hooks_resolve_for_visibility` with feature state (`oos`):

```yaml
sylius_twig_hooks:
    hooks:
        sylius_shop.product.show.content.info.summary:   # parent - unconditional
            app_back_in_stock_widget:
                template: 'shop/product/back_in_stock_widget.html.twig'
```

**Why:** hook names suggest placement but not visibility. Parent template guards short-circuit entire sub-trees. For OOS-only / in-stock-only features, the relevant branch may be dead. Resolver tool returns hook targets whose parent renders in the requested state. Never guess from name suffix.

## 37. notified_at as email proof

❌ Wrong (Playwright spec):

```ts
const row = await db.query('SELECT notified_at FROM app_back_in_stock_notification WHERE email = ?', [email]);
expect(row.notified_at).not.toBeNull();
```

Handler:

```php
foreach ($notifications as $notification) {
    $this->sender->send('app_back_in_stock', [$notification->getEmail()], $context);
    $notification->markNotified();
}
```

With `MAILER_DSN=null://null`, `sender->send()` swallows silently. Handler still calls `markNotified()`. DB row updated. Assertion green. No email actually delivered.

✅ Right:

```ts
const messages = await fetch('http://localhost:8025/api/v1/messages').then(r => r.json());
const match = messages.messages.find(m =>
    m.To.some(t => t.Address === email) && m.Subject.includes('back in stock')
);
expect(match).toBeDefined();
```

Or via Sylius Mate profiler MCP when restock is HTTP-triggered:

```
profiler_request_detail(token).mailer → assert message present
```

If neither inspectable target available, print `// TODO: assert email via mailpit/profiler` - do not green-light the spec on a DB check.

**Why:** the handler's success path doesn't depend on transport success. `notified_at` proves the handler ran, not that the email left the building. Iter-9 false-positive lesson.

## 38. RuntimeException for not-found in controller

❌ Wrong:

```php
$variant = $this->variants->findOneBy(['code' => $code]);
if (null === $variant) {
    throw new \RuntimeException('Variant not found.');
}
```

Symfony renders HTTP 500 with stack trace.

✅ Right:

```php
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$variant = $this->variants->findOneBy(['code' => $code]);
if (null === $variant) {
    throw new NotFoundHttpException();
}
```

**Why:** `NotFoundHttpException` is a Symfony HTTP exception → kernel renders the 404 template (Sylius error pages). `\RuntimeException` is generic → kernel treats as unhandled → 500 + stack trace. For resource-not-found from a path param, always `NotFoundHttpException`.

## 39. Hardcoded mailer subject

❌ Wrong:

```yaml
sylius_mailer:
    emails:
        app_back_in_stock:
            subject: 'Product back in stock'
            template: 'email/app_back_in_stock.html.twig'
```

✅ Right:

```yaml
sylius_mailer:
    emails:
        app_back_in_stock:
            subject: app.email.back_in_stock.subject
            template: 'email/app_back_in_stock.html.twig'
```

Plus translation entry in `translations/messages.<locale>.yaml`.

**Why:** template `{% block subject %}` overrides at render time, but the config-level subject is what `messenger:debug`, admin email logs, and other tooling display. Translation-key indirection keeps the config readable across locales and matches Sylius core conventions.

## 40. Missing parent::buildForm in AbstractResourceType

❌ Wrong:

```php
final class BackInStockNotificationType extends AbstractResourceType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class)
        ;
    }
}
```

✅ Right:

```php
public function buildForm(FormBuilderInterface $builder, array $options): void
{
    parent::buildForm($builder, $options);

    $builder
        ->add('email', EmailType::class)
    ;
}
```

**Why:** `AbstractResourceType::buildForm()` is empty today but is the documented extension point - Sylius may add behavior (event-driven field wiring, validation group propagation) in any minor. Skipping `parent::` saves zero lines and bets against future-proofing.

## 41. EntityManager in feature handler

❌ Wrong:

```php
#[AsMessageHandler]
final class ProductBackInStockHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private BackInStockNotificationRepositoryInterface $notifications,
        private SenderInterface $sender,
    ) {
    }

    public function __invoke(ProductBackInStock $m): void
    {
        foreach ($this->notifications->findByVariantCode($m->variantCode) as $n) {
            $this->sender->send(...);
            $n->markNotified();
            $this->em->flush();
        }
    }
}
```

✅ Right:

```php
public function __construct(
    private BackInStockNotificationRepositoryInterface $notifications,
    private SenderInterface $sender,
) {
}

public function __invoke(ProductBackInStock $m): void
{
    foreach ($this->notifications->findByVariantCode($m->variantCode) as $n) {
        $this->sender->send(...);
        $n->markNotified();
        $this->notifications->add($n);  // persist+flush idempotent for managed entities
    }
}
```

**Why:** "No `EntityManagerInterface` in feature code when a Resource exists" applies to controllers, handlers, listeners, services - anywhere the rule of resource-first composition holds. `EntityRepository::add()` is idempotent for both new and managed entities. Exception: bulk operations where DBAL/QueryBuilder beats per-row flush.

## 42. AbstractController in feature controller

❌ Wrong:

```php
final class SubscribeAction extends AbstractController
{
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(BackInStockNotificationType::class);
        // ...
        $this->addFlash('success', 'app.flashes.back_in_stock.subscribed');
        return $this->redirect($this->generateUrl('sylius_shop_product_show', [...]));
    }
}
```

✅ Right:

```php
final class SubscribeAction
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->formFactory->create(BackInStockNotificationType::class);
        // ...
        $request->getSession()->getFlashBag()->add('success', 'app.flashes.back_in_stock.subscribed');
        return new RedirectResponse(
            $this->urlGenerator->generate('sylius_shop_product_show', [...]),
        );
    }
}
```

**Why:** Invokable controllers make deps explicit, decouple from Symfony controller base, match Sylius shop conventions. `AbstractController` hides DI behind helper methods; one minor of Symfony changes them and feature controllers drift.

## 43. Doctrine listener attribute + yaml tag hybrid

❌ Wrong:

```php
#[AsDoctrineListener(event: Events::onFlush)]
final class ProductVariantRestockListener { }
```

```yaml
App\EventListener\ProductVariantRestockListener:
    tags:
        - { name: doctrine.event_listener, event: onFlush }
```

With `autoconfigure: true`, both registrations apply → listener fires twice per `onFlush`.

✅ Right (Pattern A - attribute only):

```php
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ProductVariantRestockListener { }
```

```yaml
App\EventListener\ProductVariantRestockListener: ~
```

**Why:** Attribute already declares the binding; yaml tag duplicates. Once you have the attribute, never re-tag in yaml. Pattern B fallback (no attribute + `autoconfigure: false` + tag) is for autowire-excluded dirs where the attribute can't fire.

## 44. Dual form-type service id + alias

❌ Wrong:

```yaml
app.form.type.back_in_stock_notification:
    class: App\Form\Type\Notification\BackInStockNotificationType
    arguments: ['App\Entity\Notification\BackInStockNotification', ['sylius']]
    tags:
        - { name: form.type }

App\Form\Type\Notification\BackInStockNotificationType:
    alias: app.form.type.back_in_stock_notification
```

✅ Right:

```yaml
App\Form\Type\Notification\BackInStockNotificationType:
    arguments:
        - 'App\Entity\Notification\BackInStockNotification'
        - ['sylius']
    tags:
        - { name: form.type }
```

**Why:** Symfony Form factory resolves `createForm(<X>Type::class)` directly via container service at FQCN id. The legacy `app.form.type.<x>` + alias pattern is two declarations for one wiring - one trap door fewer with the FQCN-keyed form.

## 45. Manual repo-interface alias for app resource

❌ Wrong:

```yaml
App\Repository\BackInStockNotificationRepositoryInterface:
    alias: app.repository.back_in_stock_notification
```

…when the resource is registered:

```yaml
sylius_resource:
    resources:
        app.back_in_stock_notification:
            classes:
                model: App\Entity\Notification\BackInStockNotification
                repository: App\Repository\BackInStockNotificationRepository
```

✅ Right: drop the alias entirely. Sylius 2.x resource-bundle compiler pass auto-aliases interface FQCN → `app.repository.<alias>`. Verify via `bin/console debug:container --filter=BackInStockNotificationRepositoryInterface`.

**Why:** Duplicate. Sylius core repos (Product, Channel, etc.) DO need manual aliases (R-CORE-REPO-ALIASES) because they aren't auto-aliased to interface FQCNs - different concern, keep those.

## 46. Bare hook template vars

❌ Wrong:

```twig
{% if variant and not variant.tracked %}
    <p>{{ variant.product.name }}</p>
{% endif %}
```

When the hook position doesn't auto-pass `variant`, the template silently sees `null` → empty render.

✅ Right:

```twig
{% set variant = hookable_metadata.context.variant ?? null %}
{% set product = hookable_metadata.context.product ?? null %}

{% if variant and not variant.tracked %}
    <p>{{ product.name }}</p>
{% endif %}
```

**Why:** Hook context is not guaranteed to inject specific names as bare vars; the safe access is via `hookable_metadata.context`. Destructure once at the top, then code against locals.

## 47. URL hand-rolled in email

❌ Wrong:

```twig
<a href="{{ sylius_channel_url(asset(''), channel) ~ '/products/' ~ product.translation(localeCode|split('_')[0]).slug }}">
```

Two bugs: (1) string concatenation breaks on localized prefixes, (2) `localeCode|split('_')[0]` strips region → Sylius URLs use full locale.

✅ Right:

```twig
<a href="{{ url('sylius_shop_product_show', {slug: product.translation(localeCode).slug, _locale: localeCode}) }}">
```

Plus `framework.router.default_uri` in `messenger.yaml` for async handlers (no Request context):

```yaml
framework:
    router:
        default_uri: '%env(APP_DEFAULT_URI)%'
```

**Why:** `url()` generates absolute URL via router defaults; respects locale prefix / channel hostname / port. Hand-rolled concat breaks the moment any of those change. Region must remain (`en_US`, not `en`) - Sylius routes match full locale.

## 48. App\Repository sub-namespace

❌ Wrong: `App\Repository\Notification\BackInStockNotificationRepository`.

✅ Right: `App\Repository\BackInStockNotificationRepository`.

Pinned convention:

| Dir | Sub-NS? |
|---|---|
| `App\Entity\<Feature>\<X>` | ✅ |
| `App\Form\Type\<Feature>\<X>Type` | ✅ |
| `App\Repository\<X>Repository` | ❌ FLAT |
| `App\Factory\<X>Factory` | ❌ FLAT |
| `App\EventListener\<X>Listener` | ❌ FLAT |
| `App\Message\<X>` | ❌ FLAT |
| `App\MessageHandler\<X>Handler` | ❌ FLAT |

**Why:** Repositories/factories/listeners/messages/handlers are infrastructure leaves - one per resource, no taxonomy benefit from nesting. Entities and form types have multiple variants per feature (Translation, Variant, Type), so sub-namespacing earns its keep there.

## 49. Feature without admin grid

❌ Wrong: persisted resource ships entity + form + shop UI + listener + mailer. No admin grid, no admin route. Marked "done".

✅ Right: every persisted-state feature ships:

- `sylius_grid.grids.app_admin_<feature>` with fields/filters/actions.
- `config/routes/admin/<feature>.yaml` via `type: sylius.resource`.
- `bin/console debug:router | grep app_admin_<feature>_index` returns a hit.

**Why:** Admins need to view, filter, edit, and delete the data. The CRUD is 30 lines of yaml vs hundreds of custom controller. R-FEATURE-DONE-INCLUDES-ADMIN is a gate, not a suggestion - verify before declaring done.

## 50. Hardcoded App\ namespace

❌ Wrong (Elesto project - root NS = `Elesto\`):

```php
namespace App\Entity\BackInStock;

class BackInStockNotification { }
```

Or in yaml:

```yaml
sylius_resource:
    resources:
        elesto.back_in_stock_notification:
            classes:
                model: App\Entity\BackInStock\BackInStockNotification
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
namespace Elesto\Entity\BackInStock;

class BackInStockNotification { }
```

```yaml
sylius_resource:
    resources:
        elesto.back_in_stock_notification:
            classes:
                model: Elesto\Entity\BackInStock\BackInStockNotification
```

**Why:** Sylius-Standard is one project. `App\` is not universal. Always read `composer.json` `autoload.psr-4` first matching `src/` entry. MCP `sylius_project_profile.app_namespace` returns it. Parameterize every scaffold.

## 51. Inventory listener under MSI plugin

❌ Wrong (project has `sylius/multi-source-inventory-plugin`):

```php
#[AsDoctrineListener(event: Events::onFlush)]
final class ProductVariantRestockListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof ProductVariantInterface) continue;
            $changes = $uow->getEntityChangeSet($entity);
            if (!isset($changes['onHand'])) continue;   // ← never fires under MSI
        }
    }
}
```

`ProductVariant.onHand` column is dead under MSI; stock changes happen on `InventorySourceStock` rows.

✅ Right: target the MSI entity:

```php
foreach ($uow->getScheduledEntityUpdates() as $entity) {
    if (!$entity instanceof InventorySourceStockInterface) continue;
    $changes = $uow->getEntityChangeSet($entity);
    if (!isset($changes['onHand'])) continue;
    [$old, $new] = $changes['onHand'];
    if ($old <= 0 && $new > 0) {
        $this->collected[] = (string) $entity->getProductVariant()->getCode();
    }
}
```

**Why:** Plugin decorations move the source of truth. Always call MCP `sylius_installed_plugins` before designing a listener / inventory checker / price service / channel resolver. Skip → ship dead code.

## 52. Manual service in excluded dir without explicit autowire

❌ Wrong:

```yaml
# config/services.yaml
<AppNs>\:
    resource: '../src/'
    exclude: ['../src/Controller/']

<AppNs>\Controller\Shop\BackInStockSubscribeController:
    tags: ['controller.service_arguments']
```

Sibling controllers in `services.yaml` set `autowire: false`. AI's controller inherits silently → runtime `Too few arguments`.

✅ Right:

```yaml
<AppNs>\Controller\Shop\BackInStockSubscribeController:
    autowire: true
    autoconfigure: true
    tags: ['controller.service_arguments']
```

**Why:** `_defaults` inheritance is opaque when neighboring defs override. Always explicit on manual defs in excluded dirs. `bin/console debug:container --show-arguments <FQCN>` is the catch.

## 53. Single translation file in multi-locale project

❌ Wrong (project ships `en_US` + `pl_PL`):

```
translations/messages.en_US.yaml
```

Polish keys render raw.

✅ Right:

```
translations/messages.en_US.yaml
translations/messages.pl_PL.yaml
```

**Why:** Read `sylius_locale.locales` (or fallback `framework.default_locale`). Emit one file per enabled locale. MCP `sylius_project_profile.locales` returns the list. Default to "single en_US file" only when project actually ships only en_US.

## 54. Async URL generation without default_uri

❌ Wrong:

```php
#[AsMessageHandler]
final class ProductBackInStockHandler
{
    public function __invoke(ProductBackInStock $m): void
    {
        $this->sender->send('app_back_in_stock', [...], [
            // template uses {{ url('sylius_shop_product_show', ...) }}
        ]);
    }
}
```

No `framework.router.default_uri` set. `url()` renders `http:///products/...` (empty host) or throws "no current request".

✅ Right:

```yaml
# config/packages/framework.yaml
framework:
    router:
        default_uri: '%env(APP_DEFAULT_URI)%'
```

```env
APP_DEFAULT_URI=https://shop.example.com
```

**Why:** Messenger handlers run outside HTTP context - router needs an explicit default to generate absolute URLs. Verify with `bin/console debug:config framework.router` before relying on `url()` in async paths.

## 55. Dead exception catch

❌ Wrong:

```php
try {
    $channel = $this->channelContext->getChannel();
} catch (ChannelNotFoundException) {
    return new Response('', 404);
}
```

…when the route is shop-scoped (channel always resolved by firewall).

✅ Right: drop the try/catch.

**Why:** Reads like defensive code, actually never triggers. Confuses readers about real failure modes.
