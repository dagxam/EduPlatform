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

// Free AI via Groq for every staff plan.
growth_expect($bootstrap, 'https://api.groq.com/openai/v1', 'Groq API base is missing');
growth_expect($bootstrap, "getenv('GROQ_API_KEY')", 'Groq environment key support is missing');
growth_expect($bootstrap, "openai/gpt-oss-20b", 'default free-tier Groq model is missing');
growth_expect($bootstrap, 'function ai_request(array $payload)', 'AI provider request helper is missing');
growth_expect($bootstrap, 'function subscription_record_ai_generation', 'AI generation usage accounting is missing');
growth_reject($bootstrap, 'function subscription_assert_ai_capacity', 'old paid AI subscription guard still exists');
growth_expect($aiGenerate, 'question_editor_normalize_payload($candidate)', 'AI questions do not pass through the existing question normalizer');
growth_reject($aiGenerate, 'subscription_assert_ai_capacity', 'AI generation is still subscription-gated');
growth_expect($aiGenerate, "'ai_assignment_generated'", 'AI assignment audit event is missing');
growth_expect($aiGenerate, '"draft", "draft"', 'AI generation must create a normal draft');
growth_expect($aiStatus, "'available' => true", 'AI is not available on every staff plan');
growth_expect($aiStatus, "'free_access' => true", 'free AI access flag is missing');
growth_expect($aiAdminGet, "'provider' => 'groq'", 'AI admin endpoint is not configured for Groq');
growth_expect($aiAdminSet, "'provider' => 'groq'", 'AI configuration does not persist Groq provider');
growth_expect($index, 'API-ключ Groq', 'Groq admin UI label is missing');
growth_expect($index, 'доступно на всех тарифах UROVIA', 'free AI tariff copy is missing');
growth_expect($app, 'Бесплатный ИИ через Groq', 'free AI status copy is missing');

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
growth_expect($index, 'app.js?v=101', 'growth feature app asset is not cache-busted');
growth_expect($index, 'styles.css?v=101', 'growth feature styles are not cache-busted');

echo "Teacher growth features smoke OK\n";
