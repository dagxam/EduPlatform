<?php
declare(strict_types=1);

require __DIR__ . '/api/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');

$plans = subscription_plan_catalog();
$profile = site_legal_profile(app_db());

function pi_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pi_money(int $minor): string
{
    return number_format($minor / 100, 0, ',', ' ') . ' ₽';
}

$ownerName = trim((string)($profile['legal_name'] ?? ''));
$ownerInn = trim((string)($profile['inn'] ?? ''));
$ownerPhone = trim((string)($profile['phone'] ?? ''));
$ownerEmail = trim((string)($profile['email'] ?? ''));
$ownerPostal = trim((string)($profile['postal_address'] ?? ''));
$publicReady = $ownerName !== '' && $ownerInn !== '' && $ownerPhone !== '' && $ownerEmail !== '' && $ownerPostal !== '';
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Оплата и получение услуги — UROVIA</title>
<meta name="description" content="Условия покупки подписки UROVIA: описание цифровой услуги, тарифы, порядок оплаты, активации доступа и контакты исполнителя.">
<meta name="robots" content="index,follow">
<link rel="icon" href="favicon.png?v=3">
<link rel="stylesheet" href="legal.css?v=3">
</head>
<body>
<main class="legal-shell">
<header class="legal-head">
  <div class="legal-brand"><div class="legal-mark">U</div><div><b>UROVIA</b><span>Образовательная платформа</span></div></div>
  <h1>Оплата и получение услуги</h1>
  <p>UROVIA продаёт цифровую подписку на дополнительные функции образовательной платформы. Физические товары и доставка не используются.</p>
  <div class="legal-meta"><span>Цифровая услуга</span><span>Оплата через ЮKassa</span><span>Доступ в аккаунте</span></div>
</header>

<article class="legal-card">
<section>
  <h2>1. Что продаёт UROVIA</h2>
  <p>Платная услуга — предоставление учителю или администратору расширенного доступа к функциям UROVIA на выбранный срок. Ученические аккаунты не приобретают подписку.</p>
  <div class="legal-product-list">
    <article>
      <b>PRO</b>
      <span><?=pi_money((int)$plans['pro']['monthly_price_minor'])?> / месяц · <?=pi_money((int)$plans['pro']['yearly_price_minor'])?> / год</span>
      <p>До 10 классов, до 300 учеников, до 100 активных заданий, импорт PDF/DOCX/PPTX, расширенная аналитика, экспорт результатов и AI-лимит 50 операций в месяц.</p>
    </article>
    <article>
      <b>Premium</b>
      <span><?=pi_money((int)$plans['premium']['monthly_price_minor'])?> / месяц · <?=pi_money((int)$plans['premium']['yearly_price_minor'])?> / год</span>
      <p>Классы, ученики и активные задания без тарифного лимита, импорт материалов, расширенная аналитика, экспорт результатов и AI-лимит 300 операций в месяц.</p>
    </article>
  </div>
  <p>Полное сравнение тарифов опубликовано на странице <a href="pricing.php">«Тарифы UROVIA»</a>.</p>
</section>

<section>
  <h2>2. Как оформить заказ и оплатить</h2>
  <ol>
    <li>Пользователь входит в свой аккаунт UROVIA.</li>
    <li>В разделе «Тариф и подписка» выбирает PRO или Premium и период — месяц или год.</li>
    <li>UROVIA создаёт заказ с фиксированной суммой и переводит пользователя на защищённую страницу ЮKassa.</li>
    <li>Пользователь оплачивает доступ одним из способов, доступных в ЮKassa.</li>
    <li>После подтверждения успешного платежа ЮKassa сервер UROVIA активирует оплаченный тариф в аккаунте.</li>
  </ol>
  <div class="legal-good"><b>Банковские данные.</b> Номер карты, CVC и другие реквизиты банковской карты вводятся на стороне ЮKassa и не сохраняются в UROVIA.</div>
</section>

<section>
  <h2>3. Как покупатель получает цифровую услугу</h2>
  <p>Отдельная доставка не требуется. После подтверждения успешной оплаты расширенные возможности становятся доступны в том же аккаунте UROVIA, с которого была оформлена подписка. Повторная регистрация или получение файла по почте не нужны.</p>
  <p>Если подтверждение платежа задержалось, пользователь может вернуться в UROVIA и проверить состояние подписки после обработки уведомления ЮKassa.</p>
</section>

<section>
  <h2>4. Автопродление</h2>
  <p>Автоматическое продление подключается только при отдельном согласии пользователя во время оформления платежа. Если согласие не дано, платёжный способ для последующих списаний не сохраняется. Автопродление можно отключить в разделе «Тариф и подписка»; уже оплаченный период продолжит действовать до своей даты окончания.</p>
</section>

<section>
  <h2>5. Возврат и поддержка</h2>
  <p>Условия возврата, прекращения подписки и использования сервиса опубликованы в <a href="offer.php">Публичной оферте</a>. Для обращения используйте email поддержки: <a href="mailto:<?=pi_h((string)($profile['support_email'] ?: $ownerEmail ?: 'info@urovia.ru'))?>"><?=pi_h((string)($profile['support_email'] ?: $ownerEmail ?: 'info@urovia.ru'))?></a>.</p>
</section>

<section>
  <h2>6. Исполнитель и контакты</h2>
  <?php if ($publicReady): ?>
    <dl class="legal-details">
      <dt>Статус</dt><dd><?=pi_h(($profile['entity_type'] ?? '') === 'self_employed' ? 'Самозанятый' : (($profile['entity_type'] ?? '') === 'ip' ? 'Индивидуальный предприниматель' : 'Юридическое лицо'))?></dd>
      <dt>Ф.И.О. / наименование</dt><dd><?=pi_h($ownerName)?></dd>
      <dt>ИНН</dt><dd><?=pi_h($ownerInn)?></dd>
      <dt>Телефон</dt><dd><?=pi_h($ownerPhone)?></dd>
      <dt>Email</dt><dd><a href="mailto:<?=pi_h($ownerEmail)?>"><?=pi_h($ownerEmail)?></a></dd>
      <dt>Почтовый адрес</dt><dd><?=pi_h($ownerPostal)?></dd>
    </dl>
  <?php else: ?>
    <div class="legal-alert"><b>Реквизиты владельца ещё не заполнены полностью.</b> Перед отправкой анкеты ЮKassa главный администратор UROVIA должен указать реальные Ф.И.О./наименование, ИНН, телефон, почтовый адрес и email.</div>
  <?php endif; ?>
  <div class="legal-actions">
    <a class="primary" href="requisites.php">Реквизиты и контакты</a>
    <a class="secondary" href="pricing.php">Тарифы</a>
    <a class="secondary" href="offer.php">Публичная оферта</a>
    <a class="secondary" href="login.html">Вход в UROVIA</a>
  </div>
</section>
</article>

<footer class="legal-foot">UROVIA · Оплата и получение цифровой услуги</footer>
</main>
</body>
</html>
