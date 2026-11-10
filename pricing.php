<?php
declare(strict_types=1);

require __DIR__ . '/api/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');

$plans = subscription_plan_catalog();

function pricing_money(int $minor): string
{
    return number_format($minor / 100, 0, ',', ' ') . ' ₽';
}

function pricing_limit(mixed $value): string
{
    return $value === null ? 'без ограничений' : (string)(int)$value;
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Тарифы и цены UROVIA</title>
<meta name="description" content="Актуальные тарифы и фиксированные цены на цифровую подписку UROVIA для учителей и администраторов.">
<meta name="robots" content="index,follow">
<link rel="icon" href="favicon.png?v=3">
<link rel="stylesheet" href="legal.css?v=3">
</head>
<body>
<main class="legal-shell">
<header class="legal-head">
  <div class="legal-brand"><div class="legal-mark">U</div><div><b>UROVIA</b><span>Образовательная платформа</span></div></div>
  <h1>Тарифы и цены UROVIA</h1>
  <p>Ниже указаны реальные фиксированные цены на цифровой доступ к функциям UROVIA. Платная подписка предназначена для учителей и администраторов; ученические аккаунты подписку не покупают.</p>
  <div class="legal-meta"><span>Цены в рублях</span><span>Цифровая подписка</span><span>Оплата через ЮKassa</span></div>
</header>

<article class="legal-card">
<section>
  <h2>Актуальная стоимость</h2>
  <div class="legal-pricing-grid">
    <?php foreach ($plans as $plan): ?>
      <article class="legal-price-card">
        <h3><?=htmlspecialchars((string)$plan['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></h3>
        <?php if ((int)$plan['monthly_price_minor'] === 0): ?>
          <strong>0 ₽</strong>
          <p>Базовый бесплатный доступ без автоматических списаний.</p>
        <?php else: ?>
          <strong><?=pricing_money((int)$plan['monthly_price_minor'])?> / месяц</strong>
          <p><b><?=pricing_money((int)$plan['yearly_price_minor'])?> / год</b>. Цена фиксируется при оформлении заказа.</p>
        <?php endif; ?>
        <ul>
          <li>Классы: <?=pricing_limit($plan['max_classes'])?></li>
          <li>Ученики: <?=pricing_limit($plan['max_students'])?></li>
          <li>Активные задания: <?=pricing_limit($plan['max_active_assignments'])?></li>
          <li>Создание и назначение заданий: да</li>
          <li>Автопроверка и журнал: да</li>
          <li>Библиотека UROVIA: да</li>
          <li>Импорт PDF/DOCX/PPT/PPTX: <?=!empty($plan['features']['file_import']) ? 'да' : 'нет'?></li>
          <li>Расширенная аналитика: <?=!empty($plan['features']['advanced_analytics']) ? 'да' : 'нет'?></li>
          <li>Экспорт результатов: <?=!empty($plan['features']['export_results']) ? 'да' : 'нет'?></li>
          <li>AI-лимит: <?= (int)$plan['ai_monthly_limit'] > 0 ? (int)$plan['ai_monthly_limit'] . ' операций в месяц' : 'не включён' ?></li>
        </ul>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<section>
  <h2>Описание платной услуги</h2>
  <p>Покупатель оплачивает не отдельный файл и не физический товар, а право использовать расширенные функции образовательной платформы UROVIA в своей учетной записи в течение выбранного оплаченного периода.</p>
  <p>PRO предназначен для регулярной работы учителя с несколькими классами и импортом материалов. Premium рассчитан на пользователей, которым нужны расширенные лимиты и увеличенный AI-лимит.</p>
</section>

<section>
  <h2>Как получить услугу после оплаты</h2>
  <p>После успешной оплаты через ЮKassa подписка активируется в аккаунте UROVIA, из которого был оформлен заказ. Физическая доставка отсутствует. Получать письмо с файлом или регистрироваться повторно не нужно.</p>
  <p>Подробный порядок оформления, оплаты, активации и автопродления опубликован на странице <a href="payment-info.php">«Оплата и получение услуги»</a>.</p>
</section>

<section>
  <h2>Оплата, автопродление и возврат</h2>
  <p>Оплата производится через защищённую платёжную страницу ЮKassa. Автопродление подключается только при отдельном согласии пользователя и может быть отключено в личном кабинете UROVIA.</p>
  <p>Правовые условия использования платных функций, отмены и возврата изложены в <a href="offer.php">Публичной оферте</a>.</p>
  <div class="legal-actions">
    <a class="primary" href="payment-info.php">Оплата и получение услуги</a>
    <a class="secondary" href="offer.php">Публичная оферта</a>
    <a class="secondary" href="requisites.php">Реквизиты и контакты</a>
    <a class="secondary" href="login.html">Вход в UROVIA</a>
  </div>
</section>
</article>

<footer class="legal-foot">UROVIA · Тарифы и цены</footer>
</main>
</body>
</html>
