# Wedesygn Backend

Minimal Express API ready for Vercel deployment.

## Requirements

- Node.js 20+
- npm

## Local setup

```bash
npm install
npm run dev
```

Initialize the MySQL `users` table after setting the database variables:

```bash
npm run db:init
```

Health endpoint:

```text
GET http://localhost:3000/api/health
```

## Create user

Submit the contact form details with:

```text
POST http://localhost:3000/api/create-user
Content-Type: application/json
```

```json
{
	"name": "Ali Mazhar",
	"email": "ali@example.com",
	"interestedIn": "Web design",
	"budgetInUsd": "$1,000 - $5,000",
	"projectDetails": "Project requirements go here"
}
```

The endpoint returns `201` after saving the record to the `users` table.

`POST /api/users` remains available as a backwards-compatible alias.

## Environment

Copy `.env.example` to `.env` and update values as needed. `.env` is ignored by Git.

`CORS_ORIGIN` accepts one origin or multiple comma-separated origins. The current configuration allows local development and `https://wedesygn-backend.vercel.app`. Add your frontend URL here before connecting a separate deployed frontend.

The API also enables Helmet security headers by default.

Set these private variables in `.env` locally and in Vercel Project Settings:

```env
DB_HOST=sdb-60.hosting.stackcp.net
DB_PORT=3306
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASSWORD=your_database_password
```

The database user must have permission to create tables. Never commit `.env` or expose the database password in source control.

## Deploy to Vercel

1. Import this repository into Vercel.
2. Set the project root to this `backend` directory if prompted.
3. Add the variables from `.env.example` in Vercel Project Settings when needed.
4. Deploy.

The deployed health endpoint will be:

```text
GET https://<your-project>.vercel.app/api/health
```
