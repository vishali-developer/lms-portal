# LeadPro LMS — Lead Management System

A production-ready, full-featured Lead Management System built with PHP, MySQL, Bootstrap 5, and vanilla JavaScript. Designed for companies to track, manage, and convert sales leads efficiently.

---

## Features

### Core
- **Role-based access control** — Admin, Manager, Employee
- **Lead lifecycle** — New → Contacted → Follow-up → Interested → Converted / Closed / Rejected
- **Follow-up system** — Notes, scheduling, timeline view, overdue tracking
- **Kanban board** — Visual drag-and-drop style lead pipeline
- **Reports & Analytics** — Charts (monthly trend, status pie, source bar, employee performance)
- **CSV Export** — Leads and report data
- **Activity Log** — Every action tracked with user, timestamp, and IP
- **Notification system** — In-app alerts for assignments, follow-ups, status changes

### Security
- Password hashing (bcrypt cost 12)
- Prepared statements (PDO) throughout — SQL injection proof
- CSRF tokens on all forms
- XSS protection via `htmlspecialchars` helper
- Session regeneration on login
- Role-based access on every page and API endpoint
- `.htaccess` blocks PHP in uploads, hides sensitive folders

### UI/UX
- Bootstrap 5 + custom design system
- Dark mode toggle (persisted in localStorage)
- Responsive sidebar (collapses on mobile)
- Live global search (AJAX, debounced)
- Inline status & assignment updates (AJAX)
- Toast notifications for AJAX feedback
- Profile picture upload

---

## Project Structure

```
lms/
├── admin/
│   ├── leads.php          — Lead list with filters, bulk actions
│   ├── lead-form.php      — Add / Edit lead
│   ├── lead-detail.php    — Lead detail + follow-up timeline
│   ├── employees.php      — Employee CRUD
│   ├── followups.php      — All follow-ups (today / overdue / upcoming)
│   ├── kanban.php         — Kanban board view
│   ├── reports.php        — Analytics & charts
│   ├── activity-log.php   — System activity log
│   └── settings.php       — Company, SMTP, lead sources
│
├── employee/
│   ├── leads.php          — Assigned leads
│   ├── lead-detail.php    — Proxy to admin detail (with auth check)
│   └── followups.php      — My follow-ups
│
├── api/
│   ├── leads.php          — AJAX: status update, assign, delete
│   ├── followups.php      — AJAX: add follow-up
│   ├── notifications.php  — AJAX: list, mark-read
│   ├── export.php         — CSV export (leads + reports)
│   └── search.php         — Live search API
│
├── auth/
│   ├── logout.php
│   ├── forgot-password.php
│   ├── reset-password.php
│   ├── profile.php        — Edit profile + change password + activity
│   └── change-password.php
│
├── includes/
│   ├── config.php         — DB credentials, constants
│   ├── db.php             — PDO singleton + helpers
│   ├── auth.php           — Session, login, CSRF, flash, logging
│   ├── header.php         — Sidebar + topbar template
│   └── footer.php         — JS includes
│
├── assets/
│   ├── css/main.css       — Full design system
│   └── js/main.js         — Sidebar, theme, AJAX, search, toasts
│
├── uploads/               — Profile images (PHP blocked by .htaccess)
├── database/schema.sql    — Full MySQL schema + seed data
├── index.php              — Root redirect
├── login.php              — Login page
├── dashboard.php          — Main dashboard
└── .htaccess              — Security rules
```

---

## Installation

### Requirements
- PHP 7.4+ (PHP 8.x recommended)
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite` enabled (or Nginx equivalent)

### Steps

**1. Clone / Upload**
```bash
# Upload the lms/ folder to your web root or public_html
```

**2. Create Database**
```bash
mysql -u root -p
CREATE DATABASE lms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
exit;

mysql -u root -p lms_db < lms/database/schema.sql
```

**3. Configure**

Edit `lms/includes/config.php`:
```php
define('DB_HOST',  'localhost');
define('DB_USER',  'your_db_user');
define('DB_PASS',  'your_db_password');
define('DB_NAME',  'lms_db');
define('APP_URL',  'https://yourdomain.com/lms');  // no trailing slash
```

**4. Set Permissions**
```bash
chmod 755 lms/uploads/
# uploads must be writable by the web server
chown www-data:www-data lms/uploads/
```

**5. Access**
Visit: `http://localhost/lms/`

---

## Demo Credentials

| Role  | Email           | Password   |
|-------|-----------------|------------|
| Admin | admin@lms.com   | Admin@123  |
| Employee | john@lms.com | Admin@123 |

**Change passwords immediately after first login!**

---

## cPanel Deployment

1. Zip the `lms/` folder
2. Upload to `public_html/lms/` via File Manager
3. Create MySQL database in cPanel → MySQL Databases
4. Import `database/schema.sql` via phpMyAdmin
5. Update `includes/config.php` with cPanel DB credentials
6. Browse to `yourdomain.com/lms/`

---

## AJAX Endpoints

| Endpoint | Method | Action |
|----------|--------|--------|
| `api/leads.php?action=update-status` | POST | Update lead status |
| `api/leads.php?action=assign` | POST | Assign lead to employee |
| `api/leads.php?action=delete&id=N` | GET | Delete a lead |
| `api/followups.php` | POST | Add follow-up note |
| `api/notifications.php?action=list` | GET | List notifications |
| `api/notifications.php?action=mark-all-read` | POST | Mark all read |
| `api/search.php?q=term` | GET | Live lead search |
| `api/export.php?type=leads` | GET | Download CSV |
| `api/export.php?type=report` | GET | Download report CSV |

---

## Future Scalability

The modular structure supports easy additions:
- **WhatsApp API** — Add `api/whatsapp.php` + settings key
- **Email notifications** — PHPMailer already wired in `auth.php`; fill in `smtp_*` settings
- **AI Lead Scoring** — Score field in `leads` table + scoring module
- **CRM Module** — Add `crm/` folder with deals, pipelines
- **Dealer Portal** — Add `dealer/` role and panel
- **Mobile App** — All APIs are JSON-ready
- **Marketing Automation** — Add `campaigns/` module

---

## Security Checklist

- [x] SQL injection — PDO prepared statements throughout
- [x] XSS — `e()` helper wraps all output
- [x] CSRF — tokens on every form, verified server-side
- [x] Brute force — Failed login logged (add rate limiting for production)
- [x] Session fixation — Session regenerated on login
- [x] File upload — Type + size validation, PHP blocked in uploads/
- [x] Directory listing — Disabled via `.htaccess`
- [x] Sensitive file access — Blocked via `.htaccess`

---

## License

MIT — Free for personal and commercial use.
