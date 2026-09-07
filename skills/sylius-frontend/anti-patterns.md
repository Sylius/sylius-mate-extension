# Anti-Patterns - frontend

Twig hook, hook template and twig component traps. Each entry: ❌ wrong, ✅ right, why.

## 1. Template override

❌ Wrong:

`templates/bundles/SyliusShopBundle/Product/show.html.twig`:

```twig
{% extends '@!SyliusShop/Product/show.html.twig' %}
{% block main %}
    {{ parent() }}
    <div>{# feature widget #}</div>
{% endblock %}
```

✅ Right:

```yaml
sylius_twig_hooks:
    hooks:
        sylius_shop.product.show.content.info:
            app_<feature>_widget:
                template: 'shop/product/<feature>_widget.html.twig'
                priority: 100
```

**Why:** TwigHooks are additive, survive theme + Sylius upgrades. Template extends locks the whole file to one consumer; breaks plugin co-existence.

## 2. Backslash in template path

❌ Wrong:

```yaml
sylius_grid:
    grids:
        app_admin_<alias>:
            fields:
                createdAt:
                    type: twig
                    options:
                        template: '@SyliusAdmin\shared\grid\field\datetime.html.twig'
```

✅ Right:

```yaml
sylius_grid:
    grids:
        app_admin_<alias>:
            fields:
                createdAt:
                    type: twig
                    options:
                        template: '@SyliusAdmin/shared/grid/field/datetime.html.twig'
```

**Why:** Twig namespaces always use `/`. `\` is PHP namespace separator; copy/pasting it into a yaml template string silently breaks resolution at runtime. Lint won't catch.

## 3. AsTwigComponent without template attribute

❌ Wrong:

```php
#[AsTwigComponent('app_<feature>')]
final class <X>Component
{
    public ?string $template = null;
}
```

Hook config:

```yaml
sylius_twig_hooks:
    hooks:
        sylius_shop.product.show.content.info:
            app_<feature>:
                component: '<AppNs>\Twig\Component\<X>Component'
                template: 'shop/product/<feature>.html.twig'
```

The `template:` in hook config binds to the component's public `$template` prop - does NOT redirect the renderer. UX TwigComponent then tries to auto-resolve `templates/components/<X>Component.html.twig` and 404s.

✅ Right:

```php
#[AsTwigComponent('app_<feature>', template: 'shop/product/<feature>.html.twig')]
final class <X>Component
{
}
```

**Why:** Sylius core uses a custom `sylius.twig_component` tag + compiler pass to honor hook-passed `template:`. Plain UX `#[AsTwigComponent]` does not. Always declare `template:` on the attribute itself.

## 4. Twig Component in Symfony-UX default path

❌ Wrong:

```
src/Twig/Components/<X>Component.php
namespace <AppNs>\Twig\Components;
```

✅ Right:

```
src/TwigComponent/<Section>/<X>Component.php
namespace <AppNs>\TwigComponent\<Section>;
```

**Why:** Sylius bundle convention is `Sylius\Bundle\<X>Bundle\TwigComponent\`. App-level mirrors as `<AppNs>\TwigComponent\<Section>\`. Plugin authors and core devs look in the Sylius-convention path; UX default breaks the contract.

## 5. Leaf hook target without parent visibility check

❌ Wrong: picking `sylius_shop.product.show.content.info.summary.add_to_cart` for a widget that's only relevant when the variant is unavailable, because the name suggests product page placement.

Parent template:

```twig
{% if product.enabledVariants.empty() or product.simple and not sylius_inventory_is_available(...) %}
    <div>Out of stock</div>
{% else %}
    {% hook 'add_to_cart' %}   {# ← never fires for OOS #}
{% endif %}
```

Widget meant for that OOS branch → hook never renders. Silent failure.

✅ Right:

Call the Mate tool `sylius_hooks_resolve_for_visibility` with feature state (`oos`):

```yaml
sylius_twig_hooks:
    hooks:
        sylius_shop.product.show.content.info.summary:   # parent - unconditional
            app_<feature>_widget:
                template: 'shop/product/<feature>_widget.html.twig'
```

**Why:** hook names suggest placement but not visibility. Parent template guards short-circuit entire sub-trees. For OOS-only / in-stock-only features, the relevant branch may be dead. Resolver tool returns hook targets whose parent renders in the requested state. Never guess from name suffix.

## 6. Bare hook template vars

❌ Wrong:

```twig
{% if variant and not variant.tracked %}
    <p>{{ variant.product.name }}</p>
{% endif %}
```

When the hook position doesn't auto-pass `variant`, the template silently sees `null` → empty render.

✅ Right:

```twig
{% set variant = hookable_metadata.context.variant ?? null %}
{% set product = hookable_metadata.context.product ?? null %}

{% if variant and not variant.tracked %}
    <p>{{ product.name }}</p>
{% endif %}
```

**Why:** Hook context is not guaranteed to inject specific names as bare vars; the safe access is via `hookable_metadata.context`. Destructure once at the top, then code against locals.
