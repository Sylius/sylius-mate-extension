---
name: sylius-frontend
description: Place UI on Sylius 2.x shop and admin pages through Twig Hooks instead of template overrides - hook target selection by visibility state, hook entries with explicit template paths, hookable_metadata context, twig components with the Sylius tag, template path conventions. Part of the sylius-dev skill family; for a whole feature start from sylius-dev. Triggers on "add to product page", "Sylius twig hook", "hook template", "twig component in Sylius", "shop page widget", "admin page section", "Sylius template override".
---

# Sylius Frontend (Twig Hooks)

Step 5 of the `sylius-dev` build map. Read `sylius-dev` first for discovery (`sylius_hooks_find_for_template`, `sylius_hooks_resolve_for_visibility`) and the cross-cutting rules. Paths like `sylius-dev/reference/services.md` point at a sibling skill, installed next to this one (directory `<name>` in the extension, `mate-<name>` under `.agents/skills/`).

**Mate tools used here:** `sylius_hooks_list`, `sylius_hooks_find_for_template`, `sylius_hooks_list_hookables`, `sylius_hooks_resolve_for_visibility`, `sylius_twig_list_functions`, `sylius_twig_function_verify` (**mandatory** before referencing any `sylius_*` Twig function), `sylius_test_render_template`.

## 5. Frontend

**Goal:** UI placed via TwigHook, not template override.

**Hook target selection (R-HOOK-VISIBILITY):**

- Resolve target via `sylius_hooks_resolve_for_visibility` Mate tool with feature state. Never guess from hook name suffix.
- Out-of-stock-only widgets → parent hook (`sylius_shop.product.show.content.info.summary`), NOT `add_to_cart` sub-tree (dead branch when variant unavailable).
- In-stock-only features → `add_to_cart` and children OK.
- Always-visible → any hook fires.

**Output:**

- Hook entry in `config/packages/_sylius_twig_hooks.yaml` - pass explicit `template:` path.
- Template under `templates/shop/...` - render via `{{ form_start(form, {action: path('<route>')}) }}` / `form_row` / `form_end(form)`. No hand-rolled `<form>` open tag. No hand-rolled `<input>`. Never manually render `form._token` - `form_end` emits it.
- Hook template **always** starts by destructuring context (R-HOOKABLE-METADATA):

  ```twig
  {% set variant = hookable_metadata.context.variant ?? null %}
  {% set product = hookable_metadata.context.product ?? null %}
  ```

  Never reference `variant` / `product` as bare vars - they may not be auto-passed depending on hook position.
- Preferred for stateless widgets: plain `template:` hook + Twig Extension to compute view data. Avoid Twig Components unless feature needs Live behavior.
- If using `#[AsTwigComponent]` for a hook target - bare attribute alone does NOT bind props from hook config (Sylius hooks need the `sylius.twig_component` tag + custom compiler pass). Use Sylius's `#[AsLiveComponent]` / `sylius.twig_component` registration, or fall back to `template:` hook + Twig Extension.
- Component class location: `src/TwigComponent/<Section>/<X>Component.php` (matches Sylius bundle convention `Sylius\Bundle\<X>Bundle\TwigComponent\`). NOT `src/Twig/Components/` (Symfony UX default).

**Verify:** `bin/console lint:twig templates/`.

## Hard Rules (refuse if violated)

Details + ✅ replacements in `anti-patterns.md`.

- ❌ `{% extends '@SyliusShop/...' %}` template override (use TwigHooks).
- ❌ Backslashes in Sylius template path strings. Always forward slashes: `'@SyliusAdmin/shared/crud/index.html.twig'`. Never `'@SyliusAdmin\shared\crud'`.
- ❌ **R-COMP-TPL.** `#[AsTwigComponent]` without explicit `template:` argument. Hook config passing `template:` as a prop binds to a public property - does NOT redirect renderer. UX TwigComponent auto-resolution does not match hook-prop path. Sylius core's `sylius.twig_component` tag uses a custom compiler pass; plain `#[AsTwigComponent]` lacks it. Always: `#[AsTwigComponent('app_x', template: 'shop/.../x.html.twig')]`.
- ❌ **R-TWIG-COMPONENT-PATH.** App-level Twig Components at `App\Twig\Components\` (Symfony UX default). Use `App\TwigComponent\<Section>\` to match Sylius bundle convention (`Sylius\Bundle\<X>Bundle\TwigComponent\`).
- ❌ **R-HOOK-VISIBILITY.** Selecting a `sylius_twig_hooks` leaf target without confirming the parent template renders it in the feature's target state. Leaf hooks under `*.add_to_cart`, `*.add_to_cart.variants.*` live inside `{% if %}` branches that short-circuit when variant unavailable. For a widget that only makes sense while its precondition doesn't hold (e.g. out-of-stock-only), the `add_to_cart` sub-tree is dead. Use the parent hook (`sylius_shop.product.show.content.info.summary`) that fires unconditionally. Call the Mate tool `sylius_hooks_resolve_for_visibility` with feature visibility state (`oos` / `in_stock` / `always`) to get valid targets - do not guess from hook name suffix.
- ❌ **R-HOOKABLE-METADATA bare.** Hook template referencing bare `variant` / `product` without destructuring from `hookable_metadata.context.*`. Always start the template with:

  ```twig
  {% set variant = hookable_metadata.context.variant ?? null %}
  {% set product = hookable_metadata.context.product ?? null %}
  ```
- ❌ **R-HOOK-COMPONENT-TAG.** Using bare `#[AsTwigComponent]` as a hook `component:` target - Sylius hooks need the `sylius.twig_component` tag + custom compiler pass to bind props from hook config. For stateless widgets prefer `template:` hook + Twig Extension. For Live behavior use `#[AsLiveComponent]` + Sylius tag.

## Linked Files

- `reference.md` - TwigHooks: finding the hook, adding an entry, passing data, component entries, disabling shipped entries, debug.
- `anti-patterns.md` - ❌/✅ pairs for the rules above.
