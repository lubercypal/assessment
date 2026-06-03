<?php
require_once __DIR__ . '/app/bootstrap.php';

App\Services\AuthService::requirePage();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Result | Assessment Platform</title>
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
            <div class="brand">Assessment Result</div>
            <a id="resultBackLink" href="dashboard">Back to Dashboard</a>
        </header>
        <section class="panel stack">
            <h1>Final Submission</h1>
            <div id="summary"></div>
            <div id="responses"></div>
        </section>
    </main>
    <script src="assets/js/api.js"></script>
    <script src="assets/js/result.js"></script>
</body>
</html>
