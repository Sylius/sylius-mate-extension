---
name: sylius-verify
description: Prove a Sylius 2.x change works before calling it done - the verify pass (lint, compile gate, container lookup for every new or modified class, schema, routes, messenger, Mate verify tools), cache clear through the Mate tool only, and the mandatory Playwright acceptance spec covering every observable user path with a real email proof. Part of the sylius-dev skill family. Triggers on "verify the Sylius feature", "is it done", "run the checks", "Playwright spec for Sylius", "acceptance test", "prove the email was sent".
---

# Sylius Verify and Acceptance

Steps 10-11 of the `sylius-dev` build map - the definition of DONE for every Sylius change. Container lookups follow the `sylius-dev` contract: Symfony Mate bridge when installed (`symfony_mate_bridge` in the profile output), `bin/console debug:container` otherwise. Paths like `sylius-dev/reference/services.md` point at a sibling skill, installed next to this one (directory `<name>` in the extension, `mate-<name>` under `.agents/skills/`).

**Mate tools used here:** `sylius_cache_clear` (mutating, the only allowed cache clear), `sylius_project_profile` (kernel-booting compile gate), `sylius_resource_inspect`, `sylius_routes_show`, `sylius_mailer_verify_template`, `sylius_hooks_find_for_template`, `sylius_email_capture_status`, `sylius_admin_restock_via_http`, `sylius_playwright_recipe`.

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
# installed (pack), `bin/console debug:container` otherwise - see `sylius-dev` SKILL.md.
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

**Coverage rule:** spec drives ALL observable user paths in the feature, not just the entry point. Single-step specs rejected. `sylius-dev/reference/worked-example.md` walks this exact protocol against a concrete feature.

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

## Hard Rules (refuse if violated)

Details + ✅ replacements in `anti-patterns.md`.

- ❌ **R-PLAYWRIGHT-NO-RAW-SQL.** Playwright spec triggering changes to an entity observed by a Doctrine listener via raw SQL (`bin/console doctrine:query:sql "UPDATE ..."`). Raw SQL bypasses UnitOfWork → listener never fires → handler never runs → assertion against the side-effect (a DB flag, dispatched message, sent email) fails. Use a CLI command that goes through ORM, an admin UI flow via Playwright, or an API call - see `sylius-dev/reference/worked-example.md` for a concrete restock command.
- ❌ **R-EMAIL-PROOF.** Playwright asserting a handler-written DB flag (e.g. `notifiedAt IS NOT NULL`) as proof of email delivery. Handler reaches end-of-loop even when `null://null` swallows the message - false positive. Acceptable proofs: (1) capture transport (mailpit/mailhog) - scrape `http://localhost:8025/api/v1/messages`, (2) profiler mailer collector - works ONLY when the triggering mutation happened via HTTP. A CLI-triggered mutation bypasses profiler. If neither available, spec prints `// TODO: assert email via mailpit/profiler` rather than passing on a DB-flag check it can't trust.

## Linked Files

- `anti-patterns.md` - ❌/✅ pairs for the rules above.
- `sylius-dev/reference/worked-example.md` - this protocol run against a concrete feature.
