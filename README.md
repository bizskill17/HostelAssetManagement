# Hostel Asset Management

One organisation, many hostels. Each hostel keeps a **count** of every item (beds, mattresses, fans…), with no tag numbers on individual items. Every change is recorded, so you can open any item's ledger and see its history from opening stock to today.

It runs on Hostinger shared hosting (PHP 8 + MySQL). Every push to `main` deploys automatically through GitHub Actions once the secrets are set.

## What it does

| Screen | What you do there |
|---|---|
| **Dashboard** | Counts, transfers waiting for your approval, items at repair, warranties about to expire |
| **Inventory** | Hostel, item, unit, category, opening, in, issued, damaged and current count. Click an item to see its full history in a pop-up |
| **Item ledger** | Every entry for one item (opening → received → transfers → repairs → damage) with a running balance |
| **Stock in** | Enter **opening stock** (once per item per hostel) and **items received** |
| **Issue register** | Every issue: **transfer to another hostel**, **send for repair**, or **damaged** |
| **Approvals** | Transfers wait here for the receiving hostel. Stock moves only when they approve; they can also reject with a reason |
| **Repairs** | Items out for repair, what's overdue, and **Record return** (repaired → back in stock; not repairable → counted as damaged) |
| **Damaged** | Everything written off, with where it was sent (optional) |
| **Warranties** | Warranty / AMC number, supplier, quantity, expiry. Shows what expires in 30 / 90 days |
| **Masters** (Admin) | Hostels, Items (with unit and category), Categories, Damage destinations, Users |
| **Settings** (Admin) | Telegram notifications |

Every list has counts on top, search and filters, and **Download CSV** (which downloads exactly what the filters show).

### How the counts work
- **In** = received + transfers in + returned from repair
- **Issued** = transfers out + sent for repair
- **Current inventory** = opening + in − issued − damaged
- A transfer waiting for approval doesn't change stock yet, but it does reserve the quantity so it can't be issued twice.

## Roles

| Role | Can do |
|---|---|
| **Admin** | Sees every hostel. The only role that can change masters (hostels, items, categories, users) and settings, and the only role that can delete anything (issues, stock entries, warranties, masters) |
| **Warden** | Linked to one hostel and sees only that hostel's data. Adds stock, issues items, updates the received quantity of transfers coming in, records repair returns, adds and edits warranties. Cannot delete anything |

## Notifications
Notifications go to **Telegram only**. Set them up under **Settings**:
- Create a bot with @BotFather, add it to your group, then paste the bot token and the **main group** chat ID (it starts with `-100`). The main group gets every message.
- Optional: give a hostel its own group in **Masters → Hostels → Telegram group ID**. That group gets only that hostel's messages.
- Tick which messages to send: stock received, transfer sent / received / cancelled, damage reported / approved / rejected, repair sent / returned, and the daily messages (repairs overdue, warranties expiring at 30, 15, 7, 3, 1 and 0 days, morning summary).
- Daily messages go out once a day after the time set in Settings: on the first app visit after that time, or by the cron job shown in Settings (Hostinger hPanel → Advanced → Cron Jobs, every hour).

Use **Send a test** to check every group, and **Send daily messages now** to preview the daily messages.

## What's in the repo

```
public/                 ← uploaded to the site's web folder
  index.html            ← page layout and styles
  app.js                ← the app (all screens)
  api/index.php         ← JSON API: sign-in, data, stock, issues, approvals, repairs, warranties, settings
  api/install.php       ← creates the tables (ha_*) on first run
  api/notify.php        ← Telegram messages
  api/seed.php          ← sample data
.github/workflows/deploy.yml  ← deploys to Hostinger over FTP
```

The tables all start with `ha_`, so this app can share a database with the older asset tracker without clashing.

## One-time setup

1. **Database**: in hPanel go to **Websites → Manage → Databases** and create a database and user. The tables are created automatically on first visit.
2. **FTP**: under **hPanel → Files → FTP Accounts**, note the FTP IP and username (reset the password if you need to).
3. **GitHub secrets**: under **Settings → Secrets and variables → Actions**, add `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `DB_HOST` (`localhost`), `DB_NAME`, `DB_USER`, `DB_PASS`. Set the repository **variable** `SITE_URL` to your domain (default: `https://hostelassettracker.bizskilledu.com`).
4. **Deploy**: run **Actions → Deploy to Hostinger**, or push to `main`.
5. **First sign-in**: the first visit asks you to create the Admin account. Then add hostels, items and users (give each warden a hostel and a password).

To try it with sample data, use **Settings → Load sample data**, or run the workflow with **Load sample data** ticked.

## Run locally

```
cp public/api/config.sample.php public/api/config.php   # fill in your local MySQL details
php -S localhost:8000 -t public
```
