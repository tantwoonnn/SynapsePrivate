# Synapse Setup Guide

Synapse is a PHP and MySQL peer tutoring system. This guide explains how to run it locally with XAMPP on Windows.

## What you need

- XAMPP with Apache, MySQL, and PHP 8.1 or newer.
- Composer for PHP dependency installation.
- A Google account and a Google Cloud project configured for the Gmail API if you want to send real verification emails.

The PHP installation should have `mysqli`, `mbstring`, and OpenSSL enabled. XAMPP normally includes these extensions.

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

The script creates the `synapse` database, tables, and demo records. It also adds missing email-verification fields and tutor-application fields when run against an existing `synapse` database. It does not drop existing tables or records. Back up your database before importing into an existing project.

This setup expects the database to be named `synapse`. If your existing database has a different name, export its tables and data, create or select `synapse`, and import that backup there before continuing. Keep the old database until you have confirmed Synapse works.

## 3. Install the Google API client

Open the project folder in VS Code, then choose **Terminal -> New Terminal**. Run each command separately:

```powershell
cd C:\xampp\htdocs\Synapse
composer install
```

If PowerShell says Composer is not recognized, install Composer for Windows and select `C:\xampp\php\php.exe` in the installer. Close and reopen VS Code after installation, then check `composer --version`.

## 4. Set up Gmail API email verification

Open [Google Cloud Console](https://console.cloud.google.com/) and set up Gmail API OAuth access:

1. Create or select a Google Cloud project and enable **Gmail API**.
2. Configure the Google Auth Platform consent screen. For a personal Gmail account, choose **External** and add the Gmail account that will send mail as a test user.
3. Create an OAuth client ID for a **Web application**. Add `https://developers.google.com/oauthplayground` as an authorized redirect URI.
4. Open [OAuth 2.0 Playground](https://developers.google.com/oauthplayground). In its settings, enable **Use your own OAuth credentials**, select the server-side flow and offline access, and enter your OAuth client ID and secret.
5. In Step 1, enter the scope `https://www.googleapis.com/auth/gmail.send`, authorize it with the sender Gmail account, then exchange the authorization code in Step 2. Copy the refresh token.

The Gmail API uses OAuth 2.0 and the `gmail.send` permission to send the message. The refresh token lets Synapse obtain access tokens when sending verification emails. If the OAuth app remains in Testing status, Google expires the authorization after seven days, so you will need to authorize again. [Google Gmail OAuth guide](https://developers.google.com/workspace/gmail/api/auth/web-server), [Gmail send scope](https://developers.google.com/workspace/gmail/api/auth/scopes), [Google OAuth testing status](https://support.google.com/cloud/answer/15549945?hl=en)

Create a local settings file if you have not already:

```powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

Fill in the `.env` values. `GMAIL_SENDER_EMAIL` must be the Gmail account you authorized, or one of its configured send-as aliases:

```dotenv
APP_URL=http://localhost/Synapse
GOOGLE_OAUTH_CLIENT_ID=your-client-id
GOOGLE_OAUTH_CLIENT_SECRET=your-client-secret
GOOGLE_OAUTH_REFRESH_TOKEN=your-refresh-token
GMAIL_SENDER_EMAIL=your-sender@gmail.com
GMAIL_SENDER_NAME=Synapse
```

Keep `.env` private and do not commit it to GitHub. The repository's `.gitignore` excludes it.

## 5. Open Synapse

Go to [http://localhost/Synapse/](http://localhost/Synapse/). Register a learner account with an email address you can access, then open the verification link from your inbox. Verified accounts can log in and use the protected pages.

## Demo accounts

These verified accounts are inserted by `database.sql` for local demonstration:

| Role | Email | Password |
| --- | --- | --- |
| Administrator | `admin@synapse.test` | `Admin@123` |
| Tutor | `staff@synapse.test` | `Staff@123` |
| Tutor | `staff2@synapse.test` | `Staff@123` |
| Learner | `user@synapse.test` | `User@123` |

The demo emails use the reserved `.test` domain and cannot receive mail. Use a real email address for new registrations and verification.

## Main features

- Learners can find tutors, see the subjects each tutor teaches, request sessions in a tutor's listed subjects with a chosen end time and optional phone/social contact, view their bookings, rate completed sessions, and apply to become tutors with an optional PDF, Word, JPG, or PNG supporting file (maximum 5 MB).
- When a learner requests a session, Synapse emails the selected tutor with the booking details and a link to review the request.
- When a tutor accepts or declines a session request, Synapse emails the learner. Decline emails include the tutor’s reason.
- When an administrator approves or rejects a tutor application, Synapse emails the applicant the decision and any review note.
- Tutors can choose teaching subjects from the administrator's subject catalog, manage weekly availability, review requests in arrival order, and mark sessions complete. A tutor must enter a reason when rejecting a booking, and the learner can see it on their dashboard.
- Administrators can manage accounts and subjects, review tutor applications and download their supporting files, manage appointments, view activity logs, and print date-filtered reports. Application files are stored with randomized names outside direct web access and are downloaded through an administrator-only page.
- Email verification links expire after 24 hours and can only be used once.

## Troubleshooting

- **Unknown database `synapse`:** import `database.sql` in phpMyAdmin and confirm the database name is `synapse`.
- **Unknown column or table:** import `database.sql` into the `synapse` database. It creates missing tables and adds missing verification, booking, and tutor-application fields without deleting existing data.
- **Composer is not recognized:** install Composer for Windows, select `C:\xampp\php\php.exe`, and reopen VS Code.
- **Verification email is not sent:** check that Composer completed, Gmail API is enabled, `.env` has valid OAuth credentials and refresh token, the sender email matches the authorized Gmail account, Apache/MySQL are running, and `APP_URL` is `http://localhost/Synapse`. If the OAuth app is in Testing status, authorize again after the refresh token expires.
- **Database login fails:** the local defaults are in `config/database.php` (`localhost`, user `root`, blank password). Update those values if your MySQL setup uses different credentials.

## Sharing the project safely

Commit `composer.json`, `composer.lock`, `.env.example`, and the source files. Do not commit `.env` or the `vendor` folder. After downloading the project, each person can run `composer install` to install its PHP dependency.
