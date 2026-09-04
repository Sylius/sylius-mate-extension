# Anti-Patterns - mailer

Mailer config, email template, send context, URL and translation traps. Each entry: ❌ wrong, ✅ right, why.

## 1. Email template without layout + blocks

❌ Wrong:

```twig
<h1>Subject line</h1>
<p>{{ entity.someField }} happened.</p>
```

Or extends layout but missing a block:

```twig
{% extends '@SyliusCore/Email/layout.html.twig' %}
{% block content %}<p>...</p>{% endblock %}
```

Or top-level `set`:

```twig
{% set translation_locale = entity.localeCode %}
{% extends '@SyliusCore/Email/layout.html.twig' %}

{% block subject %}
    {{ 'app.email.<code>.subject'|trans({}, 'messages', translation_locale) }}
{% endblock %}
```

Sylius mailer renders `subject` block standalone → top-level `set` never runs → "Variable translation_locale does not exist".

✅ Right:

```twig
{% extends '@SyliusCore/Email/layout.html.twig' %}

{% block subject %}
    {% set translation_locale = entity.localeCode %}
    {{ 'app.email.<code>.subject'|trans({}, 'messages', translation_locale) }}
{% endblock %}

{% block content %}
    {% set translation_locale = entity.localeCode %}
    <p>{{ 'app.email.<code>.body'|trans({}, 'messages', translation_locale) }}</p>
{% endblock %}
```

**Why:** Sylius mailer renders `subject` and `content` blocks separately (different rendering passes). Plain HTML or missing-block templates produce "Block subject does not exist". Top-level `set` runs only when the template is rendered as a whole, which mailer doesn't do.

## 2. Mailer send without channel + localeCode

❌ Wrong:

```php
$this->sender->send('app_<code>', [$entity->getEmail()], [
    'entity' => $entity,
]);
```

✅ Right:

```php
$channel = $this->channelRepository->findOneByCode($entity->getChannelCode());

$this->sender->send('app_<code>', [$entity->getEmail()], [
    'entity' => $entity,
    'channel' => $channel,
    'localeCode' => $entity->getLocaleCode(),
]);
```

**Why:** `@SyliusCore/Email/layout.html.twig` calls `sylius_channel_url(asset(...), channel)` and uses `channel` for logo/footer. Missing `channel` = render error. Missing `localeCode` (and pulling from current request instead of the persisted resource) = email rendered in worker's default locale, not the subscriber's.

## 3. Translation file with locale shorthand

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

## 4. Mailer code without app_ prefix

❌ Wrong:

```yaml
sylius_mailer:
    emails:
        <code>:
            subject: app.email.<code>.subject
            template: 'email/<code>.html.twig'
```

✅ Right:

```yaml
sylius_mailer:
    emails:
        app_<code>:
            subject: app.email.<code>.subject
            template: 'email/<code>.html.twig'
```

`$this->sender->send('app_<code>', ...)`.

**Why:** Sylius core ships codes like `order_confirmation`, `password_reset_token`, `customer_registration`, `contact_request`. Bare app code risks future collision when Sylius adds a new built-in. `app_` prefix isolates the namespace cheaply.

## 5. Hardcoded mailer subject

❌ Wrong:

```yaml
sylius_mailer:
    emails:
        app_<code>:
            subject: 'Some literal English subject'
            template: 'email/app_<code>.html.twig'
```

✅ Right:

```yaml
sylius_mailer:
    emails:
        app_<code>:
            subject: app.email.<code>.subject
            template: 'email/app_<code>.html.twig'
```

Plus translation entry in `translations/messages.<locale>.yaml`.

**Why:** template `{% block subject %}` overrides at render time, but the config-level subject is what `messenger:debug`, admin email logs, and other tooling display. Translation-key indirection keeps the config readable across locales and matches Sylius core conventions.

## 6. URL hand-rolled in email

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

## 7. Single translation file in multi-locale project

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

**Why:** Read `sylius_locale.locales` (or fallback `framework.default_locale`). Emit one file per enabled locale. The Mate tool `sylius_project_profile` returns the list as `enabled_locales`. Default to "single en_US file" only when project actually ships only en_US.

## 8. Async URL generation without default_uri

❌ Wrong:

```php
#[AsMessageHandler]
final class <X>Handler
{
    public function __invoke(<X>Triggered $m): void
    {
        $this->sender->send('app_<code>', [...], [
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
