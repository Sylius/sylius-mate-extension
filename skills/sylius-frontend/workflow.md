# Sylius Frontend - build steps

Step numbers follow the `sylius-dev` build map; hard rules and their rationale are in `SKILL.md` and `anti-patterns.md` next to this file.

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
