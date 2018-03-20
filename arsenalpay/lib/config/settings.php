<?php 
return array(
	'widget_id' => array(
		'value' => '',
		'title' => /*_wp*/('widget *'),
		'description' => /*_wp*/('Номер виджета, который присваивается ТСП для работы с виджетом (обязательный)'),
		'control_type' => waHtmlControl::INPUT,
	),
	'widget_key' => array(
		'value' => '',
		'title' => /*_wp*/('widgetKey *'),
		'description' => /*_wp*/('Ключ, который присваивается ТСП для работы с виджетом (обязательный)'),
		'control_type' => waHtmlControl::INPUT,
	),
	'callback_key' => array(
		'value' => '',
		'title' => /*_wp*/('callbackKey*'),
		'description' => /*_wp*/('Ключ для проверки подписи запросов (обязательный)'),
		'control_type' => waHtmlControl::INPUT,
	),
	'allowed_ip' => array(
		'value' => '',
		'title' => /*_wp*/('Allowed IP'),
		'description' => /*_wp*/('IP-адрес, с которого будет разрешен запрос от ArsenalPay (Необязательный)'),
		'control_type' => waHtmlControl::INPUT,
	),
);