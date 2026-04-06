# HackDesk v2

HackDesk v2 is a production-grade hackathon management system for VIT Vellore built with vanilla PHP, MySQL, PDO, PHPMailer, QR codes, and FPDF.

## Server Requirements

- PHP 8.2 or newer
- MySQL 8.0 or newer
- Composer
- Apache with `mod_rewrite` and `mod_headers`

## Stack

- PHP 8.2+
- MySQL 8
- PDO prepared statements only
- PHPMailer
- chillerlan/php-qrcode
- setasign/fpdf
- Chart.js via CDN
- Vanilla JavaScript

## Setup

1. Clone the project into your web root.
2. Run `composer install`.
3. Update `config/config.php` with your database, app URL, SMTP, and HMAC settings.
4. Import `schema.sql` into MySQL.
5. Run `php seed.php` from the project root.
6. Point your web server document root to the `public/` directory, or use the provided root `.htaccess` rewrite.
7. Sign in and create your first hackathon.

## Default Login

- Email: `admin@hackdesk.local`
- Password: `Admin@1234`

## Deployment Notes

### Shared Hosting

1. Upload the full project.
2. Run `composer install` locally or on the server.
3. Update `config/config.php`.
4. Ensure Apache allows `.htaccess`.
5. Point the domain or subdirectory to `public/`.
6. Keep `uploads/`, `config/`, `core/`, and `includes/` outside direct public access or rely on the provided `.htaccess` protection.

### Railway

1. Create a new PHP service and a MySQL service.
2. Deploy the repository.
3. Set environment-specific values in `config/config.php` before deployment or adapt the config to read Railway environment variables.
4. Run `composer install`.
5. Import `schema.sql`.
6. Run `php seed.php` once.
7. Set the web root to `public/`.

## Security Notes

- Every database query uses PDO prepared statements.
- CSRF validation is required on every POST form.
- User passwords use `password_hash(..., PASSWORD_ARGON2ID)`.
- Uploads are served only through protected PHP endpoints.
- Sessions use strict mode and rotate on login.
