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

Health endpoint:

```text
GET http://localhost:3000/api/health
```

## Environment

Copy `.env.example` to `.env` and update values as needed. `.env` is ignored by Git.

## Deploy to Vercel

1. Import this repository into Vercel.
2. Set the project root to this `backend` directory if prompted.
3. Add the variables from `.env.example` in Vercel Project Settings when needed.
4. Deploy.

The deployed health endpoint will be:

```text
GET https://<your-project>.vercel.app/api/health
```
