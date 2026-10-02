# R1 bus times

Live TfL bus arrivals on a Rabbit R1, for the stops you wait at on the way out and the way back.

The R1 loads one small web page, 240 by 282 pixels, served by this Laravel app. Inside a period the page asks the app for arrivals every 20 seconds. The app asks TfL, caches the answer, and sends back the next buses for the right stop.

There is no database. The stops, the R1 link token and the admin login live in one JSON file, `bus.json`, on the default filesystem disk.

## How it behaves

There are two periods on weekdays. Morning runs 06:30 to 09:00 and afternoon runs 14:30 to 15:30, London time. Each period has an outward stop and an inward stop, so four stops in total. You can leave any of them unset.

Inside a period the R1 shows the outward stop first.

- Tap the header or press the side button to flip between outward and inward.
- Turn the scroll wheel to move through the list.
- The line under the stop name says which direction is showing, for example "Outward, towards Blackheath".
- The number in the top right counts down the seconds to the next automatic refresh.

Outside a period the R1 shows when the next one starts and does not call TfL. The page stops asking the app too, and starts again by the R1's own clock when the next period begins, so a hibernating host can sleep with the R1 left open.

- Press "Check now" or the side button to fetch the upcoming period's buses once.
- Tap the header to check the other direction.

When a period ends, the screen goes back to outward.

If TfL stops answering, the app serves the last good result for up to 15 minutes and the footer says how old it is. If the R1 loses its connection for a minute, the bus times come off the screen. Old bus times are worse than none.

## Run it locally

You need PHP 8.3 or newer and Composer. Node is only needed for the page script tests.

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan bus:make-admin you@example.com
php artisan serve
```

Locally `bus.json` is written to `storage/app/private/`. Delete it to start again.

Then open `http://localhost:8000/login`, sign in, and search for a stop in each Outward and Inward box. TfL lists every stop at a place with its letter and where it heads, so pick the one on your side of the road.

`TFL_APP_KEY` in `.env` is optional locally. TfL rate limits requests without a key, so set one in production. Keys are free from the [TfL API portal](https://api-portal.tfl.gov.uk/).

## Get it on the R1

The R1 installs a creation by scanning a QR code that points at a URL. The R1 has to reach that URL over the internet, so `localhost` will not work.

### 1. Put the app on a public HTTPS address

Deploy it to any host that runs Laravel and can give it a disk that survives a deploy. On Laravel Cloud that disk is an object storage bucket, because the app's own filesystem is wiped on every deploy.

1. Create a Laravel Object Storage bucket and attach it to the environment. Make it **private** and mark it as the default disk. `bus.json` holds the R1 token and the admin password hash, and a public bucket would serve both at a public URL. Visibility is fixed when the bucket is created.
2. Set these environment variables:

   ```
   APP_URL=https://your-host
   TFL_APP_KEY=your-key
   SESSION_DRIVER=cookie
   SESSION_LIFETIME=43200
   CACHE_STORE=file
   QUEUE_CONNECTION=sync
   ```

3. Remove `php artisan migrate` from the deploy commands if it is there. There is nothing to migrate.
4. Deploy, then run this once from the environment's command runner:

   ```sh
   php artisan bus:make-admin you@example.com
   ```

The app needs no database, no scheduler and no queue worker. Do not run more than one instance. The cache and rate limits are per instance.

If the host has no terminal to prompt on, pass the password inline with `--password='...'`. It will show in that runner's command history, so prefer the prompt when you have one.

To try it before deploying, tunnel the local server with `cloudflared tunnel --url http://localhost:8000` or `ngrok http 8000`. The R1 only works while the tunnel is up.

### 2. Copy the R1 link

Sign in at `/login` on the public address and set your stops. The "R1 link" card at the bottom of the admin page shows a URL like `https://your-host/r1/<token>`. Copy it.

### 3. Make the QR code

Rabbit publishes a QR generator in the [creations SDK](https://github.com/rabbit-hmi-oss/creations-sdk). Clone the repo and open `qr/final/index_fixed.html` in a browser. Fill in the form:

| Field | Value |
| --- | --- |
| Title | Bus times |
| URL | the R1 link from step 2 |
| Description | anything you like |
| Icon URL | optional, any public image |
| Theme color | `#ff6b00` matches the page |

The QR code holds those five fields as JSON. Nothing else is involved.

### 4. Scan it

Point the R1 camera at the QR code. The creation appears on the device. Open it and the bus times load.

### Keep the link private

The token in the R1 link is the only thing protecting the page. Anyone with the link or the QR code can see your bus times. If it leaks, press "Regenerate" on the admin page. The old link stops working at once, so make a new QR code and scan it again.

## Change the times

Periods, weekdays and limits live in `config/bus.php`.

| Setting | Default |
| --- | --- |
| `windows` | morning 06:30 to 09:00, afternoon 14:30 to 15:30 |
| `weekdays` | Monday to Friday |
| `cache_seconds` | 20, also how often the R1 polls |
| `stale_minutes` | 15 |
| `max_arrivals` | 10 |

## Tests

```sh
./vendor/bin/pest   # the Laravel app
npm test            # the R1 page script, Node only, no install needed
```

Run both. The Pest suite points the default database connection at nothing, so any code that reaches for a database fails. The page script tests load the script out of `resources/views/r1.blade.php` and run it against a fake DOM, so a change to the R1 screen can break them while Pest stays green.
