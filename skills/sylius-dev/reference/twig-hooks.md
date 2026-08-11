# Reference - TwigHooks

Deep dive on hook-based UI extension.

## When to use

Any change to a Sylius-shipped template. Adding a button to PDP, a banner in checkout, a column to cart line item, etc.

**Never** `{% extends '@!SyliusShop/...' %}` - locks the file, breaks themes + plugins.

## Finding the hook

Call `sylius_hooks_find_for_template` with the template path. Returns list of hook names + their slots inside that template. Example output for `@SyliusShop/Product/show.html.twig` includes `sylius_shop.product.show.content.info`, `sylius_shop.product.show.content.attributes`, etc.

## Adding an entry

```yaml
sylius_twig_hooks:
    hooks:
        sylius_shop.product.show.content.info:
            back_in_stock_button:
                template: 'shop/product/back_in_stock_button.html.twig'
                priority: 100
                enabled: true
```

Rules:

- `template:` is explicit. Do not rely on auto path resolution.
- `priority:` higher = earlier. Default 0.
- `enabled:` toggle without removing config.

## Passing data

Hook context inherits from parent template. Access via `hookable_metadata.context` or just `product`, `variant`, etc. - same vars available.

## Component hook entry

If a Twig component:

```yaml
sylius_twig_hooks:
    hooks:
        sylius_shop.product.show.content.info:
            back_in_stock_button:
                component: 'App\TwigComponent\Shop\BackInStockButtonComponent'
                template: 'shop/product/back_in_stock_button.html.twig'
```

Pass `template:` even with `component:` - don't rely on Symfony UX auto-template path (R-COMP-TPL). Sylius hooks need the explicit path.

Component class lives at `src/TwigComponent/<Section>/<X>Component.php` (R-TWIG-COMPONENT-PATH) - matches the Sylius bundle convention `Sylius\Bundle\<X>Bundle\TwigComponent\`, not Symfony UX default `App\Twig\Components\`.

For stateless widgets prefer a plain `template:` hook + Twig Extension. Use a Twig component only when feature needs Live behavior (`#[AsLiveComponent]` + Sylius `sylius.twig_component` tag).

## Disabling shipped entries

```yaml
sylius_twig_hooks:
    hooks:
        sylius_shop.product.show.content.info:
            cart_form:
                enabled: false
```

## Debug

`bin/console debug:twig-hooks` (if available) or grep `templates/bundles/SyliusShopBundle` for entries.
