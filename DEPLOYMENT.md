# NoteSphere deployment guide

## 1. Database (Aiven MySQL)

The app no longer has hardcoded database credentials. Every page loads
`includes/db_config.php`, which reads these variables:

| Variable | Example | Notes |
| --- | --- | --- |
| `DB_HOST` | `notesphere-xjallorina-16dc.g.aivencloud.com` | |
| `DB_PORT` | `27295` | Aiven port |
| `DB_NAME` | `defaultdb` | |
| `DB_USER` | `avnadmin` | |
| `DB_PASS` | *(your Aiven password)* | never commit this |
| `DB_SSL` | `1` | TLS is on by default for remote hosts |
| `DB_SSL_CA_PEM` | *(CA certificate text)* | recommended on Vercel |
| `DB_SESSIONS` | `1` | required on Vercel |

Import your existing dump once with the MySQL client:

```powershell
C:\xampp\mysql\bin\mysql.exe -h notesphere-xjallorina-16dc.g.aivencloud.com -P 27295 -u avnadmin -p defaultdb < "%USERPROFILE%\Downloads\1notesphere_db.sql"
```

`includes/session.php` creates the `php_sessions` table automatically.

### Local XAMPP

```powershell
copy .env.example .env
```

Then edit `.env` and set the real `DB_PASS`. When `DB_HOST` is `localhost`,
TLS is disabled automatically.

## 2. Cloudinary (file uploads)

Vercel Functions have a read-only filesystem and reject request bodies larger
than about 4.5 MB, so uploads are sent **directly from the browser to
Cloudinary**. The PHP handlers only receive the resulting URL in small hidden
fields (`note_file__url`, etc.). Local XAMPP uploads still use `uploads/` when
Cloudinary is not configured.

1. Create a Cloudinary account.
2. In **Settings → Upload**, add an **unsigned** upload preset (e.g.
   `notesphere-unsigned`).
3. Set these variables locally in `.env` and in Vercel:

```
CLOUDINARY_CLOUD_NAME=your-cloud-name
CLOUDINARY_UPLOAD_PRESET=your-unsigned-preset
CLOUDINARY_FOLDER=notesphere
```

The preset accepts `auto/upload` with `use_filename=true` and
`unique_filename=true`. URLs are validated server-side against
`https://res.cloudinary.com/<your cloud name>/...` before being stored.

Existing records that still contain local `uploads/...` paths keep working:
local files are served normally on XAMPP, and remote Cloudinary URLs are served
directly on Vercel.

## 3. Deploy to Vercel

1. Push this repository to GitHub.
2. In Vercel, **Add New → Project** and import the repository.
3. Add all variables from `.env.example` in **Project Settings → Environment
   Variables** (production, preview and development).
4. Build settings come from `vercel.json`: the `vercel-php@0.9.0` runtime builds
   a single front controller (`api/index.php`) and routes every `*.php` request
   through it. Static assets (CSS, JS, images) are served by Vercel directly.
5. Deploy. Vercel will not receive `uploads/`, `.env`, `push-server/` or the
   local CA certificate (see `.vercelignore`).

`api/index.php` loads `includes/bootstrap.php`, which starts the database
session and lets the original page scripts run unchanged.

## 4. Mail

Set `MAIL_USERNAME` and `MAIL_APP_PASSWORD` (a Gmail app password) in the
environment. `includes/mail_config.php` reads them; no secret is stored in the
repository.

## 5. Push notifications (limitations)

`push-server/` is a long-running Node.js server and **cannot run inside a
Vercel Function**. Keep running it on a machine that is always on, or deploy it
to a service that supports long-running processes (Render, Fly.io, a VPS, …).

Set `PUSH_SERVER_URL` to its public URL. All PHP pages use
`ns_push_server_url()` from `includes/env.php`, so no hardcoded
`http://127.0.0.1:4000` remains. If the push server is unreachable, page
loading and uploads are unaffected — only the notification is skipped.

## 6. Local lint check

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { & C:\xampp\php\php.exe -l $_.FullName }
```
