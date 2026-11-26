<?php
declare(strict_types=1);

function growth_source(string $path): string {
    $content = file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "Teacher growth smoke failed: cannot read {$path}.\n");
        exit(1);
    }
    return $content;
}

function growth_expect(string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Teacher growth smoke failed: {$label}.\n");
        exit(1);
    }
}

function growth_reject(string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Teacher growth smoke failed: {$label}.\n");
        exit(1);
    }
}

$bootstrap = growth_source('api/bootstrap.php');
$aiGenerate = growth_source('api/ai/generate-assignment.php');
$aiStatus = growth_source('api/ai/status.php');
$aiAdminGet = growth_source('api/admin/ai/get.php');
$aiAdminSet = growth_source('api/admin/ai/set.php');
$libraryRequest = growth_source('api/library/request.php');
$libraryList = growth_source('api/library/list.php');
$libraryImport = growth_source('api/library/import.php');
$libraryPreview = growth_source('api/library/preview.php');
$schema = growth_source('database/schema.sql');
$app = growth_source('app.js');
$index = growth_source('index.html');

// AI is temporarily suspended; keep provider implementation but never expose or invoke it.
growth_expect($bootstrap, 'function ai_feature_enabled(): bool { return false; }', 'AI must be suspended');
growth_expect($aiGenerate, 'ai_require_enabled();', 'generation endpoint must be disabled');
growth_expect($aiAdminGet, 'ai_require_enabled();', 'AI admin settings must be disabled');
growth_expect($aiAdminSet, 'ai_require_enabled();', 'AI configuration writes must be disabled');
growth_expect(growth_source('api/admin/ai/test.php'), 'ai_require_enabled();', 'AI diagnostics must be disabled');
growth_expect($aiStatus, '$available = ai_feature_enabled()', 'AI status must report unavailable');
growth_expect(growth_source('api/paper/ocr.php'), '$enabled=ai_feature_enabled()', 'external OCR must be disabled');
foreach (['openAiTaskBtn','aiTaskModal','aiConfigForm','aiRunDiagnostic'] as $id) growth_reject($index,$id,'AI interface is visible');
growth_reject($app, 'ai_monthly_limit', 'AI advertised in tariff UI');
growth_reject(growth_source('pricing.php'),'ai_monthly_limit','AI advertised on public pricing');
foreach (['newPaperAi','navPaperAi','paperAiDialog'] as $id) growth_reject(growth_source('paper-workspace.html'),$id,'PAPER AI interface is visible');
growth_reject(growth_source('paper-workspace.js'),'api/paper/ocr.php','external OCR frontend still active');

// Work-on-mistakes feature must remain removed.
if (is_file('api/results/create-remediation.php')) {
    fwrite(STDERR, "Teacher growth smoke failed: remediation API still exists.\n");
    exit(1);
}
growth_reject($app, 'data-review-remediation', 'remediation UI still exists');
growth_reject($app, 'Работа над ошибками', 'remediation copy still exists');

// Marketplace stage 1 remains unchanged.
growth_expect($schema, "sale_mode TEXT NOT NULL DEFAULT 'free'", 'marketplace sale mode schema is missing');
growth_expect($schema, 'price_minor INTEGER NOT NULL DEFAULT 0', 'marketplace price schema is missing');
growth_expect($libraryRequest, "'marketplace_sell'", 'paid material publishing is not subscription-gated');
growth_expect($libraryList, "'can_sell' => \$canSell", 'library API does not expose marketplace sell permission');
growth_expect($libraryImport, "'MARKETPLACE_PURCHASE_REQUIRED'", 'paid materials can still be copied for free');
growth_expect($libraryPreview, "'price_minor'", 'library preview does not expose price');
growth_expect($app, 'marketplace-purchase-soon', 'paid marketplace state is not rendered');
growth_expect($index, 'app.js?v=', 'growth feature app asset is not cache-busted');
growth_expect($index, 'styles.css?v=', 'growth feature styles are not cache-busted');

echo "Teacher growth features smoke OK\n";
