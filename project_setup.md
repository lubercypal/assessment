Project: assessment.netcascade.in

Last Updated: 2026-06-13

**Project Overview**
assessment.netcascade.in is a PHP/MySQL assessment platform for student registration, email verification, login, password recovery, and timed assessments. The system supports demo mode with instant feedback and assessment mode with controlled navigation, answer saving, timer enforcement, and final scoring.

The project is deployed on Hostinger shared hosting and uses extensionless URLs, JWT-based browser authentication, DB-backed sessions, CSRF protection for state-changing requests, and environment-selected Microsoft Graph, SMTP, PHP mail, or log delivery.

**Business Requirements Document**
Goal:
- Provide a secure student assessment portal with registration, email verification, login, dashboard, and test-taking flow.

Primary user journeys:
- Register with name, email, mobile, password, and terms acceptance.
- Verify the email by OTP before login.
- Log in and access dashboard.
- Start a demo assessment with instant feedback and explanations.
- Start an actual assessment with timer, randomized questions, answer saving, review/skip controls, and final scoring.
- Reset forgotten passwords securely by email link.

Functional requirements:
- Duplicate email checks during registration.
- Mandatory field validation across all forms.
- Password policy enforcement.
- OTP verification over email.
- Password reset by email link.
- Dynamic question loading from the database.
- Randomized question order for assessments.
- Immediate response persistence before moving to the next question.
- Demo mode must show selected answer, correct answer, and explanation immediately.
- Assessment mode must hide answers until final submission.
- Final result must show attempted, not attempted, review count, time used, and score.

Non-functional requirements:
- Secure auth and session handling.
- Route protection for authenticated pages.
- Mobile and tablet responsive layouts.
- Production-safe logging and error handling.
- Clean URLs without visible `.php` extensions.

**System Architecture Documentation**
High-level architecture:
- Frontend: PHP-rendered pages plus browser JavaScript.
- Backend: PHP REST API router in `api/index.php`.
- Database: MySQL with normalized auth, assessment, and logging tables.
- Mail: Microsoft Graph, SMTP, PHP `mail()`, or local log driver through `app/Services/Mailer.php`.
- Auth: JWT plus DB-backed sessions stored in HttpOnly cookies for browser flows.

Core request flow:
- Public page loads render PHP views directly.
- Browser forms submit to `/api/...` routes using `assets/js/api.js`.
- API requests are routed through `api/index.php`.
- Authenticated POST APIs validate CSRF headers and session cookies.
- Protected pages call `AuthService::requirePage()` before rendering.

Important services:
- `app/Services/AuthService.php` handles sessions, cookies, CSRF, page protection, logout.
- `app/Services/Mailer.php` sends OTP and reset emails.
- `app/Services/RateLimiter.php` limits auth abuse.
- `app/Services/ErrorLogger.php` writes structured app logs.
- `app/Services/SecurityLog.php` records auth/security events.

Security model:
- JWT is stored in `ASSESSMENT_AUTH` HttpOnly cookie for browser auth.
- CSRF token is stored in `ASSESSMENT_CSRF` cookie and validated on protected POST calls.
- Rate limiting exists for register, login, OTP resend, password reset, and verification attempts.
- Security events are logged into the database.
- Security headers include CSP, HSTS, and other browser hardening headers.

**Database Documentation**
Database name:
- `u426922330_htb_assessment`

Schema file:
- `database/schema.sql`

Seed file:
- `database/seed.sql`

Main tables:
- `users`
- `user_sessions`
- `rate_limits`
- `security_events`
- `email_otps`
- `password_resets`
- `categories`
- `topics`
- `questions`
- `question_options`
- `question_media`
- `question_import_batches`
- `assessment_attempts`
- `assessment_responses`

Purpose of key tables:
- `users`: student identity, password hash, verification timestamp, terms acceptance.
- `user_sessions`: DB-backed session lifecycle and revocation.
- `email_otps`: OTP hashes, expiry, and consumption state.
- `password_resets`: reset token hashes, expiry, and consumption state.
- `rate_limits`: anti-abuse tracking for auth actions.
- `security_events`: audit trail for register, login, verification, logout, reset.
- `categories` and `topics`: question taxonomy.
- `questions` and `question_options`: versioned question bank, grouping, scoring, option keys, and media references.
- `question_media`: imported WebP metadata and batch-owned public paths.
- `question_import_batches`: import audit, counts, warnings, and failure information.
- `assessment_attempts`: attempt metadata, mode, timing, score, question order, and locked option order.
- `assessment_responses`: saved answers, status, timestamps.

Seeded content:
- Basic starter categories, topics, and sample demo questions are seeded for the initial assessment experience.

**API Documentation**
Base:
- `/api/...`

Authentication endpoints:
- `POST /api/auth/register`
- `POST /api/auth/verify-email`
- `POST /api/auth/resend-otp`
- `POST /api/auth/login`
- `POST /api/auth/forgot-password`
- `POST /api/auth/reset-password`
- `POST /api/auth/reset-link-status`
- `GET /api/auth/me`
- `POST /api/auth/logout`

Assessment endpoints:
- `GET /api/categories`
- `GET /api/topics?category_id=...`
- `POST /api/attempts/start`
- `GET /api/attempts/{id}/question?index=...`
- `POST /api/attempts/{id}/answer`
- `POST /api/attempts/{id}/submit`
- `GET /api/attempts/{id}/result`

API behavior:
- JSON in, JSON out.
- Validation failures return 422 with field-level details.
- Auth/session failures return 401/403-style responses where applicable.
- Expired reset links and OTP issues return recovery-oriented messages and actions.

**Feature Completion Tracker**
Completed:
- Student registration
- Email OTP verification
- Login
- Forgot password
- Password reset by email link
- JWT auth
- DB-backed sessions
- Protected page access control
- CSRF protection for protected POST APIs
- Rate limiting for auth endpoints
- Security logging
- Error logging
- Clean URLs
- Dashboard entry flow
- Demo assessment flow
- Assessment attempt flow
- Question saving and submission
- Result page flow
- Mobile and tablet responsive auth UI
- Decimal and negative scoring for single-answer and multi-select questions
- Grouped paragraph/table question sequencing
- Per-attempt option order locking
- Secure CSV plus image ZIP question import
- Automatic WebP conversion and conflict-free media folders
- Question versioning and import audit records

In progress / polish:
- Assessment question bank expansion
- More seed questions and topics
- Final UX tuning for assessment screens
- Additional admin or content management tools

**Development Log / Changelog**
2026-06-03:
- Added server-side page protection for authenticated views.
- Moved browser auth to HttpOnly cookie flow.
- Added CSRF validation for protected POST APIs.
- Added DB-backed session validation and logout revocation.
- Added structured app logging and security event logging.
- Added rate limiting for auth actions.
- Added OTP resend cooldown and recovery messaging.
- Added password reset flow with token validation and recovery states.
- Added responsive auth page layouts and cleaner helper action rows.
- Added terms page and cleaner registration flow messaging.

2026-06-13:
- Added decimal marks, negative marks, and exact/partial-credit scoring.
- Added stable question codes and immutable question version history.
- Added paragraph/group question support without a manual order column.
- Added group-aware randomization: complete groups shuffle as units while internal row order remains fixed.
- Added question, passage, and option image support.
- Added secure image ZIP validation, automatic WebP conversion, and unique batch media folders.
- Added import dry-run, audit batches, unchanged detection, and transaction rollback.
- Added option shuffling that is generated once and retained throughout an attempt.
- Added final sample XLSX, import CSV, and matching image ZIP.

Earlier work:
- Built API router and initial controller structure.
- Created schema and seed files.
- Created initial frontend pages for login, registration, dashboard, assessment, and result.

**Decision Log**
- Chosen stack: PHP, MySQL, HTML, JavaScript, JWT, REST APIs.
- Chosen browser auth model: HttpOnly cookie for JWT, not localStorage.
- Chosen session model: JWT plus DB session record for server-side revocation.
- Chosen route style: extensionless URLs with `.php` redirects to canonical paths.
- Chosen deployment style: Hostinger shared hosting with SFTP upload and manual env file.
- Chosen logging style: separate app error log and mail error log.
- Chosen mail flow: OTP and reset emails sent through `Mailer.php` with environment-driven transport.
- Chosen reset flow: token-based password reset, token checked against database, expiry enforced server-side.
- Chosen UI behavior: explicit recovery messages for expired OTP and reset links.
- Chosen question identity: `Question Code` remains stable; edited content creates a new current version.
- Chosen group sequencing: no manual group-order field; consecutive spreadsheet row order defines internal sequence.
- Chosen randomization: standalone questions and whole groups shuffle as units; questions within a group never shuffle.
- Chosen media workflow: uploader filenames only need to match workbook cells; importer creates unique folders and stores WebP.

**Known Issues & Technical Debt**
- No admin UI yet for managing categories, topics, or question bank content.
- Seed data is minimal and should be expanded for real assessment usage.
- `config/env.php` is intentionally not tracked and must be managed carefully per environment.
- SMTP delivery depends on mailbox policy and provider settings.
- The current project does not yet include analytics, reports, or candidate management modules.
- Some auth screens and recovery states may still need fine visual refinement after content changes.
- Final production testing should still include browser-based smoke tests and mail delivery checks.

**Future Roadmap / Backlog**
- Admin module for question authoring and taxonomy management.
- Browser admin/import page built on the existing CSV plus image ZIP importer.
- Candidate listing and assessment analytics.
- Attempt review and export reports.
- Better audit dashboards for security events.
- More granular permission roles if an admin panel is added.
- Extended seed data and richer demo mode content.
- Production observability improvements.
- Automated test coverage for auth and assessment flows.

**Environment & Deployment Guide**
Hosting:
- Hostinger shared hosting

Project root on server:
- `~/domains/assessment.netcascade.in/public_html`

Production setup steps:
1. Upload project files to `public_html`.
2. Create `config/env.php` manually on the server.
3. Set production values in `env.php`.
4. Import `database/schema.sql`.
5. Import `database/seed.sql`.
6. For an existing database, apply `database/2026_06_13_add_question_scoring.sql`.
7. Then apply `database/2026_06_13_add_grouped_question_media.sql`.
8. Confirm the configured mail driver for OTP and reset mail.
9. Confirm `.htaccess` is uploaded.
10. Test `/login`, `/register`, `/forgot-password`, `/reset-password`, `/dashboard`.

Local setup steps:
1. Set `app_url` to `http://localhost:8000`.
2. Set `cookie_secure` to `false`.
3. Run `php -S localhost:8000 router.php`.
4. Open `http://localhost:8000/login`.

Production settings:
- `app_url`: `https://assessment.netcascade.in`
- `cookie_secure`: `true`
- `app_env`: `production`
- DB credentials: Hostinger production values

Important file conventions:
- `config/env.php` is not committed.
- Logs live under `storage/logs/`.
- Clean URLs are routed through `router.php` and `.htaccess`.

**Module Documentation**
Auth module:
- Registration with OTP verification.
- Login with JWT session creation.
- Forgot password and reset password by email link.
- Logout and session revocation.

Assessment module:
- Category and topic selection.
- Demo mode with immediate feedback.
- Assessment mode with timer, save-before-next behavior, mark for review, skip, submit, and auto-submit.
- Complete-group selection and protected sequence for paragraph/table question sets.
- Stable option order for each candidate attempt.
- Passage, question, and option media rendering.
- Final result computation and review data.

Frontend module:
- Auth pages: login, register, verify email, forgot password, reset password.
- App pages: dashboard, assessment, result, terms.
- Shared loader and error summary behavior in `assets/js/api.js`.
- Assessment interactivity in `assets/js/assessment.js`.

Security module:
- `AuthService`, `RateLimiter`, `SecurityLog`, `ErrorLogger`.
- CSRF and protected-page guards.
- Browser cookie-based auth.

Mail module:
- OTP and reset email delivery.
- Provider-driven transport with error logging.

Question bank import procedure:
- Authoring format: one `.xlsx` workbook with a `Question Bank` sheet and an `Instructions` sheet.
- Runtime import format: export the `Question Bank` sheet as UTF-8 CSV.
- Images are optional and supplied in one ZIP alongside the CSV.
- Final sample workbook: `outputs/question_bank_sample_pasted_questions.xlsx`.
- Matching sample image ZIP: `outputs/question_bank_sample_images.zip`.
- Ready-to-run sample CSV: `storage/imports/question_bank_sample_pasted_questions.csv`.
- Upload working CSV files to `storage/imports/` on the server.
- Importer entry point: `scripts/import_question_bank.php`.
- Import service: `app/Services/QuestionBankImporter.php`.

Required CSV columns:
- `Question Code`
- `Subject`
- `Topic`
- `Question Text`
- `Question Type`
- `Correct Option`
- `Mode`

Supported authoring columns:
- `Group Code`, `Passage Text`, `Passage Image`
- `Question Image`
- `Option A Text` / `Option A Image` through option H
- `Explanation`, `Active`, `Difficulty`
- `Marks`, `Negative Marks`, `Scoring Rule`
- `Shuffle Options`, `Ready For Import`, `Notes`

Scoring rules:
- `exact_match`: the selected option set must exactly match `Correct Option` to receive full marks. Any non-empty incorrect or incomplete answer receives the configured negative marks. A blank/skipped response receives `0`.
- `partial_credit`: an exact answer receives full marks. A correct subset containing no incorrect option receives proportional marks. Selecting any incorrect option receives the configured negative marks. A blank/skipped response receives `0`.
- For single-answer questions, use `exact_match`.

Excel entry examples:

| Question Type | Correct Option | Marks | Negative Marks | Scoring Rule | Result example |
| --- | --- | ---: | ---: | --- | --- |
| `single` | `B` | `1` | `0.25` | `exact_match` | B = `1`; A/C/D = `-0.25`; blank = `0` |
| `multi` | `A,B,D` | `3` | `1` | `exact_match` | A+B+D = `3`; any other non-empty set = `-1` |
| `multi` | `A,B,D` | `3` | `1` | `partial_credit` | A+B = `2`; A = `1`; A+B+D = `3`; any set containing C = `-1` |

Recommended Excel field layout:

| Field | Entry format | Current importer |
| --- | --- | --- |
| `Question Code` | Required stable reference such as `MATH-ALG-001` | Yes; creates versions instead of duplicates |
| `Subject` | Name such as `Mathematics` | Yes |
| `Topic` | Name such as `Algebra` | Yes |
| `Group Code` | Same code for consecutive related passage questions | Yes |
| `Passage Text` | Repeated paragraph/reference text | Yes |
| `Passage Image` | Matching filename from image ZIP | Yes |
| `Question Text` | Full question; line breaks are allowed | Yes |
| `Question Image` | Matching filename from image ZIP | Yes |
| `Question Type` | `single` or `multi` | Yes |
| `Option A Text` ... `Option H Text` | Option text; line breaks are allowed | Yes |
| `Option A Image` ... `Option H Image` | Matching filename from image ZIP | Yes |
| `Correct Option` | `B` or comma-separated `A,B,D` | Yes |
| `Explanation` | Answer explanation | Yes |
| `Mode` | `demo` or `assessment` | Yes |
| `Active` | `1` or `0` | Yes |
| `Difficulty` | `easy`, `medium`, or `hard` | Yes |
| `Marks` | Positive decimal, such as `1` or `2.5` | Yes |
| `Negative Marks` | Non-negative decimal, such as `0.25` | Yes |
| `Scoring Rule` | `exact_match` or `partial_credit` | Yes |
| `Shuffle Options` | `Yes` or `No` | Yes; order is locked per attempt |
| `Ready For Import` | `Yes` imports; `No`/`Draft` skips the row | Yes |
| `Notes` | Author/editor notes | Ignored by assessment runtime |

Grouped question rules:
- Leave `Group Code` blank for standalone questions.
- Give every question in a paragraph/table set the same `Group Code`.
- Keep all rows for one group consecutive in the CSV.
- There is intentionally no `Group Order` field.
- Internal group sequence is assigned from consecutive CSV row order.
- A group code cannot disappear and reappear later in the same import.
- A group must contain at least two questions.
- The assessment randomizes standalone questions and complete groups as units.
- Once a group starts, its questions appear consecutively in protected row order.
- A complete group is selected or skipped; it is never cut to fill a remaining slot.

Media rules:
- A question, passage, or option can contain text only, image only, or text plus image.
- Workbook image cells contain filenames, not final public server paths.
- ZIP folders may be nested. A unique basename may be referenced without writing its folders in Excel.
- Duplicate case-insensitive filenames/basenames in one ZIP are rejected to prevent ambiguous mapping.
- Unsafe ZIP paths, absolute paths, `..`, links, unsupported formats, oversized files, excessive compression, and excessive pixel dimensions are rejected.
- Accepted inputs are PNG, JPG/JPEG, and WebP.
- Referenced images are decoded and re-encoded as WebP; original PNG/JPG files are not published.
- Each import receives a unique directory such as `assets/question-media/20260613-.../`.
- Stored filenames include a safe slug and content hash, so later imports cannot overwrite earlier media.
- Unreferenced ZIP images are ignored with a warning.

Import behavior:
- The importer creates missing subjects in `categories`.
- The importer creates missing topics in `topics`.
- New `Question Code` values create version 1.
- Re-importing identical content is recorded as unchanged.
- Changed content with the same `Question Code` retires the old current version and creates the next version.
- The importer inserts options into `question_options`.
- Each run is audited in `question_import_batches`.
- Database changes run inside one transaction; if one ready row fails, the full import rolls back.
- `--dry-run` performs validation and database operations inside a transaction, then rolls back.

Production import steps:
1. Prepare the question bank in Excel or Google Sheets.
2. Export/download the sheet as CSV.
3. Upload the CSV to `~/domains/assessment.netcascade.in/public_html/storage/imports/question_bank.csv`.
4. SSH into the server.
5. Go to the project root:
   `cd ~/domains/assessment.netcascade.in/public_html`
6. Validate a text-only CSV without saving:
   `php scripts/import_question_bank.php storage/imports/question_bank.csv --dry-run`
7. Validate a CSV plus images without saving:
   `php scripts/import_question_bank.php storage/imports/question_bank.csv storage/imports/question-images.zip --dry-run`
8. If the dry-run passes, import for real:
   `php scripts/import_question_bank.php storage/imports/question_bank.csv storage/imports/question-images.zip`
9. Omit the ZIP argument when the ready rows contain no image references.
10. Test the dashboard subject/topic dropdowns and start a demo/assessment.

Important:
- On an existing production database, run the scoring migration first and the grouped/media migration second.
- The server PHP build requires `zip` (`ZipArchive`) and `gd` extensions for image imports.
- Ensure the configured question media directory is writable by PHP.
- Do not manage `category_id`, `topic_id`, or `question_id` manually in CSV.
- Use names in the CSV; the importer resolves IDs internally.
- Preserve `Question Code` when correcting a question so history is versioned correctly.
- Build the future admin/import page on top of this CSV plus optional ZIP service rather than duplicating import logic.

If you are continuing development from this point, treat this document as the canonical project handoff. Update it whenever the architecture, endpoints, schema, or major UI flows change.
