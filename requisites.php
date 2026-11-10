<?php
declare(strict_types=1);

require __DIR__ . '/api/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');

$p = site_legal_profile(app_db());

function req_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$typeCode = (string)($p['entity_type'] ?? 'self_employed');
$typeLabel = match ($typeCode) {
    'ip' => 'Индивидуальный предприниматель',
    'company' => 'Юридическое лицо',
    default => 'Самозанятый',
};

$required = [
    'legal_name' => trim((string)($p['legal_name'] ?? '')),
    'inn' => trim((string)($p['inn'] ?? '')),
    'phone' => trim((string)($p['phone'] ?? '')),
    'email' => trim((string)($p['email'] ?? '')),
    'postal_address' => trim((string)($p['postal_address'] ?? '')),
];
if ($typeCode === 'ip' || $typeCode === 'company') {
    $required['ogrn'] = trim((string)($p['ogrn'] ?? ''));
}
$profileComplete = !in_array('', $required, true);
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Реквизиты и контакты — UROVIA</title>
<meta name="description" content="Публичные реквизиты и контакты владельца образовательной платформы UROVIA.">
<meta name="robots" content="index,follow">
<link rel="icon" href="favicon.png?v=3">
<link rel="stylesheet" href="legal.css?v=3">
</head>
<body>
<main class="legal-shell">
<header class="legal-head">
  <div class="legal-brand"><div class="legal-mark">U</div><div><b>UROVIA</b><span>Образовательная платформа</span></div></div>
  <h1>Реквизиты и контакты</h1>
  <p>Публичные сведения о владельце сервиса UROVIA для пользователей и платёжных партнёров.</p>
  <div class="legal-meta"><span><?=req_h($typeLabel)?></span><span>urovia.ru</span><span>Поддержка пользователей</span></div>
</header>

<article class="legal-card">
<section>
  <h2>Владелец сервиса</h2>
  <dl class="legal-details">
    <dt>Статус</dt><dd><?=req_h($typeLabel)?></dd>
    <dt><?= $typeCode === 'self_employed' ? 'Ф.И.О. самозанятого' : 'Ф.И.О. / наименование' ?></dt>
    <dd><?=req_h($p['legal_name'] ?: 'Не заполнено владельцем сервиса')?></dd>
    <dt><?= $typeCode === 'self_employed' ? 'ИНН самозанятого' : 'ИНН' ?></dt>
    <dd><?=req_h($p['inn'] ?: 'Не заполнено владельцем сервиса')?></dd>
    <?php if ($typeCode === 'ip' || $typeCode === 'company'): ?>
      <dt><?= $typeCode === 'ip' ? 'ОГРНИП' : 'ОГРН' ?></dt>
      <dd><?=req_h($p['ogrn'] ?: 'Не заполнено владельцем сервиса')?></dd>
    <?php endif; ?>
    <?php if (!empty($p['legal_address'])): ?>
      <dt>Фактический / юридический адрес</dt><dd><?=req_h($p['legal_address'])?></dd>
    <?php endif; ?>
    <dt>Почтовый адрес</dt><dd><?=req_h($p['postal_address'] ?: 'Не заполнено владельцем сервиса')?></dd>
    <dt>Телефон</dt><dd><?=req_h($p['phone'] ?: 'Не заполнено владельцем сервиса')?></dd>
    <dt>Email</dt><dd><a href="mailto:<?=req_h($p['email'] ?: 'info@urovia.ru')?>"><?=req_h($p['email'] ?: 'info@urovia.ru')?></a></dd>
  </dl>

  <?php if (!$profileComplete): ?>
    <div class="legal-alert">
      <b>Страница ещё не готова к отправке на проверку ЮKassa.</b>
      Не заполнены все обязательные публичные сведения. Главному администратору нужно открыть «Тариф и подписка → ЮKassa · модерация сайта» и указать реальные Ф.И.О./наименование, ИНН, телефон, почтовый адрес и email<?php if ($typeCode !== 'self_employed'): ?>, а также ОГРН/ОГРНИП<?php endif; ?>.
    </div>
  <?php else: ?>
    <div class="legal-good"><b>Реквизиты опубликованы.</b> Эта страница содержит контактные и идентификационные сведения владельца UROVIA и может использоваться как ссылка на реквизиты при подключении ЮKassa.</div>
  <?php endif; ?>
</section>

<section>
  <h2>Поддержка</h2>
  <p>По вопросам работы платформы, подписки, оплаты и возврата: <a href="mailto:<?=req_h($p['support_email'] ?: $p['email'] ?: 'info@urovia.ru')?>"><?=req_h($p['support_email'] ?: $p['email'] ?: 'info@urovia.ru')?></a>.</p>
  <?php if (!empty($p['phone'])): ?><p>Контактный телефон: <b><?=req_h($p['phone'])?></b>.</p><?php endif; ?>
</section>

<section>
  <h2>Что продаётся на сайте</h2>
  <p>UROVIA предоставляет цифровой доступ к дополнительным функциям образовательной платформы по подписке. Фиксированные цены, описание тарифов и порядок получения услуги опубликованы на отдельных публичных страницах.</p>
  <div class="legal-actions">
    <a class="primary" href="pricing.php">Тарифы и цены</a>
    <a class="secondary" href="payment-info.php">Оплата и получение услуги</a>
    <a class="secondary" href="offer.php">Публичная оферта</a>
    <a class="secondary" href="privacy-policy.html">Политика данных</a>
  </div>
</section>
</article>

<footer class="legal-foot">UROVIA · Реквизиты и контакты · urovia.ru</footer>
</main>
</body>
</html>
