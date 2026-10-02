# Object storage instead of a database

Date: 2026-10-02

## Why

Laravel Cloud quotes about $8.30 a month for a database. The app stores four stops, one device token and one admin login. A bucket holds that for pennies.

## Goal

The app runs with no database, locally and on Laravel Cloud. The admin page, the R1 page and `bus:make-admin` behave as they do now, apart from the changes listed under "Behaviour changes".

## Out of scope

- Moving the two stops saved in the local SQLite file. They get re-entered in admin.
- More than one app instance. The file cache and rate limiter are per instance.
- Locking for concurrent writes. There is one admin.

## Storage

One file, `bus.json`, on the default filesystem disk.

- Locally the default disk is `local`, so the file is `storage/app/private/bus.json`.
- On Laravel Cloud an attached bucket marked as the default disk supplies `FILESYSTEM_DISK` and the `AWS_*` variables. The app needs `league/flysystem-aws-s3-v3` and no other configuration.

Shape:

```json
{
  "device_token": "48 random characters",
  "admin": { "email": "you@example.com", "password": "bcrypt hash" },
  "stops": {
    "morning": {
      "outward": { "naptan_id": "490011233N", "name": "Priory Park", "stop_letter": "R", "towards": "Blackheath" }
    }
  }
}
```

Any key may be missing. A missing file reads as an empty document.

The bucket must be private. `bus.json` holds the device token and the password hash, and a public bucket would serve both at a public URL. Laravel Cloud sets visibility when the bucket is created and does not allow it per file, so the app must not pass a `visibility` option when writing.

## Components

### `App\Services\BusStore`

The only code that touches `bus.json`.

- `stop(Slot, Direction): ?Stop`
- `stops(): array` keyed by slot value, then direction value
- `saveStop(Slot, Direction, Stop): void`
- `deviceToken(): ?string` and `setDeviceToken(string): void`
- `admin(): ?array` with `email` and `password`, and `setAdmin(string $email, string $hash): void`

Reads go through the cache under one key for 60 seconds. Every write saves the file and then forgets that key. With the R1 polling every 20 seconds this caps bucket reads at one a minute.

A file that exists but is not valid JSON throws. The app must not treat it as empty and then overwrite it.

### `App\Data\Stop`

Readonly value object with `slot`, `direction`, `naptanId`, `name`, `stopLetter`, `towards`. Replaces the `StopSchedule` model everywhere it is read: `ArrivalsService`, `ArrivalsResult`, `DeviceController`, the editor and its view.

### `App\Services\DeviceToken`

Same three methods. `current()` creates and saves a token when none exists, as now.

### Admin login

A custom user provider registered in `AppServiceProvider` and named in `config/auth.php`. It returns a `GenericUser` built from `BusStore::admin()`, with the email as the identifier, and checks the password with `Hash::check`.

`LoginController` stops passing `remember: true`. A remember token would need storage that changes on every login.

`bus:make-admin` validates as now, hashes the password and calls `BusStore::setAdmin`. It replaces whatever admin was there. There is one admin.

### Drivers

`.env.example` and the README change to `SESSION_DRIVER=cookie`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, and `SESSION_LIFETIME=43200` for a 30 day login.

### Removed

`StopSchedule`, `Setting` and `User` models, `UserFactory`, `DatabaseSeeder`, and all six migrations.

## Behaviour changes

- Login lasts as long as the session cookie, 30 days, instead of using a remember token.
- Changing the admin password does not sign out a browser that is already signed in.
- A deploy clears the file cache. The next poll fetches from TfL, and for the first minute after a deploy there is no stale result to fall back on.
- If the bucket cannot be read and the cached copy has expired, the device endpoint returns an error and the R1 shows "No connection".

## Tests

- `tests/Pest.php` swaps `RefreshDatabase` for `Storage::fake()` on the default disk. The cache is already the array driver under test.
- Existing tests keep their assertions. Setup that created models now calls `BusStore`. Assertions that counted rows now read `BusStore::stops()`.
- New `BusStoreTest`:
  - a missing file reads as empty
  - a stop, the token and the admin each round trip
  - saving one stop leaves the others and the token in place
  - a write clears the cached read
  - a second read within 60 seconds does not touch the disk
  - invalid JSON throws and leaves the file alone
- `AdminAuthTest` covers login against the stored admin, a wrong password, and `bus:make-admin` replacing the admin.
- The JS suite does not change.

## Laravel Cloud setup

1. Create a private Laravel Object Storage bucket, attach it to the environment and mark it as the default disk.
2. Set `SESSION_DRIVER=cookie`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, `SESSION_LIFETIME=43200`.
3. Redeploy. Run `php artisan bus:make-admin you@example.com`.
4. Sign in, set the stops, copy the R1 link.
5. Remove the database resource.

The README gets these steps in place of the `migrate` lines.

## Risks

- Cookie sessions hold the session payload in an encrypted cookie. Livewire and the login flow only store small values, so the 4 KB cookie limit is not a concern here.
- The 60 second cache means a regenerated R1 link could keep working for up to a minute on another instance. With one instance the write clears the cache at once.
