<?php
require_once __DIR__ . '/app/bootstrap.php';

$session = App\Services\AuthService::requirePage();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | Assessment Platform</title>
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
    <main id="dashboardShell" class="page-shell">
        <header class="topbar">
            <div>
                <div class="brand">Assessment Platform</div>
                <div class="muted">Welcome, <span id="studentName"><?= htmlspecialchars($session['full_name'] ?? 'Student', ENT_QUOTES, 'UTF-8') ?></span></div>
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
            <div id="sessionMount" class="session-modal__mount"></div>
        </div>
    </div>
    <div id="sessionKeyboardWarning" class="keyboard-warning hidden">Keyboard input is disabled here. Please use mouse clicks only.</div>
    <template id="assessmentSessionTemplate">
        <main class="page-shell session-shell">
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
    </template>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/assessment.js"></script>
    <script src="assets/js/dashboard.js"></script>
</body>
</html>
