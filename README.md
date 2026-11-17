# Synapse Setup Guide

Synapse is a PHP and MySQL peer tutoring system. This guide explains how to run it locally with XAMPP on Windows.

## What you need

- XAMPP with Apache, MySQL, and PHP 8.1 or newer.
- Composer for PHP dependency installation.
- A Supabase project for Auth, PostgreSQL, and account verification.
- SMTP credentials from an email provider for booking and tutor workflow notifications.

The PHP installation should have `curl`, `mysqli`, `mbstring`, and OpenSSL enabled. XAMPP normally includes these extensions.

## 1. Put the project in XAMPP

1. Clone the repository, or download it as a ZIP and extract it:

   ```powershell
   git clone https://github.com/tantwoonnn/Synapse.git C:\xampp\htdocs\Synapse
   ```

2. If you downloaded a ZIP, place the extracted project folder at `C:\xampp\htdocs\Synapse`.
3. Open the XAMPP Control Panel and start **Apache** and **MySQL**.

## 2. Create the database

1. Open [http://localhost/phpmyadmin](http://localhost/phpmyadmin).
2. Choose **Import**.
3. Select the project's `database.sql` file and import it.

The script creates the `synapse` database, tables, and demo records. It also adds missing Supabase Auth profile links and tutor-application fields when run against an existing `synapse` database. It does not drop existing tables or records. Back up your database before importing into an existing project.

This setup expects the database to be named `synapse`. If your existing database has a different name, export its tables and data, create or select `synapse`, and import that backup there before continuing. Keep the old database until you have confirmed Synapse works.

## 3. Install the PHP dependencies

Open the project folder in VS Code, then choose **Terminal -> New Terminal**. Run each command separately:

```powershell
cd C:\xampp\htdocs\Synapse
composer install
```

If PowerShell says Composer is not recognized, install Composer for Windows and select `C:\xampp\php\php.exe` in the installer. Close and reopen VS Code after installation, then check `composer --version`.

## 4. Configure Supabase Auth and SMTP

In Supabase:

1. Open **Authentication → URL Configuration**. Set the Site URL to your app address and add the exact login callback `APP_URL/login.php?verified=1` to the allowed redirect URLs. For local work, this is `http://localhost/Synapse/login.php?verified=1`.
2. Open **Authentication → SMTP Settings** and configure a custom SMTP provider if you need reliable confirmation emails. Set up the email confirmation template to use Supabase’s `{{ .ConfirmationURL }}` link.
3. Copy the project URL and publishable/anon key from the project API settings. Copy a server-only secret key separately; the admin account creation page needs it.
4. In the Supabase SQL Editor, run `supabase_schema.sql` for a new database. For an existing Synapse Supabase database, run `supabase_auth_migration.sql` first.

Supabase Auth owns passwords, email confirmation, and resend requests. Synapse stores a separate profile row for its role and booking relationships, linked by `auth_user_id`. Existing profile roles are kept when an account signs up again with the same email. Their old password hashes are not imported: users establish their Supabase password through signup, then confirm their email. For the first admin, register with the email already assigned to the admin profile and confirm it; that links the Auth identity while preserving the admin role. If the seed admin uses a `.test` email, create a confirmed Auth user with your real admin email in the Supabase Dashboard and link its UUID to the intended `users` row in the SQL Editor.

Create a local settings file if you have not already:

```powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

Fill in the `.env` values. The Supabase secret key is only used by the server-side admin user creation page; never put it in browser code:

```dotenv
APP_URL=http://localhost/Synapse
SUPABASE_URL=https://your-project.supabase.co
SUPABASE_PUBLISHABLE_KEY=your-publishable-key
SUPABASE_SECRET_KEY=your-server-only-secret-key
SUPABASE_DATABASE_URL=your-session-pooler-connection-url
SMTP_HOST=your-smtp-host
SMTP_PORT=587
SMTP_ENCRYPTION=tls
SMTP_USERNAME=your-smtp-username
SMTP_PASSWORD=your-smtp-password
SMTP_FROM_EMAIL=notifications@example.com
SMTP_FROM_NAME=Synapse
```

Keep `.env` private and do not commit it to GitHub. The repository's `.gitignore` excludes it.

## 5. Open Synapse

Go to [http://localhost/Synapse/](http://localhost/Synapse/). Register with an email address you can access, open the Supabase confirmation link, then log in. Existing users should register again with the same email to link their existing Synapse role to Supabase Auth. An administrator can also create confirmed accounts from User Management; that page uses the server-only Supabase key.

## Demo accounts

These verified accounts are inserted by `database.sql` for local demonstration:

| Role | Email | Password |
| --- | --- | --- |
| Administrator | `admin@synapse.test` | `Admin@123` |
| Tutor | `staff@synapse.test` | `Staff@123` |
| Tutor | `staff2@synapse.test` | `Staff@123` |
| Learner | `user@synapse.test` | `User@123` |

The demo emails use the reserved `.test` domain and cannot receive mail. To use them with Supabase Auth, create the accounts as an administrator or use Supabase's admin tooling to confirm them; use real email addresses for self-service registration.

## Main features

- Learners can find tutors, see the subjects each tutor teaches, request sessions in a tutor's listed subjects with a chosen end time and optional phone/social contact, view their bookings, rate completed sessions, and apply to become tutors with an optional PDF, Word, JPG, or PNG supporting file (maximum 5 MB).
- When a learner requests a session, Synapse emails the selected tutor with the booking details and a link to review the request.
- When a tutor accepts or declines a session request, Synapse emails the learner. Decline emails include the tutor’s reason.
- When an administrator approves or rejects a tutor application, Synapse emails the applicant the decision and any review note.
- Tutors can choose teaching subjects from the administrator's subject catalog, manage weekly availability, review requests in arrival order, and mark sessions complete. A tutor must enter a reason when rejecting a booking, and the learner can see it on their dashboard.
- Learners can enter a recognizable meeting place and set its exact point on an interactive Leaflet map when requesting a session. Tutors see the place and can open its map pin.
- After a tutor marks a session complete, the learner confirms it or reports an issue for administrator review. Confirmed sessions can be paid online through PayMongo or in-person with cash; the tutor confirms cash receipt.
- Administrators can manage accounts and subjects, review tutor applications and download their supporting files, manage appointments, view activity logs, and print date-filtered reports. Application files are stored with randomized names outside direct web access and are downloaded through an administrator-only page.
- Supabase Auth sends email confirmation links and validates their status at login.
- Booking and tutor workflow notifications are sent through PHPMailer and the configured SMTP provider.

## PayMongo test setup

1. Run `payment_migration.sql` in the Supabase SQL Editor. If you already ran it before adding cash, run `cash_payment_migration.sql` as well. For local MySQL, run `payment_mysql_migration.sql`. Fresh databases also get the payment tables from `supabase_schema.sql` or `database.sql`.
2. To enable online checkout later, set `PAYMONGO_ENABLED=true` and add `PAYMONGO_SECRET_KEY` and `PAYMONGO_WEBHOOK_SECRET` to Vercel. Use the test secret key beginning with `sk_test_` and the signing secret shown for the webhook endpoint. Keep both private. Online payment remains disabled by default.
3. In PayMongo test mode, create a webhook for `https://YOUR-APP-DOMAIN/webhooks/paymongo.php` and subscribe to `checkout_session.payment.paid`. Use an HTTPS production or preview URL that points to the same environment and database.
4. Redeploy after adding the variables. A learner can confirm a tutor-completed session from My Sessions and then open its PayMongo checkout. Synapse changes the payment to paid only after the signed webhook matches the session reference, checkout ID, and amount.

Online checkout currently creates test payments only. Payments go to the PayMongo account connected to the API key; automatic transfers to tutors are not included. In-person cash payments are recorded when the tutor confirms receipt.

For an existing database, run `meeting_location_migration.sql` in the Supabase SQL Editor or `meeting_location_mysql_migration.sql` in MySQL before deploying the meeting map change. Fresh databases get the location columns from `supabase_schema.sql` or `database.sql`. The map uses Leaflet with OpenStreetMap tiles and visible attribution; standard OpenStreetMap tiles are best-effort and should be replaced with a tile provider if usage grows beyond ordinary interactive viewing.

## Troubleshooting

- **Unknown database `synapse`:** import `database.sql` in phpMyAdmin and confirm the database name is `synapse`.
- **Unknown column or table:** import `database.sql` into the `synapse` database. It creates missing tables and adds missing verification, booking, and tutor-application fields without deleting existing data.
- **Composer is not recognized:** install Composer for Windows, select `C:\xampp\php\php.exe`, and reopen VS Code.
- **Supabase confirmation email is not sent:** check the Supabase Auth SMTP settings, email templates, allowed redirect URL, and `SUPABASE_URL` / `SUPABASE_PUBLISHABLE_KEY` values.
- **Booking notification is not sent:** check that Composer completed and the `SMTP_*` settings match your mail provider.
- **Database login fails:** the local defaults are in `config/database.php` (`localhost`, user `root`, blank password). Update those values if your MySQL setup uses different credentials.

## Sharing the project safely

Commit `composer.json`, `composer.lock`, `.env.example`, and the source files. Do not commit `.env` or the `vendor` folder. After downloading the project, each person can run `composer install` to install its PHP dependency.
