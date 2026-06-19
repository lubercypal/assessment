<?php require_once __DIR__ . '/app/bootstrap.php'; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Assessment Platform</title>
    <link rel="icon" href="assets/img/assessment-shield.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-page login-page">
    <div id="globalLoader" class="loader-overlay hidden" aria-live="polite" aria-busy="true" hidden>
        <div class="loader-card">
            <img src="assets/img/assessment-loader.svg" alt="Loading">
            <div class="loader-text" data-loader-text>Working...</div>
            <div class="loader-subtext">Please wait.</div>
        </div>
    </div>
    <main class="login-shell" aria-label="Assessment login">
        <section class="login-brand-panel">
            <div class="login-brand-lockup">
                <span class="login-brand-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6l7-3z"/>
                        <path d="M9 12l2 2 4-5"/>
                    </svg>
                </span>
                <span>Assessment Platform</span>
            </div>

            <div class="login-welcome-copy">
                <h1>Welcome back!</h1>
                <p>Sign in to continue to your assessment dashboard.</p>
            </div>

            <div class="login-shield-visual" aria-hidden="true">
                <svg viewBox="0 0 220 220">
                    <g class="shield-lines">
                        <path d="M14 82h42"/>
                        <path d="M164 82h42"/>
                        <path d="M20 118h40"/>
                        <path d="M160 118h40"/>
                        <circle cx="58" cy="82" r="3"/>
                        <circle cx="162" cy="82" r="3"/>
                        <circle cx="62" cy="118" r="3"/>
                        <circle cx="158" cy="118" r="3"/>
                    </g>
                    <path class="shield-outer" d="M110 20l70 30v50c0 49-28 83-70 101-42-18-70-52-70-101V50l70-30z"/>
                    <path class="shield-inner" d="M110 48l44 19v33c0 31-17 54-44 68-27-14-44-37-44-68V67l44-19z"/>
                    <rect class="shield-lock-body" x="77" y="104" width="66" height="50" rx="11"/>
                    <path class="shield-lock-arc" d="M91 104V84c0-15 8-25 19-25s19 10 19 25v20"/>
                    <circle class="shield-key" cx="110" cy="128" r="6"/>
                    <path class="shield-key" d="M110 134v11"/>
                </svg>
            </div>

            <div class="login-trust-grid" aria-label="Portal trust highlights">
                <div>
                    <span class="login-trust-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6l7-3z"/><path d="M9 12l2 2 4-5"/></svg>
                    </span>
                    <strong>Secure &amp; Reliable</strong>
                    <p>Enterprise-grade access for assessments.</p>
                </div>
                <div>
                    <span class="login-trust-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6l7-3z"/><path d="M8 12h8"/><path d="M12 8v8"/></svg>
                    </span>
                    <strong>Fair &amp; Trusted</strong>
                    <p>Built to support verified candidate access.</p>
                </div>
                <div>
                    <span class="login-trust-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M4 6h16v12H4z"/><path d="M8 10h8"/><path d="M8 14h5"/></svg>
                    </span>
                    <strong>Always Available</strong>
                    <p>Continue securely from any supported browser.</p>
                </div>
            </div>

            <p class="login-copyright">&copy; <?php echo date('Y'); ?> Assessment Platform. All rights reserved.</p>
        </section>

        <section class="login-card panel">
            <div class="login-card__header">
                <h2>Secure Candidate Login</h2>
                <p>Use your registered email to sign in.</p>
            </div>
            <form id="loginForm" class="stack">
                <div class="field login-field">
                    <label for="loginEmail">Email address</label>
                    <div class="login-input-wrap">
                        <span class="login-input-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M4 6h16v12H4z"/><path d="M4 7l8 6 8-6"/></svg>
                        </span>
                        <input id="loginEmail" type="email" name="email" required autocomplete="email" placeholder="Enter your email address">
                    </div>
                </div>
                <div class="field login-field">
                    <label for="loginPassword">Password</label>
                    <div class="login-input-wrap">
                        <span class="login-input-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                        </span>
                        <input id="loginPassword" type="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
                        <button type="button" class="password-eye" data-password-toggle="loginPassword" aria-label="Show password">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s4-6 10-6 10 6 10 6-4 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <a class="login-forgot-link" href="forgot-password">Forgot Password?</a>
                </div>
                <div id="message" class="message"></div>
                <div id="throttleMessage" class="message error form-alert hidden" role="alert"></div>
                <div class="actions">
                    <button type="submit" class="action-wide">Sign In</button>
                </div>
                <div class="login-divider"><span>or</span></div>
                <div class="auth-links auth-links-grid login-links login-links-two">
                    <a href="register" class="action-link secondary">Register</a>
                    <a href="verify-email" class="action-link secondary">Verify Email</a>
                </div>
                <p class="login-data-note">
                    <span aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6l7-3z"/><path d="M9 12l2 2 4-5"/></svg>
                    </span>
                    Your data is protected and will never be shared.
                </p>
            </form>
        </section>
    </main>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/auth.js"></script>
</body>
</html>
