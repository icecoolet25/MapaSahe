# MapaSahe — running it on your computer

The newest planner is **`system1_3.php`**. It includes the road-path drawing
from `system1_2.php` plus the new UI, log in / sign up, and the user and admin
dashboards. Older versions (`system0_7`, `0.8`, `0.9`, `1_2`) are kept as they
were and still use `style.css`; 1.3 uses `style1_3.css`.

## One-time setup (each groupmate does this once)

1. Put this folder in `C:\xampp\htdocs\` and start **Apache** and **MySQL** in XAMPP.
2. Open `http://localhost/phpmyadmin` → **Import** → choose `mapasahe.sql` → **Go**.
   This creates a database called `mapasahe`. Running it again is safe.
3. Open `http://localhost/<folder name>/splash.html` and press **Plan my route**.

The planner works even without step 2; only log in, the dashboards and the
search log need the database.

## Accounts

- Each computer has its own database, so accounts and dashboard data are not
  shared between groupmates.
- To make your account an admin: register on the Sign up page, then in
  phpMyAdmin → `mapasahe` → **SQL**, run
  `UPDATE users SET role = 'admin' WHERE email = 'you@example.com';`
  and log in again.
- `db_config.php` expects the XAMPP default MySQL login (user `root`, no
  password). Edit your local copy if yours is different, but do not commit it.

## Files

| File | What it is |
|---|---|
| `system1_3.php`, `app.js`, `style1_3.css` | Route planner (newest) |
| `loginMapa.php`, `registerMapa.php`, `login.css`, `register.css` | Log in / sign up pages |
| `login_process.php`, `register_process.php`, `logout.php` | Account handling |
| `user_dashboard.php`, `user_actions.php` | User dashboard (saved and recent trips, account) |
| `admin_dashboard.php`, `admin_api.php`, `admin.js` | Admin dashboard (models, timings, paths, stops, history) |
| `dashboard.css` | Styles for both dashboards |
| `db_config.php`, `auth.php`, `search_logger.php` | Database connection, login checks, search logging |
| `mapasahe.sql` | Creates the database tables |
| `data/mapasahe_edge_geometry_v3.json` | Estimated road paths for drawing (map only, not pathfinding) |

Road paths and travel times are estimates; verify before the final defense.
