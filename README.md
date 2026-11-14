# Hostel Asset Management

**Organization → Foundations → Units.** Each Foundation has its own **Central Warehouse** and its units (type **Hostel** or **College**). Every unit and warehouse keeps a **count** of every item (beds, mattresses, fans…). Every change is recorded, so you can open any item's ledger and see its history from opening stock to today.

It runs on Hostinger shared hosting (PHP 8 + MySQL). Every push to `main` deploys automatically through GitHub Actions once the secrets are set.

## What it does

| Screen | What you do there |
|---|---|
| **Dashboard** | Counts, transfers waiting for your approval, items at repair, warranties about to expire |
| **Inventory** | Hostel, item, unit, category, opening, in, issued, damaged and current count. Click an item to see its full history in a pop-up |
| **Item ledger** | Every entry for one item (opening → received → transfers → repairs → damage) with a running balance |
| **Stock in** | Enter a **Purchase** or **Opening stock** (once per item per unit / warehouse). Items in a category marked *Warranty applicable* also record their warranty (serial no., from, expiry). **Bulk upload** loads opening stock from a CSV template |
| **Issue register** | Every issue: **transfer** (warehouse → warehouse, warehouse → unit, unit → warehouse, unit → unit), **send for repair** (Internal or External), or **damaged**. Warranty items are issued one piece at a time |
| **Approvals** | Transfers wait for the receiving unit / warehouse to update the received qty. Damage waits for an Admin, Organization or the Foundation user to approve |
| **Repairs** | Items out for repair, what's overdue, and **Record return** (repaired → back in stock; not repairable → goes to damage approval) |
| **Physical Stock** | **Add Physical Count**: pick a unit / warehouse and date, enter the counted qty per item. The system qty on that date and the difference are shown; a difference needs a note and waits for an Admin, Organization or the Foundation user to approve, which adjusts stock (*Physical adjustment*). Inventory shows the latest Physical Date, Physical Qty and Difference |
| **Damaged** | Everything written off, with where it was sent (optional) |
| **Warranties** | Warranty / AMC number, supplier, quantity, expiry. Shows what expires in 30 / 90 days |
| **Masters** (Admin) | Foundations (each creates its Central Warehouse), Units (Hostel / College), Items, Categories (Warranty applicable yes/no), Measuring units, Damage destinations, Users |
| **Settings** (Admin) | Telegram notifications |

Every list has counts on top, search and filters, and **Download CSV** (which downloads exactly what the filters show).

### How the counts work
- **In** = received + transfers in + returned from repair
- **Issued** = transfers out + sent for repair
- **Current inventory** = opening + in − issued − damaged ± approved physical adjustments
- A transfer waiting for approval doesn't change stock yet, but it does reserve the quantity so it can't be issued twice.

## Roles

| Role | Can do |
|---|---|
| **Admin** | Everything. The only role that can change masters and settings, and the only role that can delete anything |
| **Organization** | Sees and works on every Foundation, warehouse and unit; approves damage. No masters, settings or deleting |
| **Foundation** | Linked to one Foundation: its Central Warehouse and all its units. Purchases, issues, receives transfers, approves damage for its units |
| **Unit** | Linked to one unit and sees only its data. Adds stock, issues items, updates received qty of incoming transfers, records repair returns, adds warranties |

## Notifications
Notifications go to **Telegram only**. Set them up under **Settings**:
- Create a bot with @BotFather, add it to your group, then paste the bot token and the **main group** chat ID (it starts with `-100`). The main group gets every message.
- Optional: give a Foundation its own group (**Masters → Foundations**) to get its warehouse's and units' messages, and a unit its own group (**Masters → Units**) to get only that unit's messages.
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
5. **First sign-in**: the first visit asks you to create the Admin account. Then add foundations, units, items and users (give each user a role, their Foundation or unit, and a password).

To try it with sample data, use **Settings → Load sample data**, or run the workflow with **Load sample data** ticked.

## Run locally

```
cp public/api/config.sample.php public/api/config.php   # fill in your local MySQL details
php -S localhost:8000 -t public
```
