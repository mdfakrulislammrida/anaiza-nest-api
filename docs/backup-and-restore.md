# Backups and restoring

## What is backed up

Every night at 02:30 (Dhaka time) the server makes one zip in `storage/app/backups/`:

| In the zip | What it is |
| --- | --- |
| `database.sqlite` | A consistent copy of the whole database: orders, customers, products, settings. Taken with SQLite's `VACUUM INTO`, which is safe while customers are ordering. |
| `files/` | Everything uploaded through the admin (`storage/app/public`): product pictures, banners, the media library. |
| `manifest.json` | When it was made and how many orders, customers and products it holds. |

**Not in the zip: `.env`.** It holds the keys that unlock saved secrets (payment, Meta, TikTok and social-login credentials are stored encrypted with `APP_KEY`). Keep your own private copy of `.env`. A database restored without the matching `APP_KEY` loses those saved secrets, and they would need to be typed in again.

Kept: the last 7 nightly backups, the last 4 weekly ones (made on Sundays), and the last 3 made by hand with **Back up now**.

A backup the server cannot finish (disk nearly full, zip not available) is written to **Settings > System health** and emailed to the shop's alert address.

## Getting a copy off the server

A backup that lives only on the server it protects is lost with the server.

1. **By hand:** Admin > Settings > System health > Backups on this server > Download. Do this weekly and keep the file somewhere that is not the host (your computer, a cloud drive).
2. **Automatic copy over SFTP** (off by default): see "Off-server copy" below.

## Making a backup by hand

From the admin: System health > **Back up now**. From the server:

```bash
cd /path/to/anaiza-nest-api
php artisan backup:run --kind=manual
```

## Restoring

Do this on a copy first if you can. Restoring replaces the shop's current data with the data in the backup: orders placed since the backup are not in it.

1. Take a fresh backup of the current state (so nothing is lost if you restored the wrong one).
2. Put the shop in maintenance mode:
   ```bash
   php artisan down
   ```
3. Download the backup zip you want and upload it to the server, outside any public folder, for example to `/home/USER/restore/`. Unzip it there:
   ```bash
   cd /home/USER/restore
   unzip anaiza-daily-20261012-023000.zip -d unpacked
   ```
4. Check the database before using it (it should print `ok`):
   ```bash
   php -r '$p = new PDO("sqlite:unpacked/database.sqlite"); echo $p->query("PRAGMA integrity_check")->fetchColumn(), PHP_EOL;'
   ```
5. Replace the live database. Use the path in `DB_DATABASE` in `.env` (shown here as `database/database.sqlite`), and remove the old WAL files so SQLite does not mix them with the restored file:
   ```bash
   cd /path/to/anaiza-nest-api
   cp database/database.sqlite database/database.sqlite.before-restore
   cp /home/USER/restore/unpacked/database.sqlite database/database.sqlite
   rm -f database/database.sqlite-wal database/database.sqlite-shm
   ```
6. Restore the pictures (only needed if they were lost):
   ```bash
   cp -a /home/USER/restore/unpacked/files/. storage/app/public/
   ```
7. Bring the shop back and check it:
   ```bash
   php artisan migrate --force
   php artisan optimize:clear
   php artisan up
   ```
   Then open Settings > System health (everything should be fine) and Orders (the newest order should be the one the backup was taken after).

`php artisan migrate --force` in step 7 matters when the backup is older than the code on the server: it brings the restored database up to date with the code.

## Off-server copy (optional, off by default)

The nightly job can also send each backup to another server over SFTP. Nothing is sent unless this is switched on.

1. On a computer with Composer, in the API project: `composer require league/flysystem-sftp-v3`, then upload the updated `vendor` folder and `composer.lock`/`composer.json` with the next deploy.
2. In the server `.env`:
   ```
   BACKUP_SFTP_ENABLED=true
   BACKUP_SFTP_HOST=backup.example.com
   BACKUP_SFTP_PORT=22
   BACKUP_SFTP_USERNAME=...
   BACKUP_SFTP_PASSWORD=...        # or BACKUP_SFTP_PRIVATE_KEY=/path/to/key
   BACKUP_SFTP_ROOT=/anaiza-nest-backups
   ```
3. Run `php artisan backup:run --kind=manual`. The line it prints ends with `copied to HOST:/folder/name.zip` when it worked. If the other server cannot be reached the backup on this server is still made, and the failure is shown on System health.

The off-server copy holds the same files as the local one, so protect that server the same way: it holds customer names and addresses.

## Testing that a backup is good

The nightly job already opens the database copy and runs SQLite's integrity check before it keeps the zip, and reads the zip back. Once a quarter, do steps 3 to 4 above with the newest backup on your own computer to see it is real.
