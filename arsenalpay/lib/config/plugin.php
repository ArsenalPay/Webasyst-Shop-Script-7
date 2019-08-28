<?php
return array(
  'name' => 'ArsenalPay',
  'description' => /*_wp*/('Платежная система <a href="https://www.arsenalpay.ru">ArsenalPay</a>. Оплата с карт Visa/MasterCard/МИР'),
  // other optional parameters 
  'icon' => 'img/arsenalpay16.png', // 16x16px large icon (to be displayed in the Installer) 
  // default payment gateway logo
  'logo' => 'img/arsenalpay.png', 
  // plugin vendor ID (for 3rd parties vendors it's a number)
  'vendor' => 1032647,
  // plugin version
  'version' => '1.0.5',
  'locale' => array('ru_RU', ),
  'type' => waPayment::TYPE_ONLINE,
);

