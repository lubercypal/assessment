<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

$arguments = array_values(array_slice($argv, 1));
$dryRun = in_array('--dry-run', $arguments, true);
$arguments = array_values(array_filter($arguments, fn (string $argument): bool => $argument !== '--dry-run'));
$csvPath = $arguments[0] ?? '';
$zipPath = $arguments[1] ?? null;

if ($csvPath === '' || in_array($csvPath, ['-h', '--help'], true)) {
    fwrite(
        STDERR,
        "Usage: php scripts/import_question_bank.php path/to/question_bank.csv [path/to/images.zip] [--dry-run]\n"
    );
    exit($csvPath === '' ? 1 : 0);
}

$resolvePath = static function (string $path) use ($root): string {
    return str_starts_with($path, '/') ? $path : $root . '/' . $path;
};

try {
    $importer = new App\Services\QuestionBankImporter(db(), app_config(), $root);
    $summary = $importer->import(
        $resolvePath($csvPath),
        $zipPath ? $resolvePath($zipPath) : null,
        $dryRun
    );

    echo $dryRun
        ? "Dry run passed. No database or media changes were saved.\n"
        : "Question bank import completed.\n";

    foreach ($summary as $label => $value) {
        if ($label === 'warnings') {
            continue;
        }
        echo ucwords(str_replace('_', ' ', $label)) . ': ' . (is_bool($value) ? ($value ? 'yes' : 'no') : $value) . PHP_EOL;
    }
    foreach ($summary['warnings'] as $warning) {
        echo "Warning: {$warning}\n";
    }
} catch (Throwable $exception) {
    App\Services\ErrorLogger::exception($exception, [
        'command' => 'question-bank-import',
        'csv' => $csvPath,
        'zip' => $zipPath,
        'dry_run' => $dryRun,
    ]);
    fwrite(STDERR, 'Import failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
