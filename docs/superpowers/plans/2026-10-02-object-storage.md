# Object storage instead of a database: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run the app with no database by keeping the stops, device token and admin login in one JSON file on the default filesystem disk.

**Architecture:** `App\Services\BusStore` is the only code that reads or writes `bus.json`. Everything that used an Eloquent model goes through it. Sessions move to the cookie driver, cache to the file driver, and the queue to sync.

**Tech stack:** Laravel 13, Livewire 4, Pest 5, `league/flysystem-aws-s3-v3` for the Laravel Cloud bucket.

**Spec:** `docs/superpowers/specs/2026-10-02-object-storage-design.md`

## Global constraints

- The file is `bus.json` on the default disk, `Storage::disk()`.
- Never pass a `visibility` option when writing. R2 rejects it.
- Reads are cached for 60 seconds under one key. Every write forgets that key.
- A file that exists but is not valid JSON throws. It is never treated as empty.
- One admin. `bus:make-admin` replaces it.
- No `remember: true` on login.
- Each task ends with `./vendor/bin/pest` and `npm test` both green, then a commit.

## Review focus

1. The bucket cannot be reached. With `throw` off, `Storage::get` returns null for a failed read, which looks the same as a missing file. A later write would then replace real data with a near empty file. `BusStore` checks `exists()` first, which throws on a connection error, and throws if `get` returns null for a file that exists. Test in Task 1.
2. A write fails silently. `Storage::put` returns false with `throw` off. The admin would see "Saved" with nothing saved. `BusStore` throws when `put` returns false. Test in Task 1.
3. A stop in `bus.json` is missing `naptan_id` or `name`, for example after a hand edit. It reads as unset. Test in Task 1.
4. No admin has been created yet. Login fails with the normal "do not match" message and does not error. Test in Task 4.
5. A browser is signed in and the admin email is then replaced. The old session is signed out on its next request. Test in Task 4.

---

### Task 1: `BusStore` and `Stop`

**Files:**
- Create: `app/Services/BusStore.php`, `app/Data/Stop.php`, `tests/Feature/BusStoreTest.php`
- Modify: `composer.json` (add `league/flysystem-aws-s3-v3 ^3.0`), `tests/Pest.php` (add `Storage::fake('local')` to the shared `beforeEach`)

**Produces:**

```php
final readonly class Stop {
    public function __construct(
        public Slot $slot, public Direction $direction,
        public string $naptanId, public string $name,
        public ?string $stopLetter = null, public ?string $towards = null,
    ) {}
}

final class BusStore {
    public function stop(Slot $slot, Direction $direction): ?Stop;
    /** @return array<string, array<string, Stop>> slot value, then direction value */
    public function stops(): array;
    public function saveStop(Stop $stop): void;
    public function deviceToken(): ?string;
    public function setDeviceToken(string $token): void;
    /** @return array{email: string, password: string}|null */
    public function admin(): ?array;
    public function setAdmin(string $email, string $passwordHash): void;
}
```

- [ ] Write `BusStoreTest` with these cases, run it, watch it fail on the missing class:
  - a missing file reads as empty
  - a stop round trips with its letter and destination
  - the token and the admin round trip
  - saving one stop leaves the other stops, the token and the admin in place
  - a write is visible on the next read even though reads are cached
  - a second read within 60 seconds does not touch the disk, and one after 60 seconds does
  - invalid JSON throws and leaves the file as it was
  - a stop missing its id or name reads as unset
  - a failed write throws
  - a file that exists but cannot be read throws and is not overwritten
- [ ] `composer require league/flysystem-aws-s3-v3 "^3.0" --with-all-dependencies`
- [ ] Implement `Stop` and `BusStore`. Run the test file, then both suites.
- [ ] Commit: `feat: add BusStore for stops, token and admin in one JSON file`

### Task 2: device token on `BusStore`

**Files:**
- Modify: `app/Services/DeviceToken.php`, `tests/Feature/DeviceTokenTest.php`
- Delete: `app/Models/Setting.php`

**Consumes:** `BusStore::deviceToken()`, `BusStore::setDeviceToken()`.

- [ ] Change the two `Setting::count()` assertions to read `app(BusStore::class)->deviceToken()`. Run, watch them fail because the token is still in the database.
- [ ] `DeviceToken` takes `BusStore` in its constructor. `current()` returns the stored token or creates, saves and returns a 48 character one. `regenerate()` saves a new one.
- [ ] Delete `Setting`. Both suites green. Commit: `refactor: keep the device token in BusStore`

### Task 3: stops on `BusStore`

**Files:**
- Modify: `app/Services/ArrivalsService.php`, `app/Services/ArrivalsResult.php`, `app/Http/Controllers/DeviceController.php`, `app/Livewire/StopScheduleEditor.php`, `resources/views/livewire/stop-schedule-editor.blade.php`, `tests/Feature/ArrivalsServiceTest.php`, `tests/Feature/DeviceArrivalsTest.php`, `tests/Feature/StopScheduleEditorTest.php`
- Delete: `app/Models/StopSchedule.php`

**Consumes:** `Stop`, `BusStore::stop()`, `BusStore::stops()`, `BusStore::saveStop()`.

- [ ] In the three test files replace `StopSchedule::create([...])` with `app(BusStore::class)->saveStop(new Stop(...))`, and row counts with `stops()`. Run, watch them fail because the app still reads the database.
- [ ] `ArrivalsService::forSlot` reads `BusStore::stop()`. `ArrivalsResult::$stop` is a `Stop`. `DeviceController::live` reads `name`, `stopLetter`, `towards`, `direction`. The editor saves through `saveStop` and renders `stops()`.
- [ ] Delete `StopSchedule`. Both suites green. Commit: `refactor: keep the stops in BusStore`

### Task 4: admin login on `BusStore`

**Files:**
- Create: `app/Auth/BusStoreUserProvider.php`
- Modify: `app/Providers/AppServiceProvider.php`, `config/auth.php`, `app/Http/Controllers/LoginController.php`, `app/Console/Commands/MakeAdmin.php`, `tests/Pest.php` (add a `signedInAdmin()` helper), `tests/Feature/AdminAuthTest.php`, `tests/Feature/StopScheduleEditorTest.php`
- Delete: `app/Models/User.php`

**Consumes:** `BusStore::admin()`, `BusStore::setAdmin()`.

**Produces:** auth provider driver `bus-store`. Users are `Illuminate\Auth\GenericUser` with `id` and `email` set to the admin email.

- [ ] Rewrite `AdminAuthTest` setup to use `BusStore::setAdmin` and `signedInAdmin()`. Add:
  - login fails cleanly when no admin exists
  - a signed in browser is signed out once the admin email is replaced
  - `bus:make-admin` with a new email replaces the old admin
  Run, watch them fail.
- [ ] Implement the provider, register it with `Auth::provider('bus-store', ...)`, point `config/auth.php` at it and drop the `passwords` broker. Remove `remember: true`. `MakeAdmin` calls `setAdmin($email, Hash::make($password))`.
- [ ] Delete `User`. Both suites green. Commit: `refactor: keep the admin login in BusStore`

### Task 5: remove the database

**Files:**
- Delete: `database/migrations/*`, `database/factories/UserFactory.php`, `database/seeders/DatabaseSeeder.php`
- Modify: `tests/Pest.php` (drop `RefreshDatabase`), `phpunit.xml` (drop the `DB_*` lines), `.env.example`, `composer.json` (drop `database/` autoload entries and the `migrate` script steps), `README.md`

- [ ] Add a test that fails if anything opens a database connection during a device poll and an admin page load. Run it with `RefreshDatabase` removed.
- [ ] `.env.example`: `SESSION_DRIVER=cookie`, `SESSION_LIFETIME=43200`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`.
- [ ] README: replace the `migrate` lines, add the Laravel Cloud bucket steps from the spec, state that the bucket must be private.
- [ ] Both suites green. Run the app locally with the new drivers: make an admin, sign in, save a stop, poll the device endpoint, and confirm `storage/app/private/bus.json` holds what was saved.
- [ ] Commit: `chore: remove the database`
