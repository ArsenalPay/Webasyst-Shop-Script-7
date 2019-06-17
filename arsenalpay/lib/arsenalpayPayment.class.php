<?php

class arsenalpayPayment extends waPayment implements waIPAyment {
	/**
	 * @var string
	 */
	private $order_id;
	private $pattern = '/^(\w[\w\d]+)_([\w\d]+)_(.+)$/';
	private $template = '%s_%d_%s';

	public function allowedCurrency() {
		return array(
			'RUB',
		);
	}

	/**
	 * @see waIPayment::payment()
	 *
	 * @param array   $payment_form_data
	 * @param waOrder $order_data
	 * @param bool    $auto_submit
	 *
	 * @return string
	 * @throws waException
	 */
	public function payment($payment_form_data, $order_data, $auto_submit = false) {
		$order = waOrder::factory($order_data);

		// Verifying order currency support
		if (!in_array($order->currency, $this->allowedCurrency())) {
			throw new waException('Оплата через ArsenalPay производится только в рублях (RUB).');
		}

		$user_id     = $order_data->customer_id;
		$destination = htmlentities(sprintf($this->template, $this->app_id, $this->merchant_id, $order->id), ENT_QUOTES, 'utf-8');
		$amount      = number_format($order_data['amount'], 2, '.', '');
		$widget      = $this->widget_id;
		$widget_key  = $this->widget_key;
		$nonce       = md5(microtime(true) . mt_rand(100000, 999999));
		$sign_param  = "$user_id;$destination;$amount;$widget;$nonce";
		$widget_sign = hash_hmac('sha256', $sign_param, $widget_key);


		$view = wa()->getView();
		$view->assign(
			array(
				'user_id'     => $user_id,
				'destination' => $destination,
				'amount'      => $amount,
				'widget'      => $widget,
				'widgetKey'   => $widget_key,
				'nonce'       => $nonce,
				'widget_sign' => $widget_sign,
			)
		);

		return $view->fetch($this->path . '/templates/payment.html');
	}

	protected function callbackInit($request) {
		/**
		 * Parsing data to obtain order id as well as ids of corresponding app and plugin setup instance responsible
		 * for callback processing
		 */
		$this->request = $request;
		if (!empty($request['ACCOUNT']) && preg_match($this->pattern, strtolower($request['ACCOUNT']), $match)) {
			$this->app_id      = $match[1];
			$this->merchant_id = $match[2];
			$this->order_id    = $match[3];
		}
		else {
			self::log($this->id, array('error' => 'Invalid invoice number'));
			throw new waPaymentException('ERR', 403);
		}

		/**
		 * Calling parent's method to continue plugin initialization
		 */
		return parent::callbackInit($request); //mandatory call of parent class method at the end
	}

	protected function callbackHandler($request) {
		$allowed_ip     = trim($this->allowed_ip);
		$remote_address = $_SERVER["REMOTE_ADDR"];
		if (strlen($allowed_ip) > 0 && $allowed_ip != $remote_address) {
			self::log($this->id, array('error' => 'Denied IP ' . $remote_address ));
			throw new waPaymentException('ERR', 403);
		}

		// Verifying that order id was received within callback
		if (!$this->order_id) {
			self::log($this->id, array('error' => 'Invalid invoice number'));
			throw new waPaymentException('ERR', 403);
		}
		$transaction_data = $this->formalizeData($request);

		if (!$this->checkSign($request, $this->callback_key)) {
			self::log($this->id, array('error' => 'Invalid sign'));
			throw new waPaymentException('ERR', 403);
		}

		$app_payment_method = null;
		$message            = null;
		$back_url           = null;
		$response           = null;
		$fiscal             = false;

		switch ($request['FUNCTION']) {

			case 'check':
				$app_payment_method        = self::CALLBACK_CONFIRMATION;
				$transaction_data['type']  = self::OPERATION_CHECK;
				$transaction_data['state'] = self::STATE_AUTH;
				$back_url                  = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
				$message                   = "Заказ успешно подтвержден.";
				$response                  = "YES";

				if (class_exists('shopOrderModel') && class_exists('shopOrderItemsModel')) {
					$order_model = new shopOrderModel();
					$order = $order_model->getById($this->order_id);
					$items_model = new shopOrderItemsModel();
					$order['items'] = $items_model->getItems($this->order_id);
					$contact = new waContact($order['contact_id']);
					$fiscal = $this->prepareFiscal($transaction_data, $request, $order, $contact);
				}
			break;

			case 'payment':
				$app_payment_method        = self::CALLBACK_PAYMENT;
				$transaction_data['type']  = self::OPERATION_AUTH_CAPTURE;
				$transaction_data['state'] = self::STATE_CAPTURED;
				$back_url                  = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
				$message                   = "Заказ успешно оплачен.";
				$response                  = "OK";
			break;

			case 'cancel':
				$app_payment_method        = self::CALLBACK_CANCEL;
				$transaction_data['type']  = self::OPERATION_CANCEL;
				$transaction_data['state'] = self::STATE_CANCELED;
				$back_url                  = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
				$message                   = "Оплата заказа отменена.";
				$response                  = "OK";
			break;

			case 'cancelinit':
				$app_payment_method        = self::CALLBACK_CANCEL;
				$transaction_data['type']  = self::OPERATION_CANCEL;
				$transaction_data['state'] = self::STATE_CANCELED;
				$back_url                  = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
				$message                   = "Оплата заказа отменена.";
				$response                  = "OK";
			break;

			case 'refund':
				$app_payment_method        = self::CALLBACK_REFUND;
				$transaction_data['type']  = self::OPERATION_REFUND;
				$transaction_data['state'] = self::STATE_REFUNDED;
				$back_url                  = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
				$message                   = "Частичный возврат платежа.";
				$response                  = "OK";
			break;

			case 'reverse':
				$app_payment_method        = self::CALLBACK_REFUND;
				$transaction_data['type']  = self::OPERATION_REFUND;
				$transaction_data['state'] = self::STATE_REFUNDED;
				$back_url                  = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
				$message                   = "Полный возврат платежа.";
				$response                  = "OK";
			break;

			case 'reversal':
				$app_payment_method        = self::CALLBACK_REFUND;
				$transaction_data['type']  = self::OPERATION_REFUND;
				$transaction_data['state'] = self::STATE_REFUNDED;
				$back_url                  = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
				$message                   = "Полный возврат платежа.";
				$response                  = "OK";
			break;

			case 'hold':
				$app_payment_method        = self::CALLBACK_CONFIRMATION;
				$transaction_data['type']  = self::OPERATION_AUTH_ONLY;
				$transaction_data['state'] = self::STATE_AUTH;
				$back_url                  = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
				$message                   = "Платеж зарезервирован";
				$response                  = "OK";
			break;

			default: {
				self::log($this->id, array('error' => 'Function is not support: ' . $request['FUNCTION']));
				throw new waPaymentException('ERR', 403);
			}
		}

		$transaction_data = $this->saveTransaction($transaction_data, $request);
		$result           = $this->execAppCallback($app_payment_method, $transaction_data);
		if (!empty($result['error'])) {
			self::log($this->id, array('error' => 'Warning (validate error): ' . $result['error']));
		}

		self::log($this->id, array('response' => $response));

		if(isset($request['FORMAT']) && $request['FORMAT'] == 'json') {
			$response = array("response" => $response);
			if ($fiscal && isset($request['OFD']) && $request['OFD'] == 1) {
				$response['ofd'] = $fiscal;
			}
			$response = json_encode($response, JSON_UNESCAPED_UNICODE);
		}
		echo $response;

		return array(
			'template' => false,
			'back_url' => $back_url,
			'message'  => $message,
		);

	}

	/**
	 * Возвращает список операций с транзакциями, поддерживаемых плагином.
	 *
	 * @see waPayment::supportedOperations()
	 * @return array
	 */
	public function supportedOperations() {
		return array(
			self::OPERATION_CHECK,
			self::OPERATION_AUTH_CAPTURE,
			self::OPERATION_AUTH_ONLY,
		);
	}

	protected function formalizeData($transaction_raw_data) {
		$fields = array(
			'FUNCTION',     // Тип запроса (check - проверка получателя платежа, payment - уведомление о платеже)
			'ID',           // Merchant identifier
			'RRN',          // Transaction identifier
			'PAYER',        // Payer identifier
			'AMOUNT',       // Сумма фактического списания по платежу
			'AMOUNT_FULL',  // Сумма исходного платежа
			'ACCOUNT',      // Номер лицевого счета в системе ТСП
			'MERCH_TYPE',   // Тип магазина (0 - Юридическое лицо, 1 - Физическое лицо)
			'STATUS',       // Статус платежа (check - проверка, payment - платеж)
			'DATETIME',     // Date and time in ISO-8601 format (YYYY-MM-DDThh:mm:ss±hh:mm), urlencoded.
			'SIGN',         // Подпись запроса = md5(md5(ID).md(FUNCTION).md5(RRN).md5(PAYER).md5(AMOUNT).md5(ACCOUNT).md(STATUS).md5(secret_key))
		);
		foreach ($fields as $key) {
			if (!array_key_exists($key, $transaction_raw_data)) {
				self::log($this->id, array('error' => 'ERR_' . $key));
				throw new waPaymentException('ERR', 403);
			}
		}

		$transaction_data = parent::formalizeData($transaction_raw_data);

		$transaction_data = array_merge($transaction_data, array(
			'type'        => '',
			'native_id'   => strtolower($transaction_raw_data['ACCOUNT']),
			'amount'      => isset($transaction_raw_data['AMOUNT']) ? $transaction_raw_data['AMOUNT'] : '',
			'currency_id' => isset($transaction_raw_data['CURRENCY']) ? $transaction_raw_data['CURRENCY'] : 'RUB',
			'result'      => '',
			'order_id'    => $this->order_id,
			'view_data'   => '',
			'state'       => '',
			'plugin'      => $this->id,
			'merch_type'  => isset($transaction_raw_data['MERCH_TYPE']) ? $transaction_raw_data['MERCH_TYPE'] : '',
			'amount_full' => isset($transaction_raw_data['AMOUNT_FULL']) ? $transaction_raw_data['AMOUNT_FULL'] : 0,
		));

		return $transaction_data;
	}

	private function checkSign($callback, $pass) {

		$validSign = ($callback['SIGN'] === md5(
				md5($callback['ID']) .
				md5($callback['FUNCTION']) . md5($callback['RRN']) .
				md5($callback['PAYER']) . md5($callback['AMOUNT']) . md5($callback['ACCOUNT']) .
				md5($callback['STATUS']) . md5($pass)
			)) ? true : false;

		return $validSign;
	}

	private function prepareFiscal($transaction_data, $request, $order, $contact) {

		$emails = $contact->get('email', 'value');

		foreach($order['items'] as $key => $val) {
			$name     = $val['name'];
			$price    = (float)$val['price'];
			$quantity = (int)$val['quantity'];
			$sum      = $price*$quantity;
			$items[]  = array(
				'name'     => $name,
				'price'    => $price,
				'quantity' => $quantity,
				'sum'      => $sum,
			);
		}

		$a = Array(
            'id' => $request['RRN'],
            'type' => 'sell',
            'receipt' => Array(
                    'attributes' => Array(
                            'email' => $emails[0],
                        ),
                    'items' => $items,
                ),
		);

		return $a;
	}

}