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

$bootstrap = growth_source('api/bootstrap.php');
$aiGenerate = growth_source('api/ai/generate-assignment.php');
$aiStatus = growth_source('api/ai/status.php');
$aiAdminGet = growth_source('api/admin/ai/get.php');
$aiAdminSet = growth_source('api/admin/ai/set.php');
$remediation = growth_source('api/results/create-remediation.php');
$libraryRequest = growth_source('api/library/request.php');
$libraryList = growth_source('api/library/list.php');
$libraryImport = growth_source('api/library/import.php');
$libraryPreview = growth_source('api/library/preview.php');
$schema = growth_source('database/schema.sql');
$app = growth_source('app.js');
$index = growth_source('index.html');

// AI assignment creation stays isolated behind existing subscription limits.
growth_expect($bootstrap, 'function ai_config_path()', 'AI configuration storage is missing');
growth_expect($bootstrap, 'function ai_request(array $payload)', 'AI provider request helper is missing');
growth_expect($bootstrap, "'/responses'", 'Responses API endpoint is missing');
growth_expect($bootstrap, 'function subscription_assert_ai_capacity', 'AI subscription capacity guard is missing');
growth_expect($bootstrap, 'function subscription_record_ai_generation', 'AI monthly usage accounting is missing');
growth_expect($aiGenerate, 'subscription_assert_ai_capacity($pdo, $user, $schoolId)', 'AI generation is not subscription-gated');
growth_expect($aiGenerate, 'question_editor_normalize_payload($candidate)', 'AI questions do not pass through the existing question normalizer');
growth_expect($aiGenerate, "'ai_assignment_generated'", 'AI assignment audit event is missing');
growth_expect($aiGenerate, '"draft", "draft"', 'AI generation must create a normal draft');
growth_expect($aiGenerate, "['single', 'multiple', 'true_false', 'text', 'number']", 'AI generation is not restricted to supported auto-checkable types');
growth_expect($aiStatus, "'remaining' =>", 'AI usage status is missing');
growth_expect($aiAdminGet, "'api_key_configured'", 'AI admin status must not expose the key');
growth_expect($aiAdminSet, "ai_config_path()", 'AI admin configuration is not stored in private storage');

// Work on mistakes must create an independent draft and never alter the original result.
growth_expect($remediation, "'advanced_analytics'", 'remediation is not gated to the existing paid analytics feature');
growth_expect($remediation, "'NO_REMEDIATION_QUESTIONS'", 'remediation empty-state guard is missing');
growth_expect($remediation, "'remediation_assignment_created'", 'remediation audit event is missing');
growth_expect($remediation, 'INSERT INTO assignments', 'remediation draft creation is missing');
growth_expect($remediation, 'SELECT * FROM question_options', 'remediation does not copy answer options');
growth_expect($remediation, 'SELECT * FROM question_assets', 'remediation does not copy question assets');
growth_expect($remediation, 'material_copy_private_file(', 'remediation private asset copy is missing');

// Marketplace stage 1: price metadata + paid-copy protection, without touching subscription checkout.
growth_expect($schema, "sale_mode TEXT NOT NULL DEFAULT 'free'", 'marketplace sale mode schema is missing');
growth_expect($schema, 'price_minor INTEGER NOT NULL DEFAULT 0', 'marketplace price schema is missing');
growth_expect($bootstrap, "mysql_add_column_if_missing(\$pdo, 'library_items', 'sale_mode'", 'MySQL marketplace migration is missing');
growth_expect($bootstrap, "add_column_if_missing(\$pdo, 'library_items', 'sale_mode'", 'SQLite marketplace migration is missing');
growth_expect($libraryRequest, "'marketplace_sell'", 'paid material publishing is not subscription-gated');
growth_expect($libraryRequest, "'sale_mode' => \$saleMode", 'library publication does not persist sale mode');
growth_expect($libraryRequest, "'price_minor' => \$priceMinor", 'library publication does not persist price');
growth_expect($libraryList, "'can_sell' => \$canSell", 'library API does not expose marketplace sell permission');
growth_expect($libraryImport, "'MARKETPLACE_PURCHASE_REQUIRED'", 'paid materials can still be copied for free');
growth_expect($libraryPreview, "'price_minor'", 'library preview does not expose price');

// UI wiring for only the three selected growth features.
growth_expect($index, 'id="aiTaskModal"', 'AI creator modal is missing');
growth_expect($index, 'id="aiConfigForm"', 'AI admin configuration UI is missing');
growth_expect($index, 'id="libraryPublishModal"', 'marketplace publication modal is missing');
growth_expect($app, './api/ai/generate-assignment.php', 'AI creator UI is not connected');
growth_expect($app, './api/results/create-remediation.php', 'work-on-mistakes UI is not connected');
growth_expect($app, 'marketplace-purchase-soon', 'paid marketplace state is not rendered');
growth_expect($app, 'libraryPermissionsLoaded', 'marketplace permission preload regression guard is missing');
growth_expect($index, 'app.js?v=100', 'growth feature app asset is not cache-busted');
growth_expect($index, 'styles.css?v=100', 'growth feature styles are not cache-busted');

echo "Teacher growth features smoke OK\n";
