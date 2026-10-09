# wedesygn Backend

PHP REST API for the wedesygn contact form, backed by MySQL and deployable to Vercel with the community PHP runtime.

## Requirements

- PHP 8.2 or newer with PDO MySQL and mbstring enabled
- MySQL database
- Vercel CLI for deployment

## Configuration

Set these environment variables in your local shell and in Vercel Project Settings:

```env
NODE_ENV=development
CORS_ORIGIN=http://localhost:5173
DB_HOST=your_mysql_host
DB_PORT=3306
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASSWORD=your_database_password
ADMIN_API_KEY=your_private_admin_key
MAIL_TO=hello@wedesygn.com,wedesygnofficial@gmail.com
MAIL_FROM=hello@wedesygn.com
```

`CORS_ORIGIN` accepts `*`, one origin, or a comma-separated list of allowed origins. Each origin must be written exactly as the browser sends it: scheme and host only, no path and **no trailing slash** (for example `https://wedesygn.vercel.app`). The production site calls the API on its own domain, so it does not need CORS; the list matters for other frontends such as the Vercel staging site or local development. The API reads `.env` from the directory one level above `public_html` (preferred), or from `public_html` as a local-development fallback. Hosting-provided environment variables take precedence. Never place `.env` inside `public_html` or commit it.

Create the `users` table after setting the database variables:

```bash
php scripts/init-db.php
```

The table columns are `id`, `name`, `email`, `interested_in`, `budget_in_usd`, `project_details`, `created_at`, and `updated_at`. Email addresses are unique.

After saving a contact submission, the API sends one notification to every valid address in `MAIL_TO` (default: `hello@wedesygn.com` and `wedesygnofficial@gmail.com`). The reply-to header is the visitor's email. By default the mail goes out through PHP `mail()`; set `SMTP_HOST`, `SMTP_PORT` (465 = `ssl`, 587 = `tls`), `SMTP_USER` and `SMTP_PASSWORD` to send through a real mailbox instead (recommended for deliverability: add SPF, DKIM and DMARC records for the domain as well). If SMTP is configured but fails, the API falls back to `mail()`. The database save succeeds even if the notification fails; the failure is written to the PHP error log and the response contains `notificationSent: false`.

The database is best effort and the email is the primary channel: if the database cannot be reached the enquiry is still emailed and the API answers `202` with `saved: false`; the request only fails (`500`, with a coarse `reason` and the MySQL `code`) when both the database and the email fail. API responses are sent with `Cache-Control: no-store`. `GET /api/health?check=db` (header `X-Admin-Key`) reports which `.env` was read, whether the database and some alternative hosts/ports are reachable from the web server, and whether outbound SMTP ports are open.

The same email address can submit more than once: `users` keeps one row per email (the latest enquiry), and every enquiry is still delivered by email. A repeat submission returns HTTP 200 with `returningVisitor: true`; a new one returns 201.

## Local Development

Start PHP's built-in server with the API front controller:

```bash
php -S localhost:3000 api/index.php
```

For Windows PowerShell, set environment values in the current shell before running PHP if you are not using a local `.env` file, for example:

```powershell
$env:DB_HOST = "your_mysql_host"
$env:DB_PORT = "3306"
$env:DB_NAME = "your_database_name"
$env:DB_USER = "your_database_user"
$env:DB_PASSWORD = "your_database_password"
$env:ADMIN_API_KEY = "your_private_admin_key"
php scripts/init-db.php
php -S localhost:3000 api/index.php
```

## API Reference

There are **5 API operations** across **3 URL paths**. `/api/users` and `/api/create-user` are aliases for the same user operations.

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| `GET` | `/api/health` | Public | Health and service status |
| `POST` | `/api/create-user` | Public | Save contact form details |
| `POST` | `/api/users` | Public | Backwards-compatible create alias |
| `GET` | `/api/users` | `x-admin-key` required | List users with pagination |
| `GET` | `/api/create-user` | `x-admin-key` required | Backwards-compatible list alias |

### Health Check

```http
GET /api/health
```

### Create User

```http
POST /api/create-user
Content-Type: application/json
```

Request body:

```json
{
  "name": "Ali Mazhar",
  "email": "ali@example.com",
  "interestedIn": "Web design",
  "budgetInUsd": "$1,000 - $5,000",
  "projectDetails": "Project requirements go here"
}
```

The legacy `budget` field is also accepted in place of `budgetInUsd`. A successful request returns `201 Created`. Invalid name/email returns `400`, duplicate email returns `409`, and database failures return `500`.

### List Users

```http
GET /api/users?limit=20&offset=0
x-admin-key: your_private_admin_key
```

The endpoint returns up to 100 records per request, newest first, with a total count. Keep this admin endpoint private and do not expose the API key in frontend code.

## Vercel Deployment

1. Import this repository into Vercel.
2. Add `CORS_ORIGIN`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `ADMIN_API_KEY` under Project Settings > Environment Variables.
3. Make sure the MySQL provider allows connections from the deployment environment.
4. Deploy or redeploy. `vercel.json` configures `vercel-php@0.9.0` and routes requests to `api/index.php`.

## Security Notes

- Keep database credentials and `ADMIN_API_KEY` out of frontend code and version control.
- Restrict `CORS_ORIGIN` to your real frontend origin in production.
- Use a restricted database user for the application.
