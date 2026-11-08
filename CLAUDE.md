# Project brief: Gym membership billing API (Laravel)

Working agreement and design notes for this repository. Used by me and by the AI assistant that helps me build it.

## Goal
A small, readable Laravel API that handles one complete billing request end to end: the request comes in, is authenticated and validated, runs business logic, reads and writes the database in a transaction, calls a payment gateway, and returns a response.

Constraints:
- Readable in 40-60 minutes. Built in a ~4 hour timebox.
- Every line must be understood and explainable by me. AI-assisted code is reviewed line by line before it is committed.
- README names the file and function where the request comes in and the file where the response goes out.

## How we work
- Build in small steps. After each step, explain what was written and why.
- Keep it small and readable. No unnecessary packages, no over-engineering. If something isn't needed for the main request, leave it out and list it in the README.
- Don't hide framework magic: when Laravel does something for us (password hashing, bound parameters, token storage), add a short comment saying what happens underneath.
- Run the tests after each step. Keep CI green. Commit after each step with a clear message.
- When a design decision below is implemented, add a one-line comment at that spot pointing to it (e.g. `// Gateway call is outside the transaction: see README "Design decisions" D1`).

## Scope discipline
- **Correctness and security are not negotiable:** no double charges, no string-built SQL, no plaintext passwords, no personal data in logs, no floating-point money.
- **Missing features and polish are acceptable inside the timebox**, as long as each one is documented in the README with what's missing, why it was left out, and what I'd do next.
- Mark each open item at the place in the code where it would go, with a short comment pointing to "Known limitations". Keep these few (5-8).
- Keep an honest "what I'd clean up" list too, not only missing features (e.g. the phase B method in the service could be split further; `IdempotencyStore` would be wrapped in middleware once a second endpoint needs it (D12); the request hash ignores encoding edge cases).

## Stack
Current Laravel release, PHP 8.3+, Sanctum for API tokens, Pest for tests, SQLite for local dev and CI.

## Endpoints
| Endpoint | Access | Purpose |
|---|---|---|
| POST /api/auth/register | public | Create a member account |
| POST /api/auth/login | public | Exchange email + password for an API token |
| POST /api/subscriptions | authenticated member, requires `Idempotency-Key` header | **The main request:** subscribe to a plan and pay the first invoice |
| GET /api/subscriptions/{id} | owner or admin | Read a subscription with its invoice |

Plans and an admin user are **seeded**, not managed via the API (no plan CRUD, no plan listing). Both are listed as limitations in the README.

## Data model
- `users`: id, name, email (unique), password (bcrypt hash), role (`member` | `admin`, PHP enum, default `member`)
- `plans`: id, name, price_cents (int), currency (char 3), billing_period (`monthly`), is_active
- `subscriptions`: id, user_id, plan_id, status (`pending` | `active` | `failed`), started_at, timestamps
- `invoices`: id, subscription_id, amount_cents, currency (snapshot of the plan price), status (`open` | `paid` | `void`), gateway_reference (nullable), timestamps
- `idempotency_keys`: id, user_id, key, request_hash, status (`processing` | `completed`), response_code (nullable), response_body (json, nullable), subscription_id (nullable), timestamps. **unique(user_id, key)**

## Main flow: `POST /api/subscriptions`
1. `routes/api.php` -> middleware: `auth:sanctum`, `AssignRequestId`
2. `StoreSubscriptionRequest` (FormRequest): `plan_id` exists and is active; `Idempotency-Key` header present, max 255 chars
3. `SubscriptionController::store` -> calls `SubscriptionService::subscribe(user, plan, key, requestHash)`. The controller stays thin.
4. Service, **phase A** (`DB::transaction`): claim the idempotency key (`processing`), create the subscription (`pending`) and the invoice (`open`, price snapshot). Commit.
   - Unique violation on the key -> load the existing row: `completed` -> replay stored response; `processing` -> 409 Conflict; different `request_hash` -> 422.
5. **Gateway call, outside any transaction**: `PaymentGateway::charge(amountCents, currency, idempotencyKey): ChargeResult` inside try/catch. Fake implementation injected via the container. The gateway gets plain values, not the Eloquent `Invoice`, so it doesn't depend on our database models.
6. Service, **phase B** (`DB::transaction`): success -> subscription `active`, invoice `paid` + gateway reference. Decline -> subscription `failed`, invoice `void`. Either way store response code and body on the key row and mark it `completed`.
7. Success -> `SubscriptionResource` (201, includes the invoice). Decline -> `PaymentFailedException` -> exception handler renders clean JSON 402.

## Design decisions and trade-offs (mirrored in the README)

**D1. Gateway call outside the DB transaction (two short transactions around it).**
Why: holding a transaction open during a network call keeps locks and a connection for seconds, and a rollback after a successful charge loses the record that the customer paid.
Cost: longer code, and a window where the charge succeeded but phase B hasn't run (crash). That window is the main known limitation (see D6).
Rejected: one big transaction. Simpler to read, but wrong for payments.

**D2. Separate `idempotency_keys` table, not a column on `subscriptions`.**
Why: it can store the response for *any* outcome (including declines, which don't produce an active subscription) and replay it exactly. It also holds the request hash.
Cost: one more table and model.
Rejected: unique column on subscriptions. Fine for successes, but can't replay a 402.

**D3. Key scoped per user, `unique(user_id, key)`.**
Why: one user's key can't collide with, or replay, another user's response.
Cost: none really; clients still have to generate unique keys (UUID v4).

**D4. Concurrency handled by the database unique constraint, not by locks in PHP.**
Why: the DB is the only place both requests share; the second insert fails atomically. Catch `UniqueConstraintViolationException`, then decide replay / 409 / 422.
Cost: the losing request gets a 409 while the first is still processing and must retry. SQLite serializes writes, so the concurrency test is thin (limitation; a real check would run against MariaDB).
Rejected: `Cache::lock` (needs Redis, and the lock isn't atomic with the DB write) or `SELECT ... FOR UPDATE` on a row that doesn't exist yet (nothing to lock).

**D5. Same key, different body -> 422.** The request hash is SHA-256 of the validated input. Same behaviour as Stripe; catches client bugs instead of silently replaying the wrong result.

**D6. Failure semantics.**
- Decline (`PaymentFailedException`): a **final** result. Stored, so a retry with the same key gets the same 402 and is never charged again.
- Unexpected gateway error / timeout: treated like a failure and stored in this version. In reality the outcome is *unknown* (the charge may have gone through), so the subscription should stay `pending` and be resolved by a gateway webhook or a reconciliation job. Passing our idempotency key on to the gateway (D7) is what makes a later retry safe. Documented as a limitation.

**D7. Idempotency key is passed through to the gateway.** Real providers (Stripe, Adyen) accept one. It protects against double charges even if our own bookkeeping fails.

**D8. Money as integer cents plus currency; price snapshot on the invoice.** Floats can't represent 0.10 exactly. Snapshotting means a later plan price change doesn't rewrite old invoices.

**D9. Roles as an enum column, not a permissions package.** Two roles don't justify a package. `role` is **not** fillable, so nobody can register as admin through mass assignment. Would switch to a package when roles and permissions grow.

**D10. Sanctum personal access tokens, not JWT.** Opaque tokens are stored as SHA-256 hashes and can be revoked instantly by deleting the row. Cost: one DB lookup per request; no expiry configured (limitation).

**D11. Non-owner gets 403, not 404.** 403 is clearer for clients. Trade-off: it confirms that a subscription ID exists (enumeration). For a public API I'd return 404.

**D12. Idempotency logic lives in a small `IdempotencyStore` class injected into `SubscriptionService`, not in generic middleware.** The store does three things: `claim()`, `findReplay()`, `complete()`. The service only orchestrates (SRP), and the flow is still readable top to bottom in the service. Cost: one more file. When a second endpoint needs idempotency, wrap the store in middleware.

**D13. Logging: dedicated `billing` channel, request ID via `Log::withContext()`, Monolog processor that redacts `password`, `token`, `authorization`, `email`, `card*`.** Log user/subscription/invoice IDs, never emails or card data. Redaction is a safety net; the first rule is to not pass personal data to the logger at all.

**D14. Replays return the original status code and body, plus `Idempotent-Replayed: true`.** Clients don't need to tell the cases apart, but debugging is easier.

## Design patterns and SOLID
Rule: each pattern must earn its place. In a small codebase, a pattern that can't be justified is a cost.

Used:
- **Strategy + dependency injection: `PaymentGateway` interface.** `FakePaymentGateway` now, `StripeGateway`/`AdyenGateway` later; swapped with one binding in `AppServiceProvider`. The service never knows which one it has.
- **Adapter (future):** a real gateway class adapts the provider's SDK to our interface. Not built.
- **Service layer:** `SubscriptionService` orchestrates the use case; controllers stay thin.
- **DTO / value object:** `ChargeResult` (PHP `readonly` class: `reference`, `status`) returned by the gateway, so callers never handle raw SDK arrays.
- **Laravel built-ins as patterns:** FormRequest (validation), Policy (authorization), API Resource (output shaping), service container (IoC).

Considered and rejected:
- **Repository pattern:** Eloquent is already Active Record. A repository over it is a second API for the same thing. Cost of skipping it: the service can't be unit-tested without a DB; SQLite feature tests cover that instead.
- **State pattern for subscription status:** three states and two transitions. A PHP enum plus guarded methods (`markActive()`, `markFailed()`) is enough.
- **Decorator (`LoggingPaymentGateway`):** a clean way to add logging without touching gateways (open/closed). Too much for this size; listed under "what I'd change".
- **Factory:** the container already builds the gateway.

SOLID in this codebase:
- **S:** one job per class: Controller (HTTP), FormRequest (validation), Policy (authz), SubscriptionService (orchestration), IdempotencyStore (keys), PaymentGateway (charging), Resource (response), exception handler (error responses).
- **O:** adding a gateway means a new class plus a new binding; the service doesn't change.
- **L:** every `PaymentGateway` keeps the contract documented on the interface: returns `ChargeResult` on success, throws `PaymentFailedException` on decline. The fake behaves exactly like that, so tests stay valid.
- **I:** `PaymentGateway` has only `charge()`. Refunds would get their own interface when needed.
- **D:** the service depends on the `PaymentGateway` interface, not a concrete class; the container injects the implementation.

## Review checklist -> where it lives
1. **Error handling:** try/catch around the gateway call in the service, custom `PaymentFailedException`, exception handler in `bootstrap/app.php` returns clean JSON with no stack trace (`APP_DEBUG=false`).
2. **Error logging:** `billing` channel, request ID in every entry, redaction processor (D13).
3. **Clean code:** clear names, small methods, useful comments. "What I'd clean up" list in README.
4. **DB credentials:** from `.env` via `config/database.php`. `.env` gitignored, `.env.example` committed. In production, secrets come from a secrets store (Vault / AWS Secrets Manager) injected as env vars at deploy, with rotation.
5. **Login and permissions:** `Hash::make` and `Hash::check`, Sanctum token auth on each request, `SubscriptionPolicy::view` for owner-or-admin.
6. **OOP design:** Controller -> Service -> Model, `PaymentGateway` interface bound in a service provider, API Resource for responses, no duplicated logic.
7. **Authn/Authz:** route table in README marking each endpoint public / authenticated / owner-or-admin.
8. **Database access:** Eloquent/query builder with bound parameters only, never string-built SQL. Two transactions (D1). Unique constraint on the idempotency key (D4).
9. **General practice:** Pest feature tests (success, validation failure, unauthenticated 401, forbidden 403, duplicate key replay with **gateway called exactly once**, same key different body 422, gateway decline 402, no `trace` in error JSON), a unit test for log redaction, GitHub Actions workflow, Dependabot (composer + github-actions), `composer audit` in CI, README section on review and deploy.

## Known limitations (documented in README)
No rate limiting on login, no token expiry/rotation, fake gateway, no webhooks or reconciliation (D6), thin concurrency test on SQLite (D4), no plan management API or plan listing (seeded), no recurring renewals or dunning, no "one active subscription per plan" rule. The payment call should move to a queued job with retries, and state changes should publish events via an outbox. README says what I'd change and why.

## README contents
- How to run and test
- Route table (access per endpoint)
- Entry point: `routes/api.php` and `SubscriptionController::store`
- Exit point: `SubscriptionResource`, and the exception handler for errors
- Step-by-step trace of the main request with file:line for each step
- Design decisions D1-D14 (short)
- Checklist items mapped to files and lines
- Known limitations / what I'd change
- How I'd review and deploy this

## Build order (~4 hour timebox)
1. Scaffold, migrations, models, seeders (30 min)
2. Register/login, policy, FormRequest (45 min)
3. Subscription service: two transactions, idempotency, fake gateway (75 min)
4. Error handling and logging (40 min)
5. Tests, CI, Dependabot, README (50 min)
