# Reference - Sylius Mailer

Deep dive on email registration + send path.

## Registration

`config/packages/_sylius_mailer.yaml`:

```yaml
sylius_mailer:
    emails:
        app_<code>:
            subject: app.email.<code>.subject
            template: 'email/<code>.html.twig'
```

`templates/email/<code>.html.twig` (R-EMAIL-LAYOUT):

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

A filled-in instance (`app_back_in_stock`, with a product-name interpolation)
is in `reference/worked-example.md`.

Rules:

- Extends `@SyliusCore/Email/layout.html.twig` (footer, channel logo, asset urls).
- Both `{% block subject %}` and `{% block content %}` defined - mailer renders them in separate passes.
- Helper `{% set %}` inside each block, not at top level. `subject` block is rendered standalone, top-level `set` does not propagate.

Translation files: `translations/messages.<exact_locale>.yaml`. Match the shop locale string exactly (`en_US`, not `en`). Translator only picks up new catalogs on cache rebuild - cache handling is owned by MCP `sylius_cache_clear` or project setup, never by this skill (see SKILL.md "Cache Clear").

## Sending

Inject `Sylius\Component\Mailer\Sender\SenderInterface` plus channel repo:

```php
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface; // NOT Core\Repository - doesn't exist
use Sylius\Component\Mailer\Sender\SenderInterface;

public function __construct(
    private SenderInterface $sender,
    private ChannelRepositoryInterface $channels,
) {
}

$channel = $this->channels->findOneByCode($entity->getChannelCode());

$this->sender->send(
    'app_<code>',
    [$entity->getEmail()],
    [
        'entity' => $entity,
        'channel' => $channel,
        'localeCode' => $entity->getLocaleCode(),
    ],
);
```

Code (`app_<code>`) matches `sylius_mailer.emails.<code>`. Context MUST include `channel` + `localeCode` (from the persisted resource, not the current request) - see R-MAILER-CTX.

## Per-channel template

Channel-aware override: place template at `templates/email/<channel_code>/<code>.html.twig`. Sylius resolves channel-scoped path first, falls back to default.

For per-channel subject/sender, configure under `sylius_mailer.channels.<code>.emails.<code>`.

## Async send

Wrap in Messenger:

```php
final class <X>Triggered
{
    public function __construct(public string $email, public string $code) {}
}

#[AsMessageHandler]
final class <X>Handler
{
    public function __construct(
        private SenderInterface $sender,
        private <Name>RepositoryInterface $entities,
    ) {}

    public function __invoke(<X>Triggered $m): void
    {
        $entity = $this->entities->findOneByCode($m->code);
        $this->sender->send('app_<code>', [$m->email], ['entity' => $entity]);
    }
}
```

Route message to `async` transport.

## Verify

- `sylius_mailer_verify_template <code>` - confirms email registered + template resolves.
- `bin/console lint:twig templates/email/`.
- `bin/console messenger:debug` - handler registered.
