---
name: sylius-mailer
description: Add or change transactional email in Sylius 2.x - sylius_mailer.emails config with app_ codes and translation-key subjects, templates extending @SyliusCore/Email/layout.html.twig with subject + content blocks, SenderInterface context with channel + localeCode, url() with default_uri for async senders, one translation file per enabled locale. Part of the sylius-dev skill family; for a whole feature start from sylius-dev. Triggers on "Sylius email", "send an email when", "mailer template", "sylius_mailer", "notification email", "translations in Sylius", "locale file".
---

# Sylius Mailer and Translations

Step 9 (with gates 9.5 and 9.6) of the `sylius-dev` build map. Read `sylius-dev` first for discovery (mailer code check, `enabled_locales` and `framework_router_default_uri` from `sylius_project_profile`) and the cross-cutting rules. Paths like `sylius-dev/reference/services.md` point at a sibling skill, installed next to this one (directory `<name>` in the extension, `mate-<name>` under `.agents/skills/`).

**Mate tools used here:** `sylius_email_template_skeleton` (emit the template + config block instead of writing from scratch), `sylius_mailer_verify_template`, `sylius_translation_create` (mutating; one file per locale), `sylius_email_capture_status`.

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
  the product page) is in `sylius-dev/reference/worked-example.md`.

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

## Hard Rules (refuse if violated)

Details + ✅ replacements in `anti-patterns.md`.

- ❌ **R-EMAIL-LAYOUT.** Email template under `sylius_mailer.emails.*` without all of: (1) `{% extends '@SyliusCore/Email/layout.html.twig' %}`, (2) both `{% block subject %}` and `{% block content %}` defined, (3) helper `{% set %}` declared INSIDE the block that uses it (Sylius mailer renders `subject` block standalone - top-level `set` runs only for parent render). Plain HTML or one-block templates fail with "Block subject does not exist".
- ❌ **R-MAILER-CTX.** `SenderInterface::send($code, $recipients, $context)` without `channel` (resolved via `ChannelRepositoryInterface::findOneByCode`) AND `localeCode` (from the persisted resource, not the current request) in `$context`. `@SyliusCore/Email/layout.html.twig` calls `sylius_channel_url(asset(...), channel)` - missing channel = render error.
- ❌ **R-TRANS-LOCALE.** Translation filename using locale shorthand when shop runs a locale variant. If `framework.default_locale` / `sylius_locale` = `en_US`, file MUST be `translations/messages.en_US.yaml`, not `messages.en.yaml`. (Cache rewarm is `sylius_cache_clear`'s job - see Cache Clear.)
- ❌ **R-APP-EMAIL-PREFIX.** App-level mailer code without `app_` prefix. Sylius core ships `order_confirmation`, `password_reset_token`, `customer_registration`. A bare app-chosen code risks future collision with a core one. Always: `app_<code>`.
- ❌ **R-MAILER-CONFIG-TRANSLATION-KEY.** `sylius_mailer.emails.<code>.subject:` as literal English. Always a translation key: `subject: app.email.<code>.subject`. Template `{% block subject %}` overrides at render time, but config-level key keeps the indirection consistent.
- ❌ **R-URL-IN-EMAIL.** Hand-rolled URL concatenation in email templates (`sylius_channel_url(asset(''), channel) ~ '/products/' ~ ...`) or `localeCode|split('_')[0]` (strips region; Sylius URLs use full locale). Always Twig `url()`:

  ```twig
  <a href="{{ url('sylius_shop_product_show', {slug: product.translation(localeCode).slug, _locale: localeCode}) }}">
  ```

  For Messenger async handlers (no Request context), `framework.router.default_uri` must be set in `messenger.yaml` so `url()` resolves absolute. Sylius-Standard should ship this.
- ❌ **R-MULTI-LOCALE.** Single `messages.<one_locale>.yaml` when project has multiple enabled locales. Read `sylius_locale.locales` (or `framework.default_locale` fallback) - emit one translation file per enabled locale. The Mate tool `sylius_project_profile` returns the list as `enabled_locales`.
- ❌ **R-DEFAULT-URI.** Feature that generates URLs from Messenger handlers / CLI / non-HTTP contexts without `framework.router.default_uri` set. Detect via `bin/console debug:config framework.router`; if absent, add to feature yaml or `framework.yaml`. Without it, `url()` in email template renders empty/wrong host.

## Linked Files

- `reference.md` - Sylius mailer: registration, sending, per-channel templates, async send, verify.
- `anti-patterns.md` - ❌/✅ pairs for the rules above.
