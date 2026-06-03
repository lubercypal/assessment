<?php
require_once __DIR__ . '/app/bootstrap.php';

App\Services\AuthService::requirePage();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Assessment | Assessment Platform</title>
    <link rel="icon" href="assets/img/assessment-loader.svg" type="image/svg+xml">
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
                <div class="brand">Assessment Platform</div>
                <div id="modeLabel" class="muted">Assessment Mode</div>
            </div>
            <div class="timer" id="timer">--:--</div>
        </header>

        <section class="assessment-layout">
            <div class="panel">
                <div class="question-meta">
                    <span id="questionNumber">Question</span>
                    <span>Answers save during navigation</span>
                </div>
                <h1 id="questionText">Loading...</h1>
                <div id="options" class="options"></div>
                <div id="feedback" class="feedback hidden"></div>
                <div class="actions">
                    <button id="previous" type="button" class="secondary">Previous</button>
                    <button id="skip" type="button" class="secondary">Skip</button>
                    <button id="review" type="button" class="warn">Mark for Review</button>
                    <button id="next" type="button">Next</button>
                    <button id="submitTest" type="button" class="warn">Submit Test</button>
                </div>
            </div>
            <aside class="panel">
                <h2>Questions</h2>
                <div id="questionNav" class="nav-grid"></div>
            </aside>
        </section>
    </main>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/assessment.js"></script>
</body>
</html>
