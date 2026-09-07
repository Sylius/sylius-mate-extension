# Anti-Patterns - verify

Acceptance and proof traps: what does not count as evidence. Each entry: ❌ wrong, ✅ right, why.

## 1. Acceptance test (Playwright required; Behat optional)

Playwright spec is the mandatory acceptance gate (see SKILL.md workflow step 11). Behat is optional - add only when user requests or when feature is pure-domain logic better expressed as Gherkin.

✅ Optional Behat example:

```gherkin
Feature: Doing the thing
    In order to achieve some outcome
    As a Customer
    I want to perform the feature's primary action

    Background:
        Given the store operates on a single channel in "United States"
        And the precondition for the feature is met

    Scenario: Happy path
        When I am browsing the relevant page
        And I perform the feature's action
        Then I should see the expected outcome
```

**Why:** Behat is Sylius's classic regression net, but Playwright covers UI + listener + email end-to-end. Pick the one that fits the feature shape; do not require both.

## 2. Single-step Playwright spec

❌ Wrong: spec drives only the entry step, asserts only the success flash. Misses the downstream trigger, the side effect, and the post-state.

✅ Right: spec covers the whole user journey - setup → primary action → success flash → downstream trigger → side-effect assertion via the Mate profiler tools → post-state UI check. `sylius-dev/reference/worked-example.md` has a full instance.

**Why:** Bugs surface across step boundaries (listener idempotency, stale cache after state change, mailer ctx wrong, locale mismatch on async render). A spec that stops halfway through the flow rubber-stamps regressions.

## 3. Playwright spec mutating via raw SQL

❌ Wrong (inside a Playwright spec):

```ts
execSync(`bin/console doctrine:query:sql "UPDATE app_<table> SET <field> = <value> WHERE code = '<code>'"`);
```

Spec asserts a handler-written DB flag later. Fails: listener never fired, handler never dispatched, side effect never happened.

✅ Right:

```ts
execSync(`bin/console <project-specific-command> <code> <value>`);
```

Or drive the admin UI flow via Playwright (login → edit → save). Or hit an API endpoint that mutates through ORM. `sylius-dev/reference/worked-example.md` has a concrete restock command.

**Why:** Doctrine listeners (`onFlush`, `postFlush`, `preUpdate`, etc.) hook into the UnitOfWork. Raw SQL goes straight to the DB driver, never touches UoW, listeners never see the change.

## 4. Handler-written DB flag as email proof

❌ Wrong (Playwright spec):

```ts
const row = await db.query('SELECT notified_at FROM app_<alias> WHERE email = ?', [email]);
expect(row.notified_at).not.toBeNull();
```

Handler:

```php
foreach ($entities as $entity) {
    $this->sender->send('app_<code>', [$entity->getEmail()], $context);
    $entity->markNotified();
}
```

With `MAILER_DSN=null://null`, `sender->send()` swallows silently. Handler still calls `markNotified()`. DB row updated. Assertion green. No email actually delivered.

✅ Right:

```ts
const messages = await fetch('http://localhost:8025/api/v1/messages').then(r => r.json());
const match = messages.messages.find(m =>
    m.To.some(t => t.Address === email) && m.Subject.includes('<expected subject fragment>')
);
expect(match).toBeDefined();
```

Or via the Mate Symfony profiler tools when the trigger is HTTP-driven:

```
vendor/bin/mate resources:read symfony-profiler://profile/<token>/mailer → assert message present
```

If neither inspectable target available, print `// TODO: assert email via mailpit/profiler` - do not green-light the spec on a DB check.

**Why:** the handler's success path doesn't depend on transport success. A handler-written flag proves the handler ran, not that the email left the building.
