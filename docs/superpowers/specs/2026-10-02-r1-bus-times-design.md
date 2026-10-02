# R1 Bus Times: Design

Date: 2026-10-02
Status: awaiting review

## Purpose

Ross wants to glance at a Rabbit R1 and see when the next buses are due at
the stop he is about to use. The stop differs between the morning and the
afternoon. The R1 loads a small web page (an R1 creation) served by a Laravel
app, which fetches live predictions from the TfL Unified API.

Success: during the morning or afternoon window on a weekday, opening the
creation shows the correct stop and its next buses within a couple of seconds,
and the times stay current without touching the device.

## Decisions made

| Topic | Decision |
|-------|----------|
| Display | Rabbit R1 creation, 240 x 282 px web page |
| Stop selection | Fixed schedule, one stop per slot (morning, afternoon), edited in an admin page |
| Routes shown | All routes serving the stop, soonest first |
| Days | Monday to Friday only |
| Windows | 06:30-09:00 and 14:30-15:30, Europe/London |
| Fetch model | On demand when the R1 asks, cached 20 seconds. No background polling |
| Outside windows | Show next window time plus a "Check now" button |
| Hosting | Laravel Cloud (assumed from a garbled voice reply; confirm at review) |
| Users | Single admin user |

## Architecture

Latest stable Laravel, Postgres, Livewire for the admin page, Pest for tests.
Deployed to Laravel Cloud. No scheduler and no queue worker are needed.

The TfL app key lives in the environment (`TFL_APP_KEY`) and is only used
server side. The R1 never talks to TfL directly.

### Units

**`TflClient`**: the only code that talks to TfL.
- `searchStops(string $query): array`: places matching a name. TfL returns
  hubs and stop groups mixed with individual stops, so these are not yet
  savable.
- `stopsAt(string $id): array`: the individual bus stops under a place, each
  with NaPTAN ID, common name, stop letter and "towards" text.
- `arrivals(string $naptanId): array`: raw predictions for one stop.
- 5 second timeout, one retry. Throws `TflUnavailable` on failure.

**`WindowResolver`**: pure time logic, no I/O.
- `current(CarbonInterface $now): ?Slot`: `Slot::Morning`, `Slot::Afternoon`
  or null. Start is inclusive, end is exclusive (09:00:00 is outside).
- `upcoming(CarbonInterface $now): Slot`: the slot whose window starts next.
- `nextStart(CarbonInterface $now): CarbonInterface`: when that window opens,
  skipping weekends.
- Window times and active weekdays come from `config/bus.php`.

**`StopSchedule` model**: one row per slot.
- Columns: `slot` (unique: `morning` or `afternoon`), `naptan_id`, `name`,
  `stop_letter` (nullable), `towards` (nullable), timestamps.

**`ArrivalsService`**: joins the above.
- `forSlot(Slot $slot): ArrivalsResult`: loads the slot's stop, calls
  `TflClient::arrivals`, maps to `route`, `destination`, `minutes`, sorts by
  expected arrival ascending, keeps the first 10, caches 20 seconds per
  NaPTAN ID. Minutes are recomputed on every read so a stale result still
  counts down, and buses that have gone are dropped.
- Returns null when the slot has no stop; throws `TflUnavailable` when TfL
  fails and there is no usable last good result.
- Keeps the last good result for 15 minutes for the stale fallback.
- `ArrivalsResult` carries the stop, the arrivals, `fetchedAt` and a `stale`
  flag.

**`DeviceToken`**: a long random token stored in a one-row `settings` table.
The R1 URL contains it. Regenerating it in admin invalidates the old URL.

### Routes

| Route | Purpose |
|-------|---------|
| `GET /r1/{token}` | R1 creation page (Blade, vanilla JS, no build step) |
| `GET /r1/{token}/arrivals` | JSON for the current slot, or the outside-window state |
| `GET /r1/{token}/arrivals?check=1` | JSON for the upcoming slot's stop, one-off |
| `GET /admin` | Livewire admin page, behind login |
| `/login`, `/logout` | Standard session auth |

A wrong token returns 404. Device routes are rate limited to 30 requests per
minute.

### JSON shape

```json
{
  "state": "live",
  "stop": {"name": "Angel Station", "letter": "D", "towards": "Islington"},
  "arrivals": [{"route": "73", "destination": "Stoke Newington", "minutes": 3}],
  "fetched_at": "2026-10-02T07:41:10+01:00",
  "fetched_label": "07:41",
  "stale": false,
  "stale_minutes": 0,
  "next_window": null,
  "next_window_label": null
}
```

`state` is one of `live`, `outside_window`, `no_stop`, `unavailable`.
For `outside_window`, `arrivals` is empty and `next_window` holds the next
start time, unless `check=1` was sent, in which case arrivals for the upcoming
slot are returned with `state: "live"`.

The `*_label` fields are formatted on the server in London time, so the screen
is right even if the R1 clock or timezone is not.

## Behaviour

### Inside a window

1. R1 opens `/r1/{token}`.
2. Page requests the arrivals JSON immediately and then every 20 seconds.
3. Screen shows the stop name and letter, about 5 arrivals at a time (up to
   10 by scrolling), and the last updated time.

### Outside a window

1. JSON returns `outside_window` with `next_window`. No TfL call is made.
2. Screen shows "Next check 14:30" (or "Back Monday 06:30") and a
   **Check now** button.
3. Pressing it requests `?check=1` once and shows buses for the upcoming
   slot's stop: at 10:00 on a weekday that is the afternoon stop; after 15:30
   and at weekends it is the morning stop.
4. No auto-refresh in this mode. Pressing again fetches again.

### R1 screen

- Viewport 240 x 282. Large route number, destination, minutes ("due" under
  one minute, otherwise "3 min").
- Scroll wheel scrolls the list. Side button forces a refresh inside a window
  and triggers Check now outside one. The exact R1 creation event names are to
  be confirmed against the Rabbit creations SDK during planning.

### Admin page

- Two cards: Morning stop and Afternoon stop. Each shows the current stop and
  a search box. Results list places; choosing a place that holds several
  stops lists them with letter and "towards" so the correct side of the road
  can be picked. Choosing a stop saves it.
- Shows the R1 URL with a Regenerate button.
- A change takes effect on the next R1 request.
- The single admin user is created with an artisan command; there is no
  public registration.

## Error handling

| Case | Result on R1 |
|------|--------------|
| TfL fails, last good result under 15 minutes old | Show it, marked "stale, N min old" |
| TfL fails, nothing cached | `unavailable`: "TfL unavailable, retrying" |
| No stop saved for the slot | `no_stop`: "No stop set. Use admin" |
| TfL returns no predictions | "No buses due" |
| Wrong token | 404 |

Admin stop search failure shows an inline error and leaves the saved stop
unchanged.

## Testing

- `WindowResolver`: 06:29, 06:30, 08:59, 09:00, 14:30, 15:29, 15:30,
  Saturday, Sunday, Friday afternoon rolling to Monday, and both clock change
  dates.
- `TflClient`: faked HTTP for search, arrivals, timeout and 500 responses.
- `ArrivalsService`: sorting, minute rounding, 20 second cache, stale fallback.
- Feature: device endpoints in and out of a window, `check=1`, wrong token,
  no stop set; admin login, save stop, regenerate token.
- No test calls the real TfL API.

## Out of scope

Alerts and notifications, arrival history, multiple users, weekend or holiday
rules, route filtering, background polling.
