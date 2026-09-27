# Wedesygn Backend

> Express REST API for the Wedesygn contact form, backed by MySQL and ready for Vercel.

## Features

- Express 5 API
- MySQL connection pooling with `mysql2`
- Contact form user submission endpoint
- CORS configuration through environment variables
- Helmet security headers
- Nodemon development workflow
- Vercel serverless deployment support

## Requirements

- Node.js 20 or newer
- npm
- A MySQL database with remote access enabled

## Installation

```bash
npm install
```

Create a local `.env` file from `.env.example` and fill in the database credentials. Never commit `.env`.

## Environment Variables

```env
NODE_ENV=development
PORT=3000
CORS_ORIGIN=http://localhost:5173
DB_HOST=mysql.gb.stackcp.com
DB_PORT=39952
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASSWORD=your_database_password
```

`CORS_ORIGIN` accepts one origin or a comma-separated list of origins:

```env
CORS_ORIGIN=http://localhost:5173,https://your-frontend.vercel.app
```

## Database Setup

After the database variables are configured, create the `users` table:

```bash
npm run db:init
```

The table contains:

| Column            | Type           | Required    |
| ----------------- | -------------- | ----------- |
| `id`              | `BIGINT`       | Yes         |
| `name`            | `VARCHAR(120)` | Yes         |
| `email`           | `VARCHAR(255)` | Yes, unique |
| `interested_in`   | `VARCHAR(120)` | No          |
| `budget_in_usd`   | `VARCHAR(80)`  | No          |
| `project_details` | `TEXT`         | No          |
| `created_at`      | `TIMESTAMP`    | Yes         |
| `updated_at`      | `TIMESTAMP`    | Yes         |

For hosted MySQL, add the machine or platform IP that will connect to MySQL in the provider's **Remote MySQL Access** allowlist. Vercel deployments may require a Vercel-compatible outbound access configuration.

## Local Development

Start the server with Nodemon:

```bash
npm run dev
```

The local server runs on `http://localhost:3000` by default.

## API Reference

### Health Check

```http
GET /api/health
```

Example:

```text
https://wedesygn-backend.vercel.app/api/health
```

### Create User

Saves contact form details to the `users` table.

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

Example request:

```bash
curl -X POST https://wedesygn-backend.vercel.app/api/create-user \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Ali Mazhar",
    "email": "ali@example.com",
    "interestedIn": "Web design",
    "budgetInUsd": "$1,000 - $5,000",
    "projectDetails": "Project requirements go here"
  }'
```

Successful response: `201 Created`

```json
{
  "message": "User details saved successfully",
  "user": {
    "id": 1,
    "name": "Ali Mazhar",
    "email": "ali@example.com",
    "interestedIn": "Web design",
    "budgetInUsd": "$1,000 - $5,000",
    "projectDetails": "Project requirements go here"
  }
}
```

Error responses:

- `400 Bad Request`: missing name or invalid email
- `409 Conflict`: email already exists
- `500 Internal Server Error`: database or server failure

`POST /api/users` is also available as a backwards-compatible alias.

## Available Scripts

| Command           | Description                          |
| ----------------- | ------------------------------------ |
| `npm install`     | Install dependencies                 |
| `npm run dev`     | Start the Nodemon development server |
| `npm start`       | Start the production server          |
| `npm run db:init` | Create the `users` table             |

## Vercel Deployment

1. Import the repository into Vercel.
2. Set the project root to the `backend` directory if required.
3. Add `NODE_ENV`, `CORS_ORIGIN`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` under Vercel Project Settings > Environment Variables.
4. Make sure the MySQL provider allows connections from the deployment environment.
5. Deploy or redeploy the project.

Production endpoints:

```text
GET  https://wedesygn-backend.vercel.app/api/health
POST https://wedesygn-backend.vercel.app/api/create-user
```

## Security Notes

- Keep `.env` out of Git; it is already ignored.
- Do not expose database credentials in frontend code or API responses.
- Use a restricted database user for the application.
- Set `CORS_ORIGIN` to the real frontend origin in production instead of using `*`.
