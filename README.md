# 🎄 Secret Santa E-mail Bot (Docker Version)

Welcome to the **Secret Santa E-mail Bot**, the festive automation gremlin that handles all the chaos of drawing names without the need for paper scraps, family arguments, or “who did I get last year again?” debates.

This bot:

- Draws randomized Secret Santa pairings  
- Prevents same-household assignments  
- Now handles Wish Lists!
- Can exclude repeat pairings from up to five previous calendar years
- Sends beautiful Christmas-themed HTML emails  
- Sends the master list privately to the admin  
- Runs automatically every Thanksgiving Day at noon  
- Can also be triggered manually with `-force`
- Includes panel visit counts and first/last visit times.
- Now is packaged to run as a Docker container

In other words:

> **It’s the holiday elf you always wanted — one that doesn’t eat your cookies or unionize.**

---

## 🎁 Features

- 🐳 **Dockerized**: Easy deployment with `docker-compose`.
- 🎅 **Automated Drawing**: Runs automatically on Thanksgiving Day.
- 💰 **Budget Limit**: Easily set a budget limit for the event.
- 💌 **Festive Emails**: Sends individual, Christmas-themed HTML emails.
- ✨ **Wish Lists**: Participants can view their recipient's wishes and set their own.
- 🔔 **Real-time Notifications**: Notifies givers when their recipient updates their wishlist.
- 👾 **Panel Visits**: Count visits to participants' wishlist panels without email tracking images.
- 🔒 **Smart Constraints**: Prevents same-household matchups and can exclude repeat recipients from the previous 0–5 calendar years.
- 🧪 **Admin Dashboard**:
  - Test and commit pairings.
  - **Manual Participant Management**: Add participants, edit their names and email addresses across all years, or delete them with a history-loss warning.
  - **Resend Emails**: Resend assignment notifications to individual participants.
  - **Wishlist Status**: Track who has (and hasn't) filled out their wishlist.
  - **Security**: Hardened with CSRF protection and robust input validation.
- 🗄 **Persistent Storage**: Uses MySQL for year-over-year tracking.
- 💫 **CSV Import**: Import participants and past pairings in bulk.

---

## 🛠 Installation & Setup (Docker)

### 1. Clone the project files
```bash
git clone https://github.com/K1WIZ/Secret-Santa-In-A-Box.git
cd Secret-Santa-In-A-Box
```

### 2. Configure Environment Variables
Modify `docker-compose.yml` to set your environment:

```yaml
      APP_BASE_URL: "http://yourdomain.com:8080"
      ADMIN_KEY: "choose_a_strong_password" # Secures your admin pages
      BUDGET_LIMIT: "$50" # Included in all emails
      NOTE_TO_USERS: "" # Optional instructions in participant pairing emails
      DB_HOST: "db"
      DB_NAME: "secret_santa"
      DB_USER: "secretsanta"
      DB_PASSWORD: "changeme"
      APP_TZ: "America/New_York"
      SMTP_HOST: "smtp.gmail.com"
      SMTP_PORT: "587"
      SMTP_USER: "yourgmail@gmail.com"
      SMTP_PASS: "your_app_password" # ⚠️ Use a Gmail App Password!
      SMTP_FROM_EMAIL: "santa@example.com"
      SMTP_FROM_NAME: "Secret Santa Bot"
      SEND_PAIRINGS: "false"
```

Set `SEND_PAIRINGS: "true"` to email the master pairing list to `SMTP_FROM_EMAIL` after a command-line draw. It defaults to `false` when omitted; only the value `true` enables it (case-insensitive). Participant assignment emails are sent regardless of this setting. Recreate the app container after changing the flag: `docker compose up -d app`.

Set `NOTE_TO_USERS` to append `(Note: your instructions)` to participant pairing emails, including dashboard resends, in both HTML and plain text. For example, `NOTE_TO_USERS: "Connect to the family VPN to access your wishlist."`. It defaults to empty; omitted, empty, or whitespace-only values add nothing. Recreate the app container after changing the note: `docker compose up -d app`.

### 3. Build and Run
```bash
docker-compose up -d --build
```

Setup assumes a new database volume. Docker creates the database named by `MYSQL_DATABASE` and loads the complete current schema from `db/init-db.sql` once. The app expects that schema and does not run upgrades. Existing installations should follow [migrate/README.md](migrate/README.md) before starting the updated app.

### Web access restrictions (Docker and Podman)

Apache uses `apache/web-access.conf` to allow only these HTTP paths:

- `/wishes.php` — participant portal; requires the participant's wishlist key.
- `/wishes.js` — JavaScript used by the portal.
- `/admin_secret_santa.php` — admin dashboard; requires `ADMIN_KEY`.
- `/import_users.php` — CSV import; requires `ADMIN_KEY`.

All other paths are denied, including `/`, configuration files, dependencies, maintenance scripts, and any new files until explicitly allowed. Directory listings and extra path segments after script names are disabled. PHP can still load its configuration and dependencies internally.

`secret_santa.php` and `add_test_users.php` also reject non-CLI execution before loading dependencies or connecting to the database. Run them with `podman exec secretsanta_app php /var/www/html/secret_santa.php` (add `-force` for a manual draw) or `podman exec secretsanta_app php /var/www/html/add_test_users.php` for development seeding.

Rebuild and recreate the app container after changing the Apache configuration or deploying this restriction: `podman compose up -d --build app` (or `docker compose up -d --build app`). Restarting an old container does not install the new image configuration. Keep the existing key checks and use HTTPS; the allowlist controls which pages can be reached, while each page still authenticates its users.

Wishlists grow as you type: there is always an empty row at the bottom, and saving removes blank entries. Each item can contain up to 255 characters. The recipient's name and wishes start hidden; use **Show** and **Hide** for privacy.

Every save updates the wishlist. Notification emails are limited per participant: the first save with an assigned giver can notify them, and later saves notify only when more than one hour has elapsed since the previous notification attempt. Saves during the cooldown do not change its timestamp. The timestamp is reserved before sending, so simultaneous saves cannot send duplicate notifications; failed email attempts also start the cooldown. Emails contain a link, not the wishlist items. No delayed email is queued; another save after the hour can send a new notification. Existing databases need the [cooldown migration](migrate/README.md#wishlist-notification-cooldown) before using this feature.

Each successful GET to `wishes.php` with a valid participant key counts one panel visit for the current calendar year in `APP_TZ`, including refreshes. Wishlist saves, HEAD requests, and invalid or missing keys do not count. The dashboard shows first visit, last visit, and visit count for the selected year; redraws preserve these counts. Visit timestamps use the database clock. This counts page loads, not unique people: forwarded links or automated link scanners can also add visits. No tracking images, IP addresses, or user agents are recorded.

### 4. Add Participants
*   **Bulk Import**: Go to `http://yourdomain:8080/import_users.php?key=your_admin_key` to upload a CSV.
*   **Manual Addition**: Use the "Add Single Participant" form on the Admin Dashboard.
*   **Households**: Give unrelated participants different family unit numbers. People in the same family unit cannot draw each other.

### Import past pairings

On the CSV Import page, add any missing participants first, then upload a second CSV through **Import Past Pairings**.

```csv
year,giver_email,receiver_email
2024,alice@example.com,bob@example.com
2025,bob@example.com,alice@example.com
```

Use one row per past assignment, with the email addresses from the participant list. You can import partial records across multiple years. Identical assignments are skipped. Errors are reported by row and cancel the upload.

### 5. Automation (Cron)
Add a cron job to your host to trigger the drawing:
```bash
0 12 * 11 *  /usr/bin/docker exec -it secretsanta_app php /var/www/html/secret_santa.php
```

---

## 🧪 Admin & Testing

Access the Admin Dashboard at:
`http://yourdomain:8080/admin_secret_santa.php?key=your_admin_key`

**Tools available:**
- **Generate Pairings**: Preview or commit assignments for the target year. Choose **0–5** years of history to exclude. In 2026, choosing **2** checks 2024 and 2025. Use **Save Default** to set the value used on future dashboard visits and scheduled draws.
- **Manage Participation**: Choose a year, select **View Roster**, and use each person’s **In / Out** button. Participants start In and keep their most recent status in later years. Changes take effect on the next draw or redraw. Existing assignments stay valid until a redraw replaces them.
- **Edit Participants**: Expand **Edit** in a participant’s Manage column to change their first name, surname, or email. Changes apply across all years, including when editing from a past year’s roster. Their identity, historical pairings, participation, household, wishlist links, and panel visit statistics are preserved. Future emails use the updated details; saving does not send email. Use **Resend** separately if needed. Past-pairing CSV imports must use the participant’s current email address.
- **Delete Participants**: Deletion permanently removes the participant, their pairings from every year, their participation records, and their panel visit statistics. This breaks historical continuity and removes those pairings from repeat-recipient checks. Prefer **Edit** for changed details or **Out** to deactivate someone while preserving history. A warning appears above the roster and a confirmation names the person before deletion.
- **Resend Notifications**: Use this if someone loses their original assignment email.
- **Wishlist Status**: Monitor progress and see which elves still need to set their wishes.
- **Force Run**: Test the full automation at any time with the `-force` flag:
  ```bash
  docker exec -it secretsanta_app php /var/www/html/secret_santa.php -force
  ```

---

## Project structure

```text
.
├── Dockerfile                   # PHP/Apache image and Composer dependencies
├── docker-compose.yml           # App, MariaDB, and persistent storage
├── apache/
│   └── web-access.conf           # Exact HTTP entry-point allowlist
├── db/
│   └── init-db.sql              # Complete schema for a fresh database
├── migrate/                     # Manual upgrade for existing installations only
│   ├── README.md                # Upgrade command and data changes
│   ├── original-to-current.sql # Direct upgrade from the original schema
│   └── wishlist-notification-cooldown.sql # Add cooldown to an existing JSON wishlist schema
├── src/                         # Apache document root
│   ├── config.php              # Database, SMTP, and app settings from environment
│   ├── admin_secret_santa.php  # Roster, draw previews/commits, and email tools
│   ├── import_users.php        # Participant and past-pairing CSV uploads
│   ├── secret_santa.php        # Scheduled/forced draw and assignment emails
│   ├── wishes.php              # Participant wishlist portal and update emails
│   ├── wishes.js               # Expandable wish rows and recipient privacy toggle
│   ├── santa_database.php      # Shared PDO connection settings
│   ├── santa_draw.php          # Participation, history exclusions, and matching
│   ├── santa_history_import.php # Validate and import past-pairing CSV records
│   ├── santa_mail.php          # Shared SMTP mailer configuration
│   ├── santa_wishlist.php      # Normalize, read, and save JSON wishlists
│   ├── add_test_users.php      # Optional development participant seeder
│   ├── composer.json           # PHP dependency requirements
│   ├── composer.lock           # Pinned dependency versions
│   └── vendor/                 # Composer dependencies
├── tests/
│   ├── database.php            # Fresh schema, migrations, and database workflows
│   ├── web-access.php          # Isolated HTTP allowlist and CLI guard checks
│   └── mail.php                # SMTP configuration and pairing-note safety checks
├── participants.csv            # Example participant import
├── README.md                   # Setup and usage guide
└── LICENSE                     # Project license
```

To run the SMTP and pairing-note safety checks in the running app container:

```bash
docker compose exec -T app php /dev/stdin /var/www/html < tests/mail.php
```

`tests/database.php` requires PHP with PDO MySQL and mbstring, plus a disposable MariaDB 11 server. Set `DB_HOST`, `DB_PORT`, `DB_USER`, and `DB_PASSWORD` for a user allowed to create/drop test databases, then run `php tests/database.php`. It creates randomly named databases, removes them afterward, and never uses `DB_NAME`.

`tests/web-access.php` runs inside a temporary app container with the Apache allowlist installed, no network access (`--network none`), and `DB_HOST=127.0.0.1`. Run `docker exec -i CONTAINER_NAME php < tests/web-access.php` (or substitute `podman`). It checks blocked HTTP paths using GET, HEAD, and POST, the allowed pages and their private includes, and the CLI guards through a separate PHP web server. It expects database connections to fail, so do not run this check against the production container.
