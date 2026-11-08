# Gym membership billing API

A member subscribes to a gym plan and pays the first invoice: `POST /api/subscriptions`. A retried request with the same `Idempotency-Key` never charges twice.

Laravel 13 · PHP 8.4 · Sanctum · Pest · SQLite

## Where the request comes in

[`routes/api.php`](routes/api.php#L13) routes `POST /api/subscriptions` to **`SubscriptionController::store`** in [`app/Http/Controllers/SubscriptionController.php`](app/Http/Controllers/SubscriptionController.php#L18).

## Where the response goes out

[`app/Http/Resources/SubscriptionResource.php`](app/Http/Resources/SubscriptionResource.php#L16), returned with status 201. Error responses are built in [`bootstrap/app.php`](bootstrap/app.php#L40).

## Run and test

```bash
composer setup     # install, .env, SQLite, migrate + seed (member@example.com / admin@example.com, password "password")
composer dev       # http://localhost:8000
vendor/bin/pest    # 37 tests
```

Built as a timeboxed exercise with AI assistance (Claude Code), in small reviewed steps. I've read and can explain every line.
