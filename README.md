# Wedesygn Backend

PHP REST API for the Wedesygn contact form, backed by MySQL and deployable to Vercel with the community PHP runtime.

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
```

`CORS_ORIGIN` accepts `*`, one origin, or a comma-separated list of allowed origins. PHP does not automatically load `.env`; provide the values through your shell or hosting environment. Never commit `.env`.

Create the `users` table after setting the database variables:

```bash
php scripts/init-db.php
```

The table columns are `id`, `name`, `email`, `interested_in`, `budget_in_usd`, `project_details`, `created_at`, and `updated_at`. Email addresses are unique.

## Local Development

Start PHP's built-in server with the API front controller:

```bash
php -S localhost:3000 api/index.php
```

For Windows PowerShell, set environment values in the current shell before running PHP, for example:

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