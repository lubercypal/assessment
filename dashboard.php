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
    <main class="page-shell">
        <header class="topbar">
            <div>
                <div class="brand">Assessment Platform</div>
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
                    <button type="submit">Start Demo</button>
                </form>

                <form id="assessmentForm" class="tile stack" data-message="#assessmentMessage">
                    <h2>Take the Test</h2>
                    <label class="field"><span>Subject</span><select name="category_id" required></select></label>
                    <label class="field"><span>Topic</span><select name="topic_id"><option value="">Any topic</option></select></label>
                    <p>Questions are randomized. Answers are saved before navigation. The assessment auto-submits when the timer ends.</p>
                    <label class="check"><input type="checkbox" name="confirm_rules" required> I confirm that I have read the instructions and assessment rules.</label>
                    <div id="assessmentMessage" class="message"></div>
                    <button type="submit">Start Assessment</button>
                </form>
            </div>
        </section>
    </main>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/dashboard.js"></script>
</body>
</html>
