Project: assessment.netcascade.in

Hosting: Hostinger Shared Hosting
SSH Access: Enabled
PHP: 8.1
Composer: Installed
Project Root:
~/domains/assessment.netcascade.in/public_html

Current Goal:
Build authentication first:
- Login
- JWT
- Session Control
- OTP Verification
- Password Reset

Tech Stack:
- PHP
- MySQL
- REST API
- JWT
- HTML/JS

Current Status:
- Composer available
- Project structure created
- Authentication REST APIs created
- JWT bearer auth with DB-backed sessions created
- JWT also stored in HttpOnly SameSite cookies for browser auth
- Server-side protected page gates created
- CSRF protection created for authenticated POST APIs
- Auth rate limiting created
- Security event logging created
- CSP, HSTS, and browser security headers created
- Email OTP verification created
- Password reset flow created
- Question bank, attempts, responses, and result schema created
- Initial login, registration, dashboard, assessment, and result frontend created

Project Structure:
- `api/index.php` - REST API router
- `app/Core` - request, response, validation, JWT helpers
- `app/Controllers` - authentication and assessment controllers
- `app/Services` - auth session and email helpers
- `config/env.example.php` - copy to `config/env.php` and fill production values
- `database/schema.sql` - MySQL table structure
- `database/seed.sql` - starter subjects, topics, and sample demo questions
- `assets/css`, `assets/js` - frontend styles and browser logic

Setup Steps:
1. Copy `config/env.example.php` to `config/env.php`.
2. Set a long random `jwt_secret`.
3. Fill Hostinger MySQL credentials in `config/env.php`.
4. Import `database/schema.sql`.
5. Import `database/seed.sql` for starter data.
6. Confirm Hostinger mail delivery works for OTP and reset messages.

Local Run Steps:
1. For local HTTP testing, set `app_url` to `http://localhost:8000`.
2. For local HTTP testing, set `cookie_secure` to `false`.
3. Start PHP from the project root:
   `php -S localhost:8000 router.php`
4. Open `http://localhost:8000/login`.
5. For production HTTPS, set `app_url` to `https://assessment.netcascade.in` and `cookie_secure` to `true`.

API Routes:
- `POST /api/auth/register`
- `POST /api/auth/verify-email`
- `POST /api/auth/resend-otp`
- `POST /api/auth/login`
- `POST /api/auth/forgot-password`
- `POST /api/auth/reset-password`
- `GET /api/auth/me`
- `POST /api/auth/logout`
- `GET /api/categories`
- `GET /api/topics?category_id=1`
- `POST /api/attempts/start`
- `GET /api/attempts/{id}/question?index=0`
- `POST /api/attempts/{id}/answer`
- `POST /api/attempts/{id}/submit`
- `GET /api/attempts/{id}/result`

Clean URL Rules:
- Public page links use `/login`, `/register`, `/forgot-password`, `/reset-password`, `/dashboard`, `/assessment`, and `/result`.
- Direct `.php` page requests redirect to the extensionless URL.
- Protected pages call `AuthService::requirePage()` before rendering HTML; invalid or expired sessions redirect to `/login`.
- After page load, `auth/me` also validates the JWT and DB session; invalid or expired sessions are cleared and redirected to `/login`.

Security Notes:
- Browser sessions use an HttpOnly `ASSESSMENT_AUTH` cookie so JavaScript cannot read the JWT.
- Authenticated POST requests require the `X-CSRF-Token` header matching the `ASSESSMENT_CSRF` cookie and DB session hash.
- REST APIs still support `Authorization: Bearer <token>` for API clients, but browser auth should use cookies.
- Login, OTP, registration, and password reset endpoints are rate limited.
- Auth events are recorded in `security_events`.
