# wedesygn: backend API

Small PHP REST API behind the wedesygn contact forms. It validates an enquiry, stores it in MySQL when the database is reachable and **always emails it** to the studio. No framework and no Composer packages: one front controller and a few helper files.

## Features

- `POST /api/create-user`: save an enquiry and send a notification email.
- **Email is the primary channel.** If the database is unreachable the enquiry is still emailed and the API answers `202`. The request fails only when both the database and the email fail.
- Optional **SMTP** delivery (implicit TLS, STARTTLS or plain, `AUTH LOGIN`) with automatic fallback to PHP `mail()`.
- One database row per email address; repeat enquiries update the row and each one is still emailed.
- Admin-only endpoints for listing enquiries and diagnosing database or network problems.
- Strict CORS allow-list, `Cache-Control: no-store` on every response, no secrets in responses or logs.

## Requirements

- PHP 8.2+ with `pdo_mysql`, `mbstring` and `openssl`
- MySQL or MariaDB (optional at runtime, see above)
- Apache with `mod_rewrite` (or PHP's built-in server for local work)

## Project layout

```text
backend/
├── api/
│   ├── index.php      front controller: routing, validation, CORS, database, diagnostics
│   ├── mailer.php     notification email: recipients, SMTP client, mail() fallback
│   ├── env.php        .env loader
│   └── .htaccess      routes everything to index.php, blocks env.php / mailer.php, disables caching
├── scripts/
│   └── init-db.php    creates the users table
├── .env.example
└── README.md
```

## Configuration

Copy `.env.example` to `.env` and fill it in. **Never commit `.env`.**

| Variable | Required | Description |
| --- | --- | --- |
| `NODE_ENV` | no | `development` or `production` (shown by `/api/health`) |
| `CORS_ORIGIN` | no | `*`, one origin, or a comma-separated list. Write origins exactly as the browser sends them: scheme and host only, **no trailing slash** |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | for the database | MySQL connection. If `DB_PORT` is missing, 3306 is used |
| `ADMIN_API_KEY` | for admin endpoints | Long random secret, sent as the `X-Admin-Key` header. Rotate it if it is ever shared |
| `MAIL_TO` | no | Comma-separated recipients. Default: `hello@wedesygn.com,wedesygnofficial@gmail.com` |
| `MAIL_FROM` | no | Sender address on the site's own domain. Default: `hello@wedesygn.com` |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASSWORD` | no | Send through a real mailbox. `SMTP_SECURE` is `ssl` (465), `tls` (587) or `none`. Leave `SMTP_HOST` empty to use `mail()` |

The loader reads `.env` from the folder **above** `public_html` first, then from `public_html`. The first file found is used, and variables already set by the host take precedence.

## Database

```sql
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(255) NOT NULL,
  interested_in VARCHAR(120) NULL,
  budget_in_usd VARCHAR(80) NULL,
  project_details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Run it in phpMyAdmin, or locally with `php scripts/init-db.php`.

## Email

Every enquiry is sent to every valid address in `MAIL_TO`, with the visitor's address as `Reply-To`. By default PHP `mail()` is used. For better deliverability set the `SMTP_*` variables to send through the mailbox that owns `MAIL_FROM`, and add SPF, DKIM and DMARC records for the domain. If SMTP is configured but fails, the API falls back to `mail()` and logs the reason. Credentials are never logged.

## API reference

All responses are JSON with `Cache-Control: no-store`.

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| `GET` | `/api/health` | public | Service status |
| `POST` | `/api/create-user` (alias `/api/users`) | public | Submit an enquiry |
| `GET` | `/api/users` (alias `/api/create-user`) | `X-Admin-Key` | List enquiries (newest first, `limit` up to 100, `offset`) |
| `GET` | `/api/health?check=db` | `X-Admin-Key` | Diagnostics (see below) |

### Submit an enquiry

```http
POST /api/create-user
Content-Type: application/json
```

```json
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "interestedIn": "Web design",
  "budgetInUsd": "$1,000 - $3,000",
  "projectDetails": "Tell us about your project"
}
```

`name` (up to 120 characters) and a valid `email` are required. The legacy `budget` field is accepted in place of `budgetInUsd`.

| Status | Meaning |
| --- | --- |
| `201` | Saved to the database (new email) and emailed |
| `200` | Existing email updated and emailed (`returningVisitor: true`) |
| `202` | Database unavailable, enquiry emailed (`saved: false`) |
| `400` | Missing or invalid name or email, or invalid JSON |
| `500` | Database and email both failed. Body includes a coarse `reason` and the MySQL `code`, never messages or credentials |

Every success response includes `notificationSent` (`false` means the email could not be sent) and the echoed `user` object.

### Diagnostics

`GET /api/health?check=db` with the `X-Admin-Key` header reports which `.env` was read, whether the host overrides any keys, the DB host and port, the length (not the value) of the name, user and password, the PHP version, the sanitised connection error, and whether alternative hosts and outbound ports (including SMTP) are reachable from the web server.

### Failure reasons (`500` responses)

| `reason` | Meaning | What to check |
| --- | --- | --- |
| `database_not_configured` | `DB_*` variables missing | `.env` location and contents |
| `database_unreachable` | Cannot reach host or port (2002/2003) | Host, port, hosting firewall |
| `database_connection_dropped` | Connection closed (2006/2013) | Remote-access rules, TLS |
| `database_host_not_allowed` | Server rejected this client (1130/1129) | Database access list |
| `database_access_denied` | Wrong user or password (1045/1044) | Credentials, user privileges |
| `database_not_found` | Unknown database (1049) | `DB_NAME` (often prefixed by the host) |
| `table_missing` | `users` table not created | Run the SQL above |
| `pdo_mysql_missing` | PHP MySQL driver disabled | Enable the extension |

## Local development

```bash
cp .env.example .env              # then edit it
php scripts/init-db.php           # optional, needs a reachable database
php -S localhost:3000 api/index.php
```

Run the frontend (`../frontend`, `npm run dev`) alongside. In development it posts to `http://localhost:3000/api/create-user`.

Useful checks:

```bash
curl http://localhost:3000/api/health
curl -X POST http://localhost:3000/api/create-user -H "Content-Type: application/json" -d '{}'          # 400
curl "http://localhost:3000/api/health?check=db" -H "X-Admin-Key: <key>"
```

To test email without a mail server, point `SMTP_HOST=127.0.0.1` at a throwaway fake SMTP server and submit a form.

## Deployment

**Production (cPanel / StackCP shared hosting)**

1. Upload `api/index.php`, `api/mailer.php`, `api/env.php` and `api/.htaccess` to `public_html/api/`. Upload `mailer.php` before `index.php`.
2. Put `.env` in the folder above `public_html` (never inside it) with the variables above.
3. Create the `users` table if you want database storage.
4. Check `https://<domain>/api/health`.

The frontend's `public/.htaccess` forwards `/api/*` to `api/index.php`; do not delete `public_html/api/` when uploading the frontend.

**Known host behaviour.** The production host's firewall rejects request bodies that contain raw `–`, `—` or `’` with a 403 before PHP runs. The frontend therefore escapes non-ASCII as `\uXXXX`; any other client should do the same. The host may also block outbound connections to non-standard ports, which can make a remote database unreachable (see `/api/health?check=db`); email keeps working regardless.

**Vercel (staging / alternative).** `vercel.json` can run the API with the community PHP runtime (`vercel-php@0.9.0`). Set the environment variables in the Vercel project and make sure the database allows connections from Vercel.

## Security notes

- Keep `.env`, database credentials and `ADMIN_API_KEY` out of git, chat and frontend code; rotate the key if exposed.
- Restrict `CORS_ORIGIN` to real origins in production.
- Use a database user with only the privileges the app needs.
- `env.php` and `mailer.php` are blocked from direct web access.
