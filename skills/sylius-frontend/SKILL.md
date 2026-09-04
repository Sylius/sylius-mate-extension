---
name: sylius-frontend
description: Place UI on Sylius 2.x shop and admin pages through Twig Hooks instead of template overrides - hook target selection by visibility state, hook entries with explicit template paths, hookable_metadata context, twig components with the Sylius tag, template path conventions. Part of the sylius-dev skill family; for a whole feature start from sylius-dev. Triggers on "add to product page", "Sylius twig hook", "hook template", "twig component in Sylius", "shop page widget", "admin page section", "Sylius template override".
---

# Sylius Frontend (Twig Hooks)

Step 5 of the `sylius-dev` build map. Read `sylius-dev` first for discovery (`sylius_hooks_find_for_template`, `sylius_hooks_resolve_for_visibility`) and the cross-cutting rules. Paths like `sylius-dev/reference/services.md` point at a sibling skill, installed next to this one (directory `<name>` in the extension, `mate-<name>` under `.agents/skills/`).

**Mate tools used here:** `sylius_hooks_list`, `sylius_hooks_find_for_template`, `sylius_hooks_list_hookables`, `sylius_hooks_resolve_for_visibility`, `sylius_twig_list_functions`, `sylius_twig_function_verify` (**mandatory** before referencing any `sylius_*` Twig function), `sylius_test_render_template`.

## Build (step 5)

Full procedure in `workflow.md`. In short: resolve the hook target with `sylius_hooks_resolve_for_visibility` (never from the name suffix), add the entry to `config/packages/_sylius_twig_hooks.yaml` with an explicit `template:`, start the template by destructuring `hookable_metadata.context.*`, render forms with `form_start` / `form_row` / `form_end`, prefer a `template:` hook + Twig Extension over a Twig Component unless live behavior is needed, put components under `src/TwigComponent/<Section>/`. Verify with `sylius_twig_function_verify` for every `sylius_*` helper and `bin/console lint:twig templates/`.

## Hard Rules (refuse if violated)

Rationale and ✅ replacements in `anti-patterns.md`.

- ❌ `{% extends '@SyliusShop/...' %}` template override → TwigHook entry.
- ❌ Backslashes in template path strings → forward slashes (`'@SyliusAdmin/shared/crud/index.html.twig'`).
- ❌ **R-HOOK-VISIBILITY.** Leaf hook target chosen without confirming the parent template renders it in the feature's state → `sylius_hooks_resolve_for_visibility`; `*.add_to_cart.*` is dead when the variant is unavailable.
- ❌ **R-HOOKABLE-METADATA.** Hook template using bare `variant` / `product` → destructure from `hookable_metadata.context.*` first.
- ❌ **R-COMP-TPL.** `#[AsTwigComponent]` without explicit `template:` → hook `template:` props do not redirect the renderer; always `#[AsTwigComponent('app_x', template: '...')]`.
- ❌ **R-HOOK-COMPONENT-TAG.** Bare `#[AsTwigComponent]` as a hook `component:` target → Sylius needs the `sylius.twig_component` tag; prefer a `template:` hook + Twig Extension, `#[AsLiveComponent]` + Sylius tag for live behavior.
- ❌ **R-TWIG-COMPONENT-PATH.** Components under `App\Twig\Components\` → `App\TwigComponent\<Section>\` (Sylius bundle convention).

## Linked Files

- `workflow.md` - the build steps above in full: tool calls, outputs, per-step verification.
- `reference.md` - TwigHooks: finding the hook, adding an entry, passing data, component entries, disabling shipped entries, debug.
- `anti-patterns.md` - ❌/✅ pairs for the rules above.
