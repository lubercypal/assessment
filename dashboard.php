<?php
require_once __DIR__ . '/app/bootstrap.php';

App\Services\AuthService::requirePage();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | Assessment Platform</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="app-page">
    <div id="globalLoader" class="loader-overlay hidden" aria-live="polite" aria-busy="true">
        <div class="loader-card">
            <img src="assets/img/assessment-loader.svg" alt="Loading">
            <div class="loader-text" data-loader-text>Working...</div>
            <div class="loader-subtext">Please wait.</div>
        </div>
    </div>
    <main class="page-shell">
        <header class="topbar">
            <div>
                <div class="brand">Assessment Platformssss</div>
                <div class="muted">Welcome, <span id="studentName">Student</span></div>
            </div>
            <button id="logout" class="secondary" type="button">Logout</button>
        </header>

        <section class="panel">
            <h1>Dashboard</h1>
            <p>Select a practice demo or start the formal assessment after reading the rules.</p>
            <div class="tiles">
                <form id="demoForm" class="tile stack" data-message="#demoMessage">
                    <h2>Take a Demo</h2>
                    <p>Sample questions with immediate answer feedback and explanations.</p>
                    <label class="field"><span>Subject</span><select name="category_id" required></select></label>
                    <label class="field"><span>Topic</span><select name="topic_id"><option value="">Any topic</option></select></label>
                    <div id="demoMessage" class="message"></div>
                    <button type="submit" class="action-wide">Take the Demo</button>
                </form>

                <form id="assessmentForm" class="tile stack" data-message="#assessmentMessage">
                    <h2>Take the Test</h2>
                    <label class="field"><span>Subject</span><select name="category_id" required></select></label>
                    <label class="field"><span>Topic</span><select name="topic_id"><option value="">Any topic</option></select></label>
                    <p>Questions are randomized. Answers are saved before navigation. The assessment auto-submits when the timer ends.</p>
                    <label class="check"><input type="checkbox" name="confirm_rules" required> I confirm that I have read the instructions and assessment rules.</label>
                    <div id="assessmentMessage" class="message"></div>
                    <button type="submit" class="action-wide">Start Assessment</button>
                </form>
            </div>
        </section>
    </main>
    <div id="sessionModal" class="session-modal hidden" aria-hidden="true">
        <div class="session-modal__shell">
            <header class="session-modal__bar">
                <div>
                    <div id="sessionTitle" class="brand">Assessment Session</div>
                    <div id="sessionSubtitle" class="muted">Use mouse clicks only.</div>
                </div>
                <button id="closeSession" type="button" class="secondary">Exit</button>
            </header>
            <iframe id="sessionFrame" title="Assessment session" class="session-modal__frame" src="about:blank"></iframe>
        </div>
    </div>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/dashboard.js"></script>
</body>
</html>
