---
name: sylius-mailer
description: Add or change transactional email in Sylius 2.x - sylius_mailer.emails config with app_ codes and translation-key subjects, templates extending @SyliusCore/Email/layout.html.twig with subject + content blocks, SenderInterface context with channel + localeCode, url() with default_uri for async senders, one translation file per enabled locale. Part of the sylius-dev skill family; for a whole feature start from sylius-dev. Triggers on "Sylius email", "send an email when", "mailer template", "sylius_mailer", "notification email", "translations in Sylius", "locale file".
---

# Sylius Mailer and Translations

Step 9 (with gates 9.5 and 9.6) of the `sylius-dev` build map. Read `sylius-dev` first for discovery (mailer code check, `enabled_locales` and `framework_router_default_uri` from `sylius_project_profile`) and the cross-cutting rules. Paths like `sylius-dev/reference/services.md` point at a sibling skill, installed next to this one (directory `<name>` in the extension, `mate-<name>` under `.agents/skills/`).

**Mate tools used here:** `sylius_email_template_skeleton` (emit the template + config block instead of writing from scratch), `sylius_mailer_verify_template`, `sylius_translation_create` (mutating; one file per locale), `sylius_email_capture_status`.

## Build (step 9 + gates 9.5, 9.6)

Full procedure - config block, template skeleton, URL rule, gates - in `workflow.md`. In short: `sylius_mailer.emails.app_<code>` with a translation-key subject; template from `sylius_email_template_skeleton` extending `@SyliusCore/Email/layout.html.twig` with `subject` + `content` blocks and `{% set %}` inside each; links via `url()` with the full locale, `framework.router.default_uri` set for async senders; one `translations/messages.<exact_locale>.yaml` per enabled locale. Gates: `sylius_mailer_verify_template --code=<code>` (9.5), locale files match `enabled_locales` (9.6).

## Hard Rules (refuse if violated)

Rationale and ✅ replacements in `anti-patterns.md`.

- ❌ **R-APP-EMAIL-PREFIX.** App mailer code without `app_` prefix → collides with core codes later.
- ❌ **R-MAILER-CONFIG-TRANSLATION-KEY.** `subject:` as literal text → translation key `app.email.<code>.subject`.
- ❌ **R-EMAIL-LAYOUT.** Email template without all of: `{% extends '@SyliusCore/Email/layout.html.twig' %}`, both `subject` and `content` blocks, helper `{% set %}` inside the block → "Block subject does not exist".
- ❌ **R-MAILER-CTX.** `SenderInterface::send()` context without `channel` (via `ChannelRepositoryInterface::findOneByCode`) and `localeCode` (from the persisted resource) → layout render error.
- ❌ **R-URL-IN-EMAIL.** Hand-rolled URL concatenation or `localeCode|split('_')[0]` in email → Twig `url()` with the full locale.
- ❌ **R-DEFAULT-URI.** URLs generated from Messenger / CLI without `framework.router.default_uri` → set it (`bin/console debug:config framework.router`).
- ❌ **R-TRANS-LOCALE.** Translation filename with locale shorthand when the shop runs a variant → `messages.en_US.yaml`, not `messages.en.yaml`.
- ❌ **R-MULTI-LOCALE.** One translation file when several locales are enabled → one file per `enabled_locales` entry.

## Linked Files

- `workflow.md` - the build steps above in full: tool calls, outputs, per-step verification.
- `reference.md` - Sylius mailer: registration, sending, per-channel templates, async send, verify.
- `anti-patterns.md` - ❌/✅ pairs for the rules above.
