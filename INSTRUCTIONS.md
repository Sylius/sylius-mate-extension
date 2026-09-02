# sylius/sylius-mate-extension — Agent Instructions

Mate CLI tools exposing the Sylius runtime domain — mostly read-only, the three mutating ones are flagged below. Invoke via `vendor/bin/mate tools:call <tool> --<param>=<value>` (nested values via `--json`). Call before generating any Sylius code; call again to audit after.

Container and profiler introspection come from the Symfony bridge (`symfony/ai-symfony-mate-extension`, installed alongside by the `sylius/sylius-ai-dev-tools` pack): `symfony-services --query=<fragment>` / `symfony-service-detail --id=<exact id>` instead of `bin/console debug:container`, `symfony-profiler-list` plus the `symfony-profiler://profile/{token}/{collector}` resource instead of reading `var/cache/dev/profiler` by hand. Those tools read the dumped container XML, so after `sylius_cache_clear` call any kernel-booting `sylius_*` tool first to recompile it.

## Tool index

| Tool | Use when |
|---|---|
| `sylius_domain_list_resources` | Checking whether a resource already exists; learning the shape of registered resources |
| `sylius_domain_resource_template` | Scaffolding a new Sylius Resource (entity, interface, repository + interface, factory + interface, form, `sylius_resource` config, grid stub). Optional `with_admin_hook=true` adds a TwigHook + sidebar nav template; optional `mailer_code` adds `sylius_mailer` config + email template stub. Output includes `summary.kinds` — confirm every kind is written, especially `entity` AND `resource_config` |
| `sylius_resource_inspect` | Resource diagnostic: classes wired, custom-factory `__construct(string $className)` signature check, interface auto-detection vs explicit, ResourceInterface conformance, generated service ids |
| `sylius_mailer_verify_template` | After wiring a mailer entry, confirm the configured Twig template resolves and is non-empty |
| `sylius_domain_list_grids` | Mirroring an existing admin grid for a new resource |
| `sylius_hooks_list` | Enumerating registered TwigHooks |
| `sylius_hooks_find_for_template` | Finding which hook(s) enclose a given template path |
| `sylius_hooks_list_hookables` | Listing hookables attached to a given hook |
| `sylius_hooks_resolve_for_visibility` | For a visibility intent (`oos`, `in_stock`, `always`, `logged_in`, `admin_only`) + context substring + section, return `safe_hooks` / `unsafe_hooks`. **Call before attaching widgets that should render outside the parent's normal visibility branch** (e.g. back-in-stock when product is OOS) |
| `sylius_grid_actions_audit` | Validate a grid's `actions:` block. Warns on `main.delete` (item-scoped), missing `main.create`, mis-scoped `item.create`, etc. |
| `sylius_email_capture_status` | Inspect active `MAILER_DSN`. Returns `observable=true/false` + recommended action when null-routed (call `sylius_admin_restock_via_http` for profiler-based assertion) |
| `sylius_admin_restock_via_http` | Compose a Playwright PATCH against the admin variant edit form that goes through ORM + listeners and yields an `X-Debug-Token`. Use when Mailpit is not configured but Symfony profiler is |
| `sylius_twig_list_functions` | Browse Twig helpers by prefix |
| `sylius_twig_function_verify` | **MANDATORY before referencing any `sylius_*` Twig function/filter/test in a template.** Strict exact-name check + reflected signature |
| `sylius_test_render_template` | Smoke-test a template with a provided context — returns the rendered output or a structured Twig error. Catches missing helpers, syntax errors, undefined variables before running the app |
| `sylius_routes_show` | Resolving a Symfony route by name. **Call this before linking to or generating any route reference** so the route definitely exists |
| `sylius_route_inspect` | Route diagnostic w/ duplicate-segment detection (catches double-prefix bugs from outer prefix + sylius.resource loader) |
| `sylius_cache_clear` | **Mutating.** Pre-Playwright cache clear, **PHP-native** (does NOT shell `bin/console`; routes through `Symfony\\Bundle\\FrameworkBundle\\Console\\Application` programmatically, fallback purge of `var/cache/<env>/`). Use this instead of any Bash `cache:clear` — the harness Bash classifier blocks the shell form |
| `sylius_services_yaml_profile` | Read once per session: host project DI defaults, `_instanceof` overrides, app + controller globs. Tells you how a new class will be registered (and whether you need an explicit service entry) |
| `sylius_services_yaml_audit` | Audit every services yaml file (root + imports) for explicit-def vs `App\:` glob conflicts. Returns `conflicts[]` with fix hints |
| `sylius_services_yaml_patch_exclude` | **Mutating.** Idempotently add an entry to the `<AppNs>\:` `exclude:` list in `config/services.yaml`. Use when emitting explicit service defs that the glob would otherwise capture |
| `sylius_email_template_skeleton` | Emit a working `templates/email/<code>.html.twig` extending `@SyliusCore/Email/layout.html.twig` + matching `sylius_mailer.yaml` block. Use instead of writing email templates from scratch |
| `sylius_translation_create` | **Mutating.** Merge a key tree into `translations/<domain>.<locale>.yaml`. Pass `locales: [...]` for multi-locale projects (one file per locale). Auto-detects locale from `kernel.default_locale`. Returns `cache_clear_required: true` |
| `sylius_playwright_recipe` | Emit a `tests/Playwright/<slug>.spec.ts` script for a flow. Steps grouped into setup / scenario / teardown |
| `sylius_project_profile` | **Call once per session.** Auto-detect host app namespace (e.g. `Elesto`), src/config/translations dirs, enabled locales, default locale + channel, MAILER_DSN observability, services.yaml exclude entries, hook config dir convention. Feeds every other scaffold tool — stops hardcoding `App\` |
| `sylius_installed_plugins` | Dynamic detection, no hardcoded plugin registry: `sylius_version`, every `sylius/*` package (composer.lock), every enabled bundle resolved to its owning composer package (`items[]`). Pure inventory — for decoration info use `sylius_service_decorators` |
| `sylius_service_decorators` | Every service that decorates a `sylius.*`/`sylius_*` id: `original_service_id`, `decorator_class`, `decorator_package` (nullable — decoration is orthogonal to plugins; a decorator can be the host project's own customization), `priority`. Facts only — **you** interpret what a decorator implies (e.g. MSI's checker means stock lives on `InventorySourceStockInterface`, not `ProductVariant`); read `decorator_class` via `sylius_resource_inspect`/file read if unsure. **Call before designing any inventory/order/price/availability listener whose target might already be overridden** |
| `sylius_plugin_compatibility` | For every `sylius-plugin`-type package in `composer.lock`, checks whether its INSTALLED version supports `target_sylius_version` (reads that plugin's own `vendor/<pkg>/composer.json` constraint, offline) and, if not, looks up Packagist for the newest stable version that does. **Call before bumping `sylius/sylius`** — pair with `sylius_installed_plugins.sylius_version` for "what am I on, what needs to move first" |
| `sylius_project_audit` | One-shot audit of Sylius-Standard baseline conventions: services.yaml excludes, core repo aliases, sync messenger in dev, `framework.router.default_uri`, mailer observable, twig_hooks dir, project `CLAUDE.md`. Returns `patches_available[]` |

## Hard rules

1. **Twig helpers**: never write `{{ sylius_x(...) }}` or `{% if foo is sylius_y %}` without first calling `sylius_twig_function_verify name="sylius_x"`. An `error.code=empty` or `items=[]` response means the helper does not exist; do not use it.
2. **Routes**: never call `path('route_name')` / `url('route_name')` / `redirectToRoute('route_name')` without calling `sylius_routes_show` with `name=<route_name>` first.
3. **New persisted feature**: always scaffold via `sylius_domain_resource_template`, then verify via `sylius_resource_inspect`. Plain `#[ORM\Entity]` outside the Resource pattern is forbidden.
4. **Email templates**: after writing a `sylius_mailer.emails.<code>` entry, call `sylius_mailer_verify_template` with that code. Confirms the template path resolves and is non-empty (catches the empty-file class of bugs).
5. **Twig smoke-test**: after generating any template that calls `sylius_*` helpers or registered Twig hooks, call `sylius_test_render_template` with a minimal context to surface render errors before runtime.
6. **Mutating tools**: `sylius_cache_clear`, `sylius_services_yaml_patch_exclude`, `sylius_translation_create` modify host state (filesystem). Confirm with user before invoking unless the workflow has already authorized the operation.

## Session-startup checklist

Run once when starting a new feature, before any code edit:

1. `sylius_services_yaml_profile` — learn DI defaults
2. `sylius_project_profile` — namespace / locales / DSN
3. `sylius_installed_plugins` + `sylius_service_decorators` — plugin inventory + decorator awareness
4. `sylius_domain_list_resources` + `sylius_hooks_list` — sanity scan
