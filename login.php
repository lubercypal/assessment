<?php require_once __DIR__ . '/app/bootstrap.php'; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Assessment Platform</title>
    <link rel="icon" href="assets/img/assessment-loader.svg" type="image/svg+xml">
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
                <span class="login-brand-icon"><img src="assets/img/assessment-loader.svg" alt="" aria-hidden="true"></span>
                <span>Assessment Platform</span>
            </div>

            <div class="login-welcome-copy">
                <h1>Welcome back!</h1>
                <p>Sign in to continue to your assessment dashboard.</p>
            </div>

            <div class="login-shield-visual" aria-hidden="true">
                <svg viewBox="0 0 220 220">
                    <path d="M110 18l70 28v55c0 48-28 82-70 101-42-19-70-53-70-101V46l70-28z" fill="none" stroke="rgba(96, 165, 250, 0.45)" stroke-width="3"/>
                    <path d="M110 42l46 18v39c0 34-18 58-46 73-28-15-46-39-46-73V60l46-18z" fill="rgba(37, 99, 235, 0.14)" stroke="rgba(45, 212, 191, 0.28)" stroke-width="2"/>
                    <rect x="78" y="100" width="64" height="50" rx="10" fill="rgba(45, 212, 191, 0.28)" stroke="rgba(147, 197, 253, 0.8)" stroke-width="2"/>
                    <path d="M92 100V82c0-14 8-24 18-24s18 10 18 24v18" fill="none" stroke="rgba(226, 232, 240, 0.9)" stroke-width="8" stroke-linecap="round"/>
                    <circle cx="110" cy="124" r="6" fill="#e2e8f0"/>
                    <path d="M110 130v10" stroke="#e2e8f0" stroke-width="4" stroke-linecap="round"/>
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
                    <div class="field-heading">
                        <label for="loginPassword">Password</label>
                        <a href="forgot-password">Forgot Password?</a>
                    </div>
                    <div class="login-input-wrap">
                        <span class="login-input-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                        </span>
                        <input id="loginPassword" type="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
                        <button type="button" class="password-eye" data-password-toggle="loginPassword" aria-label="Show password">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s4-6 10-6 10 6 10 6-4 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>
                <div id="message" class="message"></div>
                <div id="throttleMessage" class="message error form-alert hidden" role="alert"></div>
                <div class="actions">
                    <button type="submit" class="action-wide">Sign In</button>
                </div>
                <div class="login-divider"><span>or</span></div>
                <div class="auth-links auth-links-grid login-links login-links-three">
                    <a href="register" class="action-link secondary">Register</a>
                    <a href="verify-email" class="action-link secondary">Verify Email</a>
                    <a href="forgot-password" class="action-link secondary">Forgot Password</a>
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
