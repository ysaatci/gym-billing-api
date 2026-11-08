# Gym membership billing API

A small Laravel API that handles one complete billing request end to end: **a member subscribes to a gym plan and pays the first invoice, exactly once, even if the request is retried.**

Laravel 13 · PHP 8.4 · Sanctum tokens · Pest · SQLite. About 950 lines of application code (a lot of it comments), readable in under an hour.

## Where to start reading

| | File and function |
|---|---|
| **Request comes in** | [`routes/api.php`](routes/api.php#L17) → [`SubscriptionController::store`](app/Http/Controllers/SubscriptionController.php#L26) |
| **Business logic** | [`SubscriptionService::subscribe`](app/Services/SubscriptionService.php#L43) |
| **Response goes out** | [`SubscriptionResource`](app/Http/Resources/SubscriptionResource.php) (success), [`bootstrap/app.php`](bootstrap/app.php#L51) (errors) |

## The main request, step by step

`POST /api/subscriptions` with `Authorization: Bearer <token>`, `Idempotency-Key: <uuid>` and `{"plan_id": 1}`.

| # | What happens | Where |
|---|---|---|
| 1 | Request ID assigned (or a valid incoming `X-Request-Id` reused), added to every log line | [`AssignRequestId`](app/Http/Middleware/AssignRequestId.php#L24), registered first in [`bootstrap/app.php`](bootstrap/app.php#L26) |
| 2 | Route matched; `auth:sanctum` resolves the user from the bearer token or returns 401 | [`routes/api.php`](routes/api.php#L15) |
| 3 | Validation: plan exists and is active, `Idempotency-Key` header present; otherwise 422 | [`StoreSubscriptionRequest::rules`](app/Http/Requests/StoreSubscriptionRequest.php#L25), header copied in [`prepareForValidation`](app/Http/Requests/StoreSubscriptionRequest.php#L20) |
| 4 | Thin controller hands over user, plan, key and a SHA-256 fingerprint of the body | [`SubscriptionController::store`](app/Http/Controllers/SubscriptionController.php#L28), [`fingerprint`](app/Http/Requests/StoreSubscriptionRequest.php#L58) |
| 5 | **Phase A** transaction: pending subscription, open invoice (price copied), idempotency key claimed. Commit. | [`openSubscription`](app/Services/SubscriptionService.php#L64), [`IdempotencyStore::claim`](app/Services/IdempotencyStore.php#L30) |
| 5b | Key already claimed → unique constraint fails → phase A rolls back → replay (same 201 / same 402), 409 if still in flight, 422 if the body differs | [`catch` in `subscribe`](app/Services/SubscriptionService.php#L47), [`findReplayable`](app/Services/IdempotencyStore.php#L47), [`replay`](app/Services/SubscriptionService.php#L166) |
| 6 | **Gateway call outside any transaction**, inside try/catch | [`payFirstInvoice`](app/Services/SubscriptionService.php#L95) |
| 7 | **Phase B** transaction: active + paid, or failed + void. Key marked completed. Logged. | [`recordPayment`](app/Services/SubscriptionService.php#L141), [`recordDecline`](app/Services/SubscriptionService.php#L154) |
| 8 | 201 + JSON resource, or `PaymentFailedException` → 402 JSON | [`SubscriptionResource`](app/Http/Resources/SubscriptionResource.php#L17), [`bootstrap/app.php`](bootstrap/app.php#L51) |

## Endpoints and access

| Endpoint | Access | Protected by |
|---|---|---|
| `POST /api/auth/register` | public | validation only; `role` can never be set by the client |
| `POST /api/auth/login` | public | returns a Sanctum bearer token |
| `POST /api/subscriptions` | **authenticated** | `auth:sanctum` + `Idempotency-Key` header |
| `GET /api/subscriptions/{id}` | **owner or admin** | `auth:sanctum` + [`SubscriptionPolicy::view`](app/Policies/SubscriptionPolicy.php#L15) |

Plans and an admin user are seeded; there is no plan management API (see limitations).

## Run it

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed      # admin@example.com and member@example.com, password "password" (local only)
php artisan serve
```

Try the main request:

```bash
TOKEN=$(curl -s -X POST localhost:8000/api/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"member@example.com","password":"password"}' | php -r 'echo json_decode(stream_get_contents(STDIN))->token;')

curl -i -X POST localhost:8000/api/subscriptions \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: 6f1c2a9e-0d4b-4c51-9a3e-2b7f1e8d4c10' -d '{"plan_id":1}'
# Run the same curl again: same 201 and body, no second charge. Check storage/logs/billing.log.
# Set FAKE_GATEWAY_DECLINE=true in .env to see the 402 path.
```

## Test it

```bash
vendor/bin/pest           # 38 tests, in-memory SQLite
vendor/bin/pint --test    # code style
```

The tests cover: success; retry replays and **the gateway is called exactly once**; same key with a different body (422); key still in flight (409); same key used by two users; decline (402) and its retry; price snapshot; validation; 401 / 403 / 404; password hashing and token storage; log content (IDs and request ID present, no email or password); redaction; a bare 500 with no stack trace.

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs Pint, Pest and `composer audit` on every push and pull request. Dependabot ([`.github/dependabot.yml`](.github/dependabot.yml)) opens weekly update PRs for Composer packages and GitHub Actions.

## Design decisions

Full reasoning, with costs and rejected alternatives, is in [`CLAUDE.md`](CLAUDE.md#design-decisions-and-trade-offs-mirrored-in-the-readme). Short version:

- **D1 Gateway call outside the transaction**, between two short transactions. A transaction held open over a network call keeps locks; a rollback after a successful charge loses the record that the customer paid.
- **D2 Idempotency table stores the outcome** (which subscription), not the raw HTTP response; the replay rebuilds the same response. Keeps HTTP out of the service.
- **D3 Keys are scoped per user**: `unique(user_id, key)`.
- **D4 The database enforces it**: insert and let the unique constraint refuse, instead of check-then-insert, which races.
- **D5 Same key, different body → 422** instead of silently replaying the wrong result.
- **D6 A decline is final and stored**: the retry gets the same 402 and no second charge.
- **D7 The gateway gets its own key, `invoice-{id}`**, because client keys are only unique per user.
- **D8 Money is integer cents + currency**; the price is copied onto the invoice.
- **D9 Roles are an enum column**; `role` is not fillable, so registration can't create admins.
- **D10 Sanctum opaque tokens** (stored as SHA-256), revocable, not JWT.
- **D11 Non-owner gets 403**, not 404 (trade-off: reveals that the ID exists).
- **D12 Idempotency in its own small class**, `IdempotencyStore`, so the service only orchestrates.
- **D13 Structured billing log + request ID via `Context` + redaction processor.**
- **D14 Replays look identical to the first response.**
- **D15 Status changes only through guarded methods** (`markActive()`, `markPaid()`, ...).
- **D16 Domain exceptions don't know HTTP**; `bootstrap/app.php` maps them to 402 / 409 / 422.

Patterns: **Strategy + dependency injection** for the gateway ([interface](app/Payments/PaymentGateway.php#L16), [binding](app/Providers/AppServiceProvider.php#L17)), a service layer, a `readonly` value object (`ChargeResult`), and Laravel's FormRequest / Policy / Resource. Deliberately **not** used: Repository (Eloquent already is the data layer), State pattern (three states), Decorator, Factory. Reasons in `CLAUDE.md`.

## Review checklist → code

**1. Error handling.** try/catch around the gateway call: [`SubscriptionService.php#L100-L117`](app/Services/SubscriptionService.php#L100). A decline is recorded, then rethrown and rendered as 402 with a reason code ([`bootstrap/app.php#L51`](bootstrap/app.php#L51)). Idempotency conflicts give 409 / 422 (same file, L56-L60). Anything unexpected becomes `{"message":"Server Error"}` with status 500: no class, message or trace with `APP_DEBUG=false` (tested in `LoggingAndErrorsTest`). Invalid status changes throw instead of writing bad data ([`Subscription::markActive`](app/Models/Subscription.php#L33)).

**2. Error logging.**
- Request ID: [`AssignRequestId`](app/Http/Middleware/AssignRequestId.php#L30), on every line and in the `X-Request-Id` header.
- `billing` channel, JSON lines: [`config/logging.php#L74`](config/logging.php#L74).
- Redaction: [`RedactSensitiveData`](app/Logging/RedactSensitiveData.php#L21) masks password, token, authorization, email, card and other keys at any depth, plus email addresses in text.
- Events: `payment.succeeded`, `payment.declined`, `idempotency.*` and `auth.login_failed`, all with IDs only ([`SubscriptionService.php#L112`](app/Services/SubscriptionService.php#L112), [`AuthController.php#L54`](app/Http/Controllers/AuthController.php#L54)).
- Expected outcomes are `dontReport` ([`bootstrap/app.php#L37`](bootstrap/app.php#L37)), so the error log holds only real errors.

**3. Clean code.** Thin controller; one job per class; names say what they do (`openSubscription`, `payFirstInvoice`, `recordDecline`). Comments explain *why* and what Laravel does underneath. See "What I'd clean up" below.

**4. Database credentials.** Read from environment variables in [`config/database.php`](config/database.php#L54) (`env('DB_PASSWORD')`). `.env` is gitignored; `.env.example` has no secrets. Local dev uses SQLite, so there's no password at all. In production the variables would be injected at deploy time from a secrets store (Vault, AWS Secrets Manager), with rotation, never baked into the image or repo.

**5. Login and permissions.**
- Hashing: [`Hash::make`](app/Http/Controllers/AuthController.php#L31) (bcrypt, salted, cost factor).
- Compare: [`Hash::check`](app/Http/Controllers/AuthController.php#L51), constant-time.
- Same 401 for an unknown email and a wrong password.
- Who the user is on each request: Sanctum token from [`createToken`](app/Http/Controllers/AuthController.php#L62). Only its SHA-256 hash is stored, and `auth:sanctum` checks it on every request.
- What they may do: [`SubscriptionPolicy::view`](app/Policies/SubscriptionPolicy.php#L17) (owner or admin), called by [`Gate::authorize`](app/Http/Controllers/SubscriptionController.php#L53).

**6. Object-oriented design.**
- Layers: Controller → Service → Model.
- `PaymentGateway` interface with a documented contract; the fake is swappable via one container binding.
- `IdempotencyStore` split out of the service.
- `SubscriptionResource` is the only place that shapes subscription JSON, and `bootstrap/app.php` is the only place that maps exceptions to HTTP.

**7. Authentication and authorization.** See the endpoints table above. Public routes are listed explicitly; everything else is inside the `auth:sanctum` group ([`routes/api.php#L15`](routes/api.php#L15)). Guests always get 401 JSON, never a redirect ([`bootstrap/app.php#L23`](bootstrap/app.php#L23)).

**8. Database access.**
- Eloquent and the query builder only, so every value is a bound PDO parameter (`where email = ?`). Comments show the SQL at each query.
- No raw SQL anywhere.
- Two short transactions around the gateway call ([`L67`](app/Services/SubscriptionService.php#L67), [`L143`](app/Services/SubscriptionService.php#L143)).
- The unique constraint that makes idempotency safe under concurrency: [`idempotency_keys` migration L25](database/migrations/2026_11_09_100003_create_idempotency_keys_table.php#L25).

**9. General practice.** Pest feature and unit tests (above). FormRequest validation on every endpoint. CI with style, tests and a security audit. Dependabot. Review and deploy below.

## Known limitations / what I'd change

Left out on purpose to stay inside the timebox. Correctness and security were not cut.

| Limitation | What I'd do |
|---|---|
| **Crash between the charge and phase B** leaves the key `processing` (retries get 409) and the subscription `pending`. Same if a gateway breaks its contract (tested). | Gateway webhooks plus a reconciliation job that asks the provider about `pending` invoices (safe, because the provider has our `invoice-{id}` key). A timeout on stale `processing` keys. |
| **Gateway timeouts are treated as declines** (the gateway contract converts them). In reality the outcome is unknown. | A separate "unknown" outcome that leaves the subscription `pending` for reconciliation. |
| **Payment runs inside the HTTP request.** | Queued job with retries and backoff; respond 202 with the pending subscription. Publish `SubscriptionActivated` via an outbox table so other services get events exactly once. |
| **Concurrency test is thin.** SQLite runs writes one at a time, so the 409 test sets up the in-flight state instead of racing two real requests. | A CI job against MariaDB that fires parallel requests with the same key. |
| **No rate limiting** on login or subscriptions. | Laravel `RateLimiter` per IP and per account on login, per user on subscriptions. |
| **Login timing differs for unknown emails** (the bcrypt check is skipped), which leaks whether an account exists. | Check against a dummy hash when the user doesn't exist. |
| **Tokens never expire** (`config/sanctum.php` `expiration => null`) and there's no logout or rotation. | Short expiry, a logout endpoint that deletes the token, and per-device tokens. |
| **Idempotency keys are kept forever.** | Scheduled cleanup after ~24h (the usual provider window). |
| **No plan management API, no plan listing, no renewals or dunning,** and nothing stops two active subscriptions to the same plan. | Admin plan endpoints, a scheduled renewal command creating new invoices, a `past_due` status with retry emails, a uniqueness rule on active subscriptions. |
| **403 vs 404** reveals which subscription IDs exist. | Return 404 for other users' subscriptions in a public API. |
| **Fake gateway only.** | `StripeGateway` implementing `PaymentGateway` (an Adapter over the SDK), plus one binding change. |

## What I'd clean up

- `recordPayment()` and `recordDecline()` are near-duplicates. I kept them separate so each reads top to bottom.
- `IdempotencyStore` would become middleware once a second endpoint needs idempotency.
- The request fingerprint lists fields by hand. Fine for one field; it needs a canonical-JSON helper as the body grows.
- A `LoggingPaymentGateway` decorator would take logging out of the service (open/closed).
- An `Idempotent-Replayed` response header would make replays visible when debugging.
- `SubscriptionResource` builds the plan and invoice arrays inline; separate resources once other endpoints need them.

## How I'd review and deploy this

**Review.** Every change goes through a pull request: CI must be green (style, tests, `composer audit`), with one reviewer for normal changes and two for anything touching money or auth. Reviewers ask:
- Does a retry still charge exactly once?
- Is anything new logged that shouldn't be?
- Are migrations backwards compatible?

**Deploy.**
1. Build once and deploy the same artifact to staging, then production.
2. Secrets come from the secrets store at runtime. `APP_DEBUG=false` in production.
3. Migrations are additive and run before the new code (expand, then contract later), so old and new versions can run side by side for a zero-downtime rolling deploy.
4. A new gateway goes live behind a feature flag for a small share of traffic first.
5. After release, watch the error rate, the `payment.declined` rate and the number of `processing` keys older than a few minutes. Roll back by redeploying the previous artifact.

## About this code

Written for this review as a timeboxed exercise, with AI assistance (Claude Code; see `CLAUDE.md` for the working brief). I read, ran and can explain every line, and the commit history shows the order it was built in.
