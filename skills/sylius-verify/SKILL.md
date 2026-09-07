---
name: sylius-verify
description: Prove a Sylius 2.x change works before calling it done - the verify pass (lint, compile gate, container lookup for every new or modified class, schema, routes, messenger, Mate verify tools), cache clear through the Mate tool only, and the mandatory Playwright acceptance spec covering every observable user path with a real email proof. Part of the sylius-dev skill family. Triggers on "verify the Sylius feature", "is it done", "run the checks", "Playwright spec for Sylius", "acceptance test", "prove the email was sent".
---

# Sylius Verify and Acceptance

Steps 10-11 of the `sylius-dev` build map - the definition of DONE for every Sylius change. Container lookups follow the `sylius-dev` contract: Symfony Mate bridge when installed (`symfony_mate_bridge` in the profile output), `bin/console debug:container` otherwise. Paths like `sylius-dev/reference/services.md` point at a sibling skill, installed next to this one (directory `<name>` in the extension, `mate-<name>` under `.agents/skills/`).

**Mate tools used here:** `sylius_cache_clear` (mutating, the only allowed cache clear), `sylius_project_profile` (kernel-booting compile gate), `sylius_resource_inspect`, `sylius_routes_show`, `sylius_mailer_verify_template`, `sylius_hooks_find_for_template`, `sylius_email_capture_status`, `sylius_admin_restock_via_http`, `sylius_playwright_recipe`.

## Gates (steps 10-11)

Full scripts and the step-by-step Playwright protocol in `workflow.md`. In short:

- **10. Verify pass:** `php -l` on every touched file, `lint:yaml`, `lint:twig`, then the compile gate (`sylius_cache_clear` + `sylius_project_profile`) and a container lookup for **every** new or modified FQCN, `doctrine:schema:validate`, `debug:router` for every new route (admin index included), `messenger:debug` if async, Mate verify tools (`sylius_resource_inspect`, `sylius_routes_show`, `sylius_mailer_verify_template`, `sylius_hooks_find_for_template`). Any failure → STOP, fix, re-run.
- **10.5. Cache clear:** `sylius_cache_clear` once, never the shell command.
- **11. Playwright acceptance:** author a repeatable spec at `tests/Playwright/<feature>.spec.ts` (never one-shot exploratory calls), run it via Playwright MCP, refuse "done" until every step is green. **Coverage rule:** setup, the user action, the downstream trigger through ORM, any email assertion against mailpit or the profiler, AND the post-change UI state. Single-step specs are rejected.

## Hard Rules (refuse if violated)

Rationale and ✅ replacements in `anti-patterns.md`.

- ❌ **R-PLAYWRIGHT-NO-RAW-SQL.** Spec mutating a listener-observed entity via raw SQL → bypasses UoW, the listener never fires; use an ORM path (CLI command, admin UI, API).
- ❌ **R-EMAIL-PROOF.** Handler-written DB flag as proof of delivery → mailpit/mailhog or the profiler mailer collector (HTTP-triggered only); otherwise `// TODO` + report INCOMPLETE.

## Linked Files

- `workflow.md` - the build steps above in full: tool calls, outputs, per-step verification.
- `anti-patterns.md` - ❌/✅ pairs for the rules above.
- `sylius-dev/reference/worked-example.md` - this protocol run against a concrete feature.
