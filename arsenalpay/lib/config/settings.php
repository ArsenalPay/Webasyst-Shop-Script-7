<?php 
return array(
	'merchant_token' => array(
		'value' => '',
		'title' => /*_wp*/('Уникальный токен'),
		'description' => /*_wp*/('Уникальный токен, который присваивается ТСП для работы с фреймом'),
		'control_type' => waHtmlControl::INPUT,
	),
	'secret_key' => array(
		'value' => '',
		'title' => /*_wp*/('Секретный ключ'),
		'description' => /*_wp*/('Секретный ключ для проверки подписи запросов'),
		'control_type' => waHtmlControl::INPUT,
	),
	'gateway' => array(
		'value' => 'card',
		'title' => /*_wp*/('Форма оплаты'),
		'description' => /*_wp*/('Выберите форму оплаты, которая должна открываться при переходе пользователя на платежную страницу ArsenalPay'),
		'control_type' => waHtmlControl::SELECT,
		'options' => array(
			array('title' => /*_wp*/('форма для оплаты с банковской карты'), 'value' => 'card'),
			array('title' => /*_wp*/('форма для оплаты с баланса мобильного'), 'value' => 'mk'),
			array('title' => /*_wp*/('форма для оплаты с электронного кошелька'), 'value' => 'wallet'),
		),
	),
	'other_code' => array(
		'value' => '',
		'title' => /*_wp*/('Другой код'),
		'description' => /*_wp*/('Дополнительный номер или код, необходимый для оплаты'),
		'control_type' => waHtmlControl::INPUT,
	),
	'css' => array(
		'value' => '',
		'title' => /*_wp*/('Ссылка на css-файл'),
		'description' => /*_wp*/('Адрес до CSS файла с кастомизацией стилей'),
		'control_type' => waHtmlControl::INPUT,
	),
	'frame_mode' => array(
		'value' => '1',
		'title' => /*_wp*/('Режим отображения платежной страницы ArsenalPay'),
		'description' => /*_wp*/('Выберите режим отображения платежной страницы'),
		'control_type' => waHtmlControl::SELECT,
		'options' => array(
			array('title' => /*_wp*/('во фрейме'), 'value' => '1'),
			array('title' => /*_wp*/('на всю страницу'), 'value' => '0'),
		),
	),
	'frame_params' => array(
		'value' => '',
		'title' => /*_wp*/('Параметры iframe'),
		'description' => /*_wp*/("Например: width='500' height='500' frameborder='0' scrolling='auto'"),
		'control_type' => waHtmlControl::INPUT,
	),
);