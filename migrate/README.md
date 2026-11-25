# Upgrading an existing database

New installations use `db/init-db.sql` and skip this directory. `original-to-current.sql` upgrades a database from the initial commit directly to the current schema in one run. It also accepts databases with earlier upgrades already applied. It targets MariaDB 11, as configured in Docker Compose.

Stop the app and back up the database before upgrading. From the repository root:

```bash
docker compose stop app
docker compose up -d db
docker compose exec -T db sh -c 'mariadb-dump --single-transaction -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > secret-santa-backup.sql
docker compose exec -T db sh -c 'mariadb -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < migrate/original-to-current.sql
```

Confirm the migration completed successfully before restarting the app:

```bash
docker compose up -d --build app
```

No intermediate scripts are needed. The migration can be rerun safely, including after the complete upgrade. Do not apply `db/init-db.sql` to an existing database. MariaDB schema changes commit independently; restore the backup if you need to undo an upgrade.

The migration adds draw settings, participation records, panel visit counters, and the wishlist notification timestamp; converts three-item wishlists to JSON; defaults empty lists to `[]`; and removes `wish_item1/2/3` and `email_opens`. Email opens are not counted as panel visits.

Participants, wishlist keys, pairings, participation, settings, and panel visits are preserved. Existing JSON wishlists take precedence over the three old columns, including intentionally empty `[]` lists. Retired email-open statistics are permanently removed; the backup retains them. The retired `open.php` tracking endpoint has been removed, so old tracking-image links return 404 and record nothing.
