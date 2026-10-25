# OSINT Hub v4

Single-file PHP OSINT + tracking hub (shared-host safe) plus a native Android client.

## Layout

    tracking.php     the whole server: 45+ OSINT modules, tracking, link
                     engine, job queue, alerts, REST /v1, dashboard
    .htaccess        pretty paths + hardening
    android/         Kotlin + Compose app (share target, GPS beacon, alerts)

## Server setup

1. Upload `tracking.php`. Works on InfinityFree and any shared host.
2. Edit the CONFIG block at the top:

       'db_dsn'  => 'mysql:host=sqlXXX.example.com;dbname=your_db;charset=utf8mb4',
       'db_user' => 'your_user',
       'db_pass' => 'your_pass',
       'master_password' => 'change-me-now',

   No MySQL? For local dev use:
       'db_dsn' => 'sqlite:' . __DIR__ . '/tracker.sqlite',

3. Open `https://yoursite/tracking.php?t=dash`, sign in, change the password
   immediately (Logs tab).
4. Open `?t=selftest&json=1` and fix anything marked false.
5. Shared hosts have no cron. Point cron-job.org at:

       https://yoursite/tracking.php?t=cron&secret=YOUR_MASTER_PASSWORD&i=1

   every 6 hours. Drives the watchlist, job queue and alert delivery.

### Optional keys (all free tiers)

`hibp_key` `github_token` `numverify_key` `abstract_phone_key` `ipqs_key`
`hlr_key` `abuseipdb_key`

### Alert channels

Set credentials in the `messaging` config block and add the name to
`messaging.chain`:

    ntfy telegram discord slack gotify pushover matrix teams gchat
    smtp http_mail twilio_sms twilio_whatsapp webhook

`ntfy` is the fastest: pick a topic, install the ntfy app, done.

## Public endpoints (no login, rate limited)

    ?t=email&e=a@b.com      ?t=phone&n=+15551234567    ?t=ip&ip=8.8.8.8
    ?t=user&u=torvalds      ?t=whois&d=example.com     ?t=dns&d=example.com
    ?t=subs&d=example.com   ?t=tls&d=example.com       ?t=headers&d=example.com
    ?t=pass&p=hunter2       ?t=addr&a=10+Downing+St    ?t=rev&lat=&lon=
    ?t=profile&e=&n=&d=&a=  ?t=tools&op=hash&v=        ?t=l&s=SLUG
    ?t=px&e=SLUG            ?t=qr&d=...                ?t=v1&r=email&e=...

Add `&json=1` for JSON, `&deep=1` for exhaustive mode, `&log=1` to also write
the lookup into the event log.

## Android app

    cd android
    ./gradlew assembleDebug
    adb install -r app/build/outputs/apk/debug/app-debug.apk

Settings -> Hub URL -> Test. Optionally paste an API key (Keys tab).

What the app adds beyond the browser:

* foreground service posting raw fused GPS to `?t=beacon` on an interval, so
  a phone in a pocket becomes a live dot on the same map
* share target: share text from any app and it auto-detects
  email / phone / IP / domain / handle and runs the right lookup
* WorkManager job polling alerts every 30 min with real notifications

## Security

* Change `master_password` on first login (bcrypt-hashed).
* API keys are scoped read/write/admin with optional hourly quotas.
* `retention_days` (default 180) prunes events automatically.
* Master-login kill switches: tracking, OSINT, geo, camera, mic, clipboard,
  sensors, WebRTC, heatmap, port scan, DNSBL, Tor check.