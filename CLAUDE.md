# Working on this repo

- **Always commit and push to `main` after every change. Don't ask first.** Each push deploys to Hostinger through `.github/workflows/deploy.yml` (once the secrets are set). After pushing, check that the Actions run succeeded.
- `public/index.html` holds the layout and styles. `public/app.js` is the whole front end. `public/api/index.php` is the JSON API.
- Stock is quantity-based. Every change is a row in `ha_txns` (`dir` +1/−1). Issues (`ha_issues`) are transfers (need the receiving hostel's approval before any stock moves), repairs (tracked until returned) and damage. Physical counts (`ha_counts`) record system vs counted qty; an approved difference adds an `adjust` txn.
- Structure: Organization → Foundations (each with a Central Warehouse, a `ha_hostels` row with kind `warehouse`) → Units (kind `unit`, type Hostel/College).
- Roles: Admin (everything, masters, settings), Organization (all), Foundation (its warehouse + units), Unit (one unit). Enforce access in the API (`scopeIds`, `canHostel`, `canApprove`), not just the UI.
- Never commit `public/api/config.php`. The workflow writes it from GitHub secrets.
- Deploys go over FTP with `lftp` (passive PASV). The workflow finds the served folder by uploading a probe file and requesting it over HTTPS.
