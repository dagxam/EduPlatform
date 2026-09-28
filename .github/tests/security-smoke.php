<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

function security_assert(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, "Security smoke failed: {$message}\n");
    exit(1);
}

function security_read(string $path): string
{
    $content = file_get_contents($path);
    security_assert(is_string($content), 'cannot read ' . $path);
    return $content;
}

$attemptQuestions = security_read($root . '/api/attempts/questions.php');
security_assert(
    str_contains($attemptQuestions, "(string)\$attempt['status'] !== 'in_progress'"),
    'students must not retrieve completed test questions'
);

$resultReview = security_read($root . '/api/results/review.php');
security_assert(
    str_contains($resultReview, "require_user(['admin', 'teacher'])")
        && !str_contains($resultReview, "require_user(['admin', 'teacher', 'student'])"),
    'completed answer review must remain staff-only'
);

$bootstrap = security_read($root . '/api/bootstrap.php');
security_assert(
    !str_contains($bootstrap, "HTTP_USER_AGENT") || !preg_match(
        '/function\s+throttle_key[\s\S]*?HTTP_USER_AGENT[\s\S]*?\}/',
        $bootstrap
    ),
    'login throttle must not be bypassable by rotating User-Agent'
);

$health = security_read($root . '/api/health.php');
security_assert(
    !str_contains($health, 'PHP_VERSION'),
    'public health endpoint must not disclose exact PHP version'
);

$resetRequest = security_read($root . '/api/auth/request-password-reset.php');
security_assert(
    str_contains($resetRequest, "/login.html#reset="),
    'password reset token must be placed in URL fragment'
);

$importFile = security_read($root . '/api/assignments/import-file.php');
security_assert(
    str_contains($importFile, '$signatureValid = match ($extension)'),
    'assignment uploads must validate real file signatures'
);

$importParser = security_read($root . '/api/assignments/_import_parser.php');
security_assert(
    str_contains($importParser, '@gzuncompress((string)$stream, 8 * 1024 * 1024)')
        && str_contains($importParser, '$inflatedBytes'),
    'PDF decompression must be bounded'
);

$htaccess = security_read($root . '/.htaccess');
security_assert(
    str_contains($htaccess, 'Content-Security-Policy')
        && str_contains($htaccess, 'X-Frame-Options')
        && str_contains($htaccess, 'X-Content-Type-Options'),
    'browser hardening headers must remain configured'
);

echo "UROVIA security smoke OK\n";
