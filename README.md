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
- Now includes usage analytics! (track opens!)  
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
- 👾 **Email Tracking**: Track when participants open their assignment emails.
- 🔒 **Smart Constraints**: Prevents same-household matchups and can exclude repeat recipients from the previous 0–5 calendar years.
- 🧪 **Admin Dashboard**:
  - Test and commit pairings.
  - **Manual Participant Management**: Add or delete individual participants.
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
```

### 3. Build and Run
```bash
docker-compose up -d --build
```

### Upgrading an existing database

For an existing Docker database volume, apply the migration before starting the updated app. From the project directory, run:

```bash
docker compose up -d db
docker compose exec -T db sh -c 'mariadb -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < db/migrate-draw-settings.sql
docker compose up -d --build
```

The migration preserves your participants and pairings and can be run again safely. Fresh volumes get the schema during initialization.

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
- **Resend Notifications**: Use this if someone loses their original assignment email.
- **Wishlist Status**: Monitor progress and see which elves still need to set their wishes.
- **Force Run**: Test the full automation at any time with the `-force` flag:
  ```bash
  docker exec -it secretsanta_app php /var/www/html/secret_santa.php -force
  ```

---

### ☕ Buy Me a Coffee
If this project saved your holiday sanity or prevented at least one family meltdown…

You can buy me a coffee on Venmo:

👉 @wizworks

Cheers, and happy gifting! 🎄😄
