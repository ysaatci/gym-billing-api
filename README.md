# Gym membership billing API

A member subscribes to a gym plan and pays the first invoice, **exactly once, even if the request is retried.**

Laravel 13 · PHP 8.4 · Sanctum · Pest · SQLite

## Where to start reading

| | |
|---|---|
| **Request comes in** | [`routes/api.php`](routes/api.php#L13) → [`SubscriptionController::store`](app/Http/Controllers/SubscriptionController.php#L18) |
| **Business logic** | [`SubscriptionService::subscribe`](app/Services/SubscriptionService.php#L40) |
| **Response goes out** | [`SubscriptionResource`](app/Http/Resources/SubscriptionResource.php#L16); errors in [`bootstrap/app.php`](bootstrap/app.php#L40) |

## The main request: `POST /api/subscriptions`

Headers `Authorization: Bearer <token>` and `Idempotency-Key: <uuid>`, body `{"plan_id": 1}`.

| # | Step | Where |
|---|---|---|
| 1 | Request ID assigned, added to every log line | [`AssignRequestId`](app/Http/Middleware/AssignRequestId.php#L26) |
| 2 | Bearer token checked, or 401 | [`routes/api.php`](routes/api.php#L12) |
| 3 | Plan exists and is active, key header present, or 422 | [`StoreSubscriptionRequest`](app/Http/Requests/StoreSubscriptionRequest.php#L15) |
| 4 | **Phase A** transaction: pending subscription, open invoice, key claimed | [`openSubscription`](app/Services/SubscriptionService.php#L61) |
| 5 | Key already claimed → replay the first answer, 409 if still running, 422 if the body differs | [`subscribe`](app/Services/SubscriptionService.php#L44), [`replay`](app/Services/SubscriptionService.php#L140) |
| 6 | Gateway call, **outside any transaction** | [`payFirstInvoice`](app/Services/SubscriptionService.php#L89) |
| 7 | **Phase B** transaction: active + paid, or failed + void | [`recordPayment`](app/Services/SubscriptionService.php#L120), [`recordDecline`](app/Services/SubscriptionService.php#L130) |
| 8 | 201 with the subscription, or 402 on decline | [`SubscriptionResource`](app/Http/Resources/SubscriptionResource.php#L16), [`bootstrap/app.php`](bootstrap/app.php#L40) |

## Endpoints

| Endpoint | Access |
|---|---|
| `POST /api/auth/register` | public |
| `POST /api/auth/login` | public, returns a bearer token |
| `POST /api/subscriptions` | authenticated |
| `GET /api/subscriptions/{id}` | owner or admin ([`SubscriptionPolicy`](app/Policies/SubscriptionPolicy.php#L12)) |

Plans and an admin user are seeded; there is no plan management API.

## Run and test

```bash
composer setup     # install, .env, SQLite, migrate + seed (admin@ / member@example.com, password "password")
composer dev       # http://localhost:8000
vendor/bin/pest    # 36 tests
```

Send the same `POST /api/subscriptions` twice with the same `Idempotency-Key`: same 201 and body, charged once. `FAKE_GATEWAY_DECLINE=true` in `.env` shows the 402 path. Payment events are in `storage/logs/billing.log`.

CI runs Pint, Pest and `composer audit` on every push ([workflow](.github/workflows/ci.yml)); Dependabot opens weekly update PRs.

## Key decisions

- **The gateway call sits between two short transactions, not inside one.** No locks held during a network call, and a rollback can't erase a charge that happened.
- **The database enforces idempotency.** `unique(user_id, key)` ([migration](database/migrations/2026_11_09_100003_create_idempotency_keys_table.php#L22)). We insert and let it refuse, because check-then-insert races.
- **A retry gets the same answer, rebuilt from the stored outcome.** A decline stays a decline (402), and the gateway is not called again.
- **The provider gets its own key, `invoice-{id}`.** Client keys are only unique per user.
- **Money is integer cents + currency**, and the price is copied onto the invoice.
- **The payment provider sits behind an interface** ([`PaymentGateway`](app/Payments/PaymentGateway.php#L14), bound in [`AppServiceProvider`](app/Providers/AppServiceProvider.php#L14)), so a real provider is one new class.

## Review checklist → code

1. **Error handling:** try/catch around the gateway in [`payFirstInvoice`](app/Services/SubscriptionService.php#L94). Exceptions are mapped to 402/409/422 in [`bootstrap/app.php`](bootstrap/app.php#L40). Anything unexpected is a bare `{"message":"Server Error"}` with no stack trace.
2. **Error logging:** request ID on every line ([middleware](app/Http/Middleware/AssignRequestId.php#L26)), a JSON `billing` channel ([config](config/logging.php#L72)) and [redaction](app/Logging/RedactSensitiveData.php#L17) of passwords, tokens, emails and card data. Only IDs are logged.
3. **Clean code:** thin controller; one job per class. Known rough spots are listed below.
4. **DB credentials:** `env('DB_PASSWORD')` in [`config/database.php`](config/database.php#L54). `.env` is gitignored. In production they'd come from a secrets store.
5. **Login and permissions:** [`Hash::make`](app/Http/Controllers/AuthController.php#L23) and [`Hash::check`](app/Http/Controllers/AuthController.php#L37); a Sanctum [token](app/Http/Controllers/AuthController.php#L44) on each request; [`Gate::authorize`](app/Http/Controllers/SubscriptionController.php#L36) with the policy. `role` is [not fillable](app/Models/User.php#L15).
6. **OO design:** Controller → Service → Model. Idempotency lives in its own [`IdempotencyStore`](app/Services/IdempotencyStore.php#L27), and the gateway is injected through its interface.
7. **Authn/Authz:** see the endpoints table. Guests always get a 401 JSON response, never a redirect ([`bootstrap/app.php`](bootstrap/app.php#L20)).
8. **Database access:** Eloquent only, with bound parameters and no raw SQL. Two transactions ([A](app/Services/SubscriptionService.php#L63), [B](app/Services/SubscriptionService.php#L122)) and the unique constraint above.
9. **General practice:** Pest feature and unit tests, FormRequest validation, CI, Dependabot.

## Known limitations / what I'd change

- **If the process crashes between the charge and phase B**, the key stays `processing` (retries get 409) and the subscription stays `pending`. → Webhooks plus a reconciliation job, and a timeout on stale keys.
- **Gateway timeouts are treated as declines**, though the real outcome is unknown. → Keep them `pending` for reconciliation.
- **Payment runs inside the HTTP request.** → A queued job with retries; events through an outbox.
- **The concurrency test is thin:** SQLite can't race two real requests. → A MariaDB CI job with parallel requests.
- **No rate limiting, and tokens never expire.** Login timing also reveals whether an email exists. → `RateLimiter`, token expiry and logout, and a dummy hash check.
- **Not built:** idempotency key cleanup, plan management, renewals and dunning.

Rough spots I'd clean up: `recordPayment`/`recordDecline` are near-duplicates; `IdempotencyStore` should become middleware once a second endpoint needs it; the request fingerprint lists fields by hand.

---
Built as a timeboxed exercise with AI assistance (see [`CLAUDE.md`](CLAUDE.md)). I've read and can explain every line.
