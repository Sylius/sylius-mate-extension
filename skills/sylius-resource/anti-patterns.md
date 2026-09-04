# Anti-Patterns - resource

Entity, repository, factory, form, grid, route, migration, fixture and controller traps. Each entry: ❌ wrong, ✅ right, why.

## 1. Plain Doctrine entity

❌ Wrong:

```php
#[ORM\Entity]
class <Name>
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
}
```

✅ Right:

```php
interface <Name>Interface extends ResourceInterface
{
}

#[ORM\Entity]
class <Name> implements <Name>Interface
{
}
```

```yaml
sylius_resource:
    resources:
        app.<alias>:
            classes:
                model: <AppNs>\Entity\<Name>
                interface: <AppNs>\Entity\<Name>Interface
                repository: <AppNs>\Repository\<Name>Repository
                form: <AppNs>\Form\Type\<Name>Type
```

**Why:** Resource registration unlocks factory, repo, admin grid, events, API auto-wiring. Plain Doctrine = manual everything + breaks plugin extension points.

`classes.factory:` omitted - Sylius default `Sylius\Resource\Factory\Factory` wires automatically. Declare a custom factory only when pre-construction behavior is needed; constructor MUST be `__construct(string $className)`.

## 2. AbstractType for resource form

❌ Wrong:

```php
final class <Name>Type extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'app_<alias>';
    }
}
```

✅ Right:

```php
final class <Name>Type extends AbstractResourceType
{
    protected function getBlockPrefix(): string
    {
        return 'app_<alias>';
    }
}
```

**Why:** `AbstractResourceType` wires `data_class` from resource registry, supports validation groups per resource. Plain `AbstractType` reinvents this and breaks resource override.

## 3. Hand-rolled `<input>`

❌ Wrong:

```twig
<form method="post" action="{{ path('<route>') }}">
    <input type="email" name="email" required>
    <button>Submit</button>
</form>
```

✅ Right:

```twig
{{ form_start(form) }}
    {{ form_row(form.email) }}
    <button>{{ 'app.ui.submit'|trans }}</button>
{{ form_end(form) }}
```

**Why:** Hand-rolled inputs bypass CSRF, validation, theme, translation. Form rendering helpers integrate all of it.

## 4. Hand-written CREATE TABLE migration

❌ Wrong:

```php
public function up(Schema $schema): void
{
    $this->addSql('CREATE TABLE app_<alias> (id INT NOT NULL, ...)');
}
```

✅ Right:

```bash
bin/console doctrine:migrations:diff
```

Then review the generated file. Adjust only if diff is wrong.

**Why:** Hand-written migrations drift from mapping. `diff` is authoritative.

## 5. Missing admin grid

❌ Wrong: user-facing resource with no grid.

✅ Right:

```yaml
sylius_grid:
    grids:
        app_admin_<alias>:
            driver:
                name: doctrine/orm
                options:
                    class: <AppNs>\Entity\<Name>
            fields:
                email: { type: string, label: app.ui.email }
                createdAt: { type: datetime, label: sylius.ui.created_at }
            actions:
                main:
                    create: { type: create }
                item:
                    delete: { type: delete }
```

**Why:** Admin needs to view/manage. Grid yaml + route is 30 lines vs custom CRUD = hundreds.

## 6. Missing fixture

❌ Wrong: no fixture for new resource.

✅ Right:

```php
final class <Name>Fixture extends AbstractFixture
{
    public function getName(): string
    {
        return 'app_<alias>';
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
            $entity = $this->factory->createNew();
            $this->repository->add($entity);
        }
    }
}
```

**Why:** Sylius demo + CI runs depend on fixtures. New resource without fixture = blank in `sylius:fixtures:load`.

## 7. Mutable DateTime

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

## 8. Missing locale + channel on user-scoped resource

❌ Wrong:

```php
class <Name>
{
    #[ORM\Column]
    private string $email;

    #[ORM\ManyToOne(targetEntity: ProductVariantInterface::class)]
    private ProductVariantInterface $variant;
}
```

✅ Right:

```php
class <Name>
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
    'app_<code>',
    [$entity->getEmail()],
    [
        'entity' => $entity,
        'channel' => $entity->getChannel(),
        'locale' => $entity->getLocaleCode(),
    ],
);
```

Email template renders strings via `'...'|trans({}, 'messages', entity.localeCode)`.

**Why:** User signed up in locale `pl_PL` on channel `FASHION_WEB`. Mailer rendered later (often async, often on different request) has no implicit locale/channel context. Persisting both pins render fidelity. `localeCode` length 16 matches Sylius core convention (`en_US`, `pt_BR_xx`).

## 9. Hand-rolled form open tag

❌ Wrong:

```twig
<form method="post" action="{{ path('<route>') }}">
    {{ form_row(form.email) }}
    <input type="hidden" name="{{ form._token.vars.full_name }}" value="{{ form._token.vars.value }}">
    <button>Submit</button>
</form>
```

✅ Right:

```twig
{{ form_start(form, {action: path('<route>')}) }}
    {{ form_row(form.email) }}
    <button>{{ 'app.ui.submit'|trans }}</button>
{{ form_end(form) }}
```

**Why:** `form_start` emits correct `method`, `action`, `enctype` (multipart on file fields), and theme-driven classes. `form_end` emits CSRF token + any unrendered fields. Manual `<form>` skips theme; manual `_token` render breaks if the form template changes its token field name.

## 10. Doctrine column without explicit snake_case name

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

## 11. AbstractResourceType without explicit service registration

❌ Wrong (file present, no yaml):

```php
final class <Name>Type extends AbstractResourceType
{
    protected function getBlockPrefix(): string
    {
        return 'app_<alias>';
    }
}
```

`services.yaml`: untouched. Sylius-Standard already declares:

```yaml
_instanceof:
    Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType:
        autowire: false
```

Symfony Form factory falls back to `new <Name>Type()` → fatal: "Too few arguments to function AbstractResourceType::__construct(), 0 passed".

✅ Right:

```yaml
# config/services/app_<feature>.yaml
services:
    <AppNs>\Form\Type\<Feature>\<Name>Type:
        arguments:
            - '<AppNs>\Entity\<Feature>\<Name>'
            - ['sylius']
        tags:
            - { name: form.type }
```

**Why:** `_instanceof: autowire: false` silently disables autowire for every `AbstractResourceType` descendant. `debug:container` looks normal - there is no service to fail. Symfony's fallback to `new <FQCN>()` then dies at first form render. Static checks all green.

## 12. Custom factory with wrong constructor signature

❌ Wrong:

```yaml
sylius_resource:
    resources:
        app.<alias>:
            classes:
                factory: <AppNs>\Factory\<Name>Factory
```

```php
final class <Name>Factory implements <Name>FactoryInterface
{
    public function __construct(
        private <Name>RepositoryInterface $repository,
    ) {
    }
}
```

At runtime: `TypeError: must be of type FactoryInterface, string given`. `debug:container` clean.

✅ Right (default factory - drop `classes.factory:`):

```yaml
sylius_resource:
    resources:
        app.<alias>:
            classes:
                model: <AppNs>\Entity\<Feature>\<Name>
                # no factory: Sylius wires default Factory automatically
```

✅ Right (custom factory genuinely needed):

```php
final class <Name>Factory implements <Name>FactoryInterface
{
    public function __construct(private string $className) {}

    public function createNew(): <Name>Interface
    {
        return new ($this->className)();
    }

    public function createForVariant(ProductVariantInterface $variant): <Name>Interface
    {
        $entity = $this->createNew();
        $entity->setVariant($variant);
        return $entity;
    }
}
```

**Why:** Sylius resource-bundle compiler pass injects the entity FQCN string into the factory constructor's first arg. Custom constructors with non-`__construct(string $className)` signatures crash at first `createNew()`. Default factory avoids the trap entirely - only add a custom one for pre-construction behavior.

## 13. Duplicate resource segment in route prefix

❌ Wrong (`config/routes/admin/<alias>.yaml`):

```yaml
app_admin_<alias>:
    resource: |
        alias: app.<alias>
        section: admin
        ...
    type: sylius.resource
    prefix: '/%sylius_admin.path_name%/<alias-plural>'
```

Resulting routes: `/admin/<alias-plural>/<alias-plural>/`, `/admin/<alias-plural>/<alias-plural>/new`, …

✅ Right:

```yaml
app_admin_<alias>:
    resource: |
        alias: app.<alias>
        section: admin
        ...
    type: sylius.resource
    prefix: '/%sylius_admin.path_name%'
```

**Why:** The `sylius.resource` route loader auto-derives the path segment from the resource alias plural. Outer `prefix:` must contain ONLY the admin/shop root, never the resource segment.

## 14. Migration with scaffold stubs

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
        return 'Create app_<alias> table.';
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

## 15. RuntimeException for not-found in controller

❌ Wrong:

```php
$entity = $this->repository->findOneBy(['code' => $code]);
if (null === $entity) {
    throw new \RuntimeException('Not found.');
}
```

Symfony renders HTTP 500 with stack trace.

✅ Right:

```php
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$entity = $this->repository->findOneBy(['code' => $code]);
if (null === $entity) {
    throw new NotFoundHttpException();
}
```

**Why:** `NotFoundHttpException` is a Symfony HTTP exception → kernel renders the 404 template (Sylius error pages). `\RuntimeException` is generic → kernel treats as unhandled → 500 + stack trace. For resource-not-found from a path param, always `NotFoundHttpException`.

## 16. Missing parent::buildForm in AbstractResourceType

❌ Wrong:

```php
final class <Name>Type extends AbstractResourceType
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

## 17. AbstractController in feature controller

❌ Wrong:

```php
final class <X>Action extends AbstractController
{
    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(<Name>Type::class);
        // ...
        $this->addFlash('success', 'app.flashes.<feature>.done');
        return $this->redirect($this->generateUrl('sylius_shop_product_show', [...]));
    }
}
```

✅ Right:

```php
final class <X>Action
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->formFactory->create(<Name>Type::class);
        // ...
        $request->getSession()->getFlashBag()->add('success', 'app.flashes.<feature>.done');
        return new RedirectResponse(
            $this->urlGenerator->generate('sylius_shop_product_show', [...]),
        );
    }
}
```

**Why:** Invokable controllers make deps explicit, decouple from Symfony controller base, match Sylius shop conventions. `AbstractController` hides DI behind helper methods; one minor of Symfony changes them and feature controllers drift.

## 18. Dual form-type service id + alias

❌ Wrong:

```yaml
app.form.type.<alias>:
    class: <AppNs>\Form\Type\<Feature>\<Name>Type
    arguments: ['<AppNs>\Entity\<Feature>\<Name>', ['sylius']]
    tags:
        - { name: form.type }

<AppNs>\Form\Type\<Feature>\<Name>Type:
    alias: app.form.type.<alias>
```

✅ Right:

```yaml
<AppNs>\Form\Type\<Feature>\<Name>Type:
    arguments:
        - '<AppNs>\Entity\<Feature>\<Name>'
        - ['sylius']
    tags:
        - { name: form.type }
```

**Why:** Symfony Form factory resolves `createForm(<X>Type::class)` directly via container service at FQCN id. The legacy `app.form.type.<x>` + alias pattern is two declarations for one wiring - one trap door fewer with the FQCN-keyed form.

## 19. Manual repo-interface alias for app resource

❌ Wrong:

```yaml
<AppNs>\Repository\<Name>RepositoryInterface:
    alias: app.repository.<alias>
```

…when the resource is registered:

```yaml
sylius_resource:
    resources:
        app.<alias>:
            classes:
                model: <AppNs>\Entity\<Feature>\<Name>
                repository: <AppNs>\Repository\<Name>Repository
```

✅ Right: drop the alias entirely. Sylius 2.x resource-bundle compiler pass auto-aliases interface FQCN → `app.repository.<alias>`. Verify via `symfony-services --query=<Name>RepositoryInterface`.

**Why:** Duplicate. Sylius core repos (Product, Channel, etc.) DO need manual aliases (R-CORE-REPO-ALIASES) because they aren't auto-aliased to interface FQCNs - different concern, keep those.

## 20. Feature without admin grid

❌ Wrong: persisted resource ships entity + form + shop UI + listener + mailer. No admin grid, no admin route. Marked "done".

✅ Right: every persisted-state feature ships:

- `sylius_grid.grids.app_admin_<feature>` with fields/filters/actions.
- `config/routes/admin/<feature>.yaml` via `type: sylius.resource`.
- `bin/console debug:router | grep app_admin_<feature>_index` returns a hit.

**Why:** Admins need to view, filter, edit, and delete the data. The CRUD is 30 lines of yaml vs hundreds of custom controller. R-FEATURE-DONE-INCLUDES-ADMIN is a gate, not a suggestion - verify before declaring done.

## 21. Dead exception catch

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
