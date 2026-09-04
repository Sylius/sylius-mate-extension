# Sylius Mailer - build steps

Step numbers follow the `sylius-dev` build map; hard rules and their rationale are in `SKILL.md` and `anti-patterns.md` next to this file.

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
