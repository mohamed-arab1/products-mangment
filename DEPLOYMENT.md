# 🚀 Deploying on Vercel + Supabase

This guide walks you through deploying this Laravel backend on **Vercel** (serverless PHP runtime) with **Supabase** (PostgreSQL) as the database.

---

## 📋 Prerequisites

- A [Vercel](https://vercel.com) account
- A [Supabase](https://supabase.com) account
- [Vercel CLI](https://vercel.com/docs/cli) installed (`npm i -g vercel`)
- Your project pushed to a GitHub repository

---

## 🗄️ Step 1: Set Up Supabase

1. Go to [supabase.com](https://supabase.com) and create a new project.
2. Wait for the project to be provisioned.
3. Navigate to **Project Settings → Database**.
4. Under **Connection string**, choose **Session mode** (port `5432`) and copy the connection string.
5. Note your **Host**, **Database**, **Username**, and **Password**.

### Run Migrations on Supabase

After connecting, you'll run migrations once via the Vercel CLI or Supabase SQL editor.

You can also connect locally and run:
```bash
DB_CONNECTION=pgsql \
DB_HOST=db.xxxxxxxxxxxxxxxxxxxx.supabase.co \
DB_PORT=5432 \
DB_DATABASE=postgres \
DB_USERNAME=postgres \
DB_PASSWORD=your-password \
DB_SSLMODE=require \
php artisan migrate --force
```

---

## ☁️ Step 2: Deploy on Vercel

### Option A: Deploy via Vercel Dashboard (Recommended)

1. Push your project to **GitHub**.
2. Go to [vercel.com/new](https://vercel.com/new).
3. Import your GitHub repository.
4. Set the **Framework Preset** to `Other`.
5. Set the **Root Directory** to `./` (leave default).
6. Click **Deploy** — Vercel will auto-detect `vercel.json`.

### Option B: Deploy via Vercel CLI

```bash
# Login to Vercel
vercel login

# Deploy from your project directory
cd /path/to/mabe3aty_backend-main
vercel --prod
```

---

## 🔑 Step 3: Set Environment Variables on Vercel

In Vercel Dashboard → Your Project → **Settings → Environment Variables**, add:

| Key | Value |
|---|---|
| `APP_NAME` | `Mabe3aty` |
| `APP_ENV` | `production` |
| `APP_KEY` | *(generate with `php artisan key:generate --show`)* |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://your-project.vercel.app` |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | `db.xxxxxxxxxxxxxxxxxxxx.supabase.co` |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | `postgres` |
| `DB_USERNAME` | `postgres` |
| `DB_PASSWORD` | *(your Supabase DB password)* |
| `DB_SSLMODE` | `require` |
| `SESSION_DRIVER` | `array` |
| `CACHE_STORE` | `array` |
| `QUEUE_CONNECTION` | `sync` |
| `LOG_CHANNEL` | `stderr` |

### Generate APP_KEY locally:
```bash
php artisan key:generate --show
```
Copy the output (starts with `base64:`) and set it as `APP_KEY` in Vercel.

---

## 🔄 Step 4: Run Migrations

Since Vercel is serverless, you cannot run `php artisan migrate` directly. Use one of these methods:

### Method 1: Via Supabase SQL Editor
Export your migration SQL and run it in the Supabase SQL editor.

### Method 2: Locally with Supabase credentials
```bash
# Copy .env.example to .env and fill in your Supabase credentials
cp .env.example .env
# Edit .env with your values, then:
php artisan migrate --force
```

### Method 3: Temporary Vercel Function (Advanced)
Add a protected endpoint that runs migrations once, then remove it.

---

## ⚠️ Important Notes for Serverless

Since Vercel uses serverless functions:
- **File storage** is read-only. Use a cloud storage service (e.g., S3, Cloudinary) for file uploads.
- **Queue jobs** run synchronously (`QUEUE_CONNECTION=sync`). For background jobs, use a queue service.
- **Sessions** use the `array` driver (stateless). This is fine since the API uses Sanctum tokens.
- **Cache** uses the `array` driver per-request. For persistent caching, use Redis (e.g., Upstash).

---

## 📁 Project Structure for Vercel

```
mabe3aty_backend-main/
├── api/
│   └── index.php        ← Vercel serverless entry point
├── vercel.json          ← Vercel configuration
├── public/
│   └── index.php        ← Local development entry point
└── ...
```

---

## 🔍 Verify Deployment

After deploying, test your API:
```bash
curl https://your-project.vercel.app/api/your-endpoint
```

---

## 🛟 Troubleshooting

- **500 errors**: Check Vercel function logs in the dashboard → Deployments → Functions tab.
- **DB connection errors**: Verify your Supabase credentials and that `DB_SSLMODE=require`.
- **Storage errors**: Switch `FILESYSTEM_DISK` to a cloud provider.
