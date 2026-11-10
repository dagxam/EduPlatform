<?php
declare(strict_types=1);
require __DIR__ . '/api/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
$p = site_legal_profile(app_db());
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$type = match ((string)($p['entity_type'] ?? '')) {
    'ip' => 'Индивидуальный предприниматель',
    'company' => 'Юридическое лицо',
    default => 'Самозанятый',
};
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Реквизиты и контакты — UROVIA</title>
<meta name="robots" content="index,follow">
<link rel="icon" href="favicon.png?v=3"><link rel="stylesheet" href="legal.css?v=2">
</head><body><main class="legal-shell">
<header class="legal-head"><div class="legal-brand"><div class="legal-mark">U</div><div><b>UROVIA</b><span>Образовательная платформа</span></div></div>
<h1>Реквизиты и контакты</h1><p>Сведения о владельце сервиса UROVIA и контакты для пользователей.</p></header>
<article class="legal-card">
<section><h2>Владелец сервиса</h2>
<dl class="legal-details">
<dt>Статус</dt><dd><?=h($type)?></dd>
<dt>Наименование / Ф.И.О.</dt><dd><?=h($p['legal_name'] ?: 'Не заполнено владельцем сервиса')?></dd>
<dt>ИНН</dt><dd><?=h($p['inn'] ?: 'Не заполнено владельцем сервиса')?></dd>
<?php if (!empty($p['ogrn'])): ?><dt>ОГРН / ОГРНИП</dt><dd><?=h($p['ogrn'])?></dd><?php endif; ?>
<?php if (!empty($p['legal_address'])): ?><dt>Адрес</dt><dd><?=h($p['legal_address'])?></dd><?php endif; ?>
<?php if (!empty($p['postal_address'])): ?><dt>Почтовый адрес</dt><dd><?=h($p['postal_address'])?></dd><?php endif; ?>
<dt>Email</dt><dd><a href="mailto:<?=h($p['email'] ?: 'info@urovia.ru')?>"><?=h($p['email'] ?: 'info@urovia.ru')?></a></dd>
<?php if (!empty($p['phone'])): ?><dt>Телефон</dt><dd><?=h($p['phone'])?></dd><?php endif; ?>
</dl>
<?php if (empty($p['legal_name']) || empty($p['inn'])): ?><div class="legal-alert"><b>Важно:</b> реквизиты владельца ещё не заполнены в панели главного администратора. До отправки сайта на проверку ЮKassa заполните Ф.И.О./наименование и ИНН.</div><?php endif; ?>
</section>
<section><h2>Поддержка</h2><p>По вопросам работы платформы и подписки: <a href="mailto:<?=h($p['support_email'] ?: 'info@urovia.ru')?>"><?=h($p['support_email'] ?: 'info@urovia.ru')?></a>.</p></section>
<section><h2>Документы</h2><div class="legal-actions"><a class="primary" href="offer.php">Публичная оферта</a><a class="secondary" href="pricing.php">Тарифы</a><a class="secondary" href="privacy-policy.html">Политика данных</a></div></section>
</article><footer class="legal-foot">UROVIA · urovia.ru</footer></main></body></html>