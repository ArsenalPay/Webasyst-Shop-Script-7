<?php

class arsenalpayPayment extends waPayment implements waIPAyment
{
    /**
    * @var string
    */
    private $order_id;
    private $pattern = '/^(\w[\w\d]+)_([\w\d]+)_(.+)$/';
    private $template = '%s_%d_%s';
    
    const GATEWAY= 'src';
    const MERCHANT_TOKEN = 't';
    const ACCOUNT_ID = 'n';
    const AMOUNT = 'a';
    const PHONE = 'msisdn';
    const PAYER_ID = 'payer_id';
    const OTHER_CODE = 's';
    const CSS_FILE = 'css';
    const FRAME_MODE = 'frame';


    public function allowedCurrency() 
    {
        return array(
            'RUB',
        );
    }

    /**
    * @see waIPayment::payment()
    */
    public function payment($payment_form_data, $order_data, $auto_submit = false) 
    {
        $order = waOrder::factory($order_data);

        // Verifying order currency support
        if (!in_array($order->currency, $this->allowedCurrency())) {
            throw new waException('Оплата через ArsenalPay производится только в рублях (RUB).');
        }

        $arsenalpay_params[self::GATEWAY] = $this->gateway;
        $arsenalpay_params[self::MERCHANT_TOKEN] = $this->merchant_token;
        if ($this->gateway == 'mk' || $this->gateway == 'card') {
            $arsenalpay_params[self::ACCOUNT_ID] = htmlentities(sprintf($this->template, $this->app_id, $this->merchant_id, $order->id), ENT_QUOTES, 'utf-8');
        } elseif($this->gateway == 'wallet') {
            $arsenalpay_params[self::PAYER_ID] = htmlentities(sprintf($this->template, $this->app_id, $this->merchant_id, $order->id), ENT_QUOTES, 'utf-8');
        } 
        $arsenalpay_params[self::AMOUNT] = number_format($order_data['amount'], 2, '.', '');
        $arsenalpay_params[self::OTHER_CODE] = $this->other_code;
        $arsenalpay_params[self::CSS_FILE] = $this->css; 
        if ($this->gateway != 'card') {
            $arsenalpay_params[self::FRAME_MODE] = $this->frame_mode;
        }
        $arsenalpay_params[self::PHONE] = htmlentities($order->getContact()->get('phone', 'default'), ENT_QUOTES, 'UTF-8');

        /**
        * Раскомментировать, если необходимо получать информацию о покупателе в email-уведомлениях о заказах
        */
        /**
        * $full_name = sprintf('%s %s', $order->getContact()->get('firstname', 'default'), $order->getContact()->get('lastname', 'default'));
        * $arsenalpay_params['full_name'] = htmlentities($full_name, ENT_QUOTES, 'UTF-8');
        * $arsenalpay_params['email'] = htmlentities($order->getContact()->get('email', 'default'), ENT_QUOTES, 'UTF-8');
        * $arsenalpay_params['address'] = htmlentities($order->getContact()->get('address', 'default'), ENT_QUOTES, 'UTF-8');
        * $description = str_replace('#', '', str_replace(array('“', '”', '«', '»'), '"', $order->description));
        * $arsenalpay_params['description'] = mb_substr($description, 0, 255, "UTF-8");
        * $arsenalpay_params['other'] = '';
        */

        $arsenalpay_params = http_build_query($arsenalpay_params);
        $frame_params = isset($this->frame_params) ? $this->frame_params : "width='500' height='500' frameborder='0' scrolling='auto'";
        
        $view = wa()->getView();

        $view->assign(
            array(
                'arsenalpay_url' => $this->getEndpointUrl(),
                'arsenalpay_params' => $arsenalpay_params,
                'frame_params' => $frame_params,
            )
        );

        return $view->fetch($this->path.'/templates/payment.html');
    }

    protected function callbackInit($request)
    {
        /**
        * Parsing data to obtain order id as well as ids of corresponding app and plugin setup instance responsible
        * for callback processing
        */
        $this->request = $request;
        if(!empty($request['ACCOUNT']) && preg_match($this->pattern, strtolower($request['ACCOUNT']), $match)) {
            $this->app_id = $match[1]; 
            $this->merchant_id = $match[2];
            $this->order_id = $match[3]; 
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

    protected function callbackHandler($request)
    {
        // Verifying that order id was received within callback
        if (!$this->order_id) {
            self::log($this->id, array('error' => 'Invalid invoice number'));
            throw new waPaymentException('ERR', 403);
        }
        $transaction_data = $this->formalizeData($request);

        if(!$this->verifySign($request)) {
            self::log($this->id, array('error' => 'Invalid sign'));
                throw new waPaymentException('ERR', 403);
        }

        // проверяем поддержку типа указанный транзакции данным плагином
        if (!in_array($transaction_data['type'], $this->supportedOperations())) {
            self::log($this->id, array('error' => 'Unsupported payment operation'));
            throw new waPaymentException('ERR', 403);
        }

        $app_payment_method = null;
        $message = null;
        $back_url = null;
        switch ($transaction_data['type']) {
            case self::OPERATION_CHECK:
                $app_payment_method = self::CALLBACK_CONFIRMATION;
                $transaction_data['state'] = self::STATE_AUTH;
                $back_url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
                $message = "Заказ успешно подтвержден.";
                $response = "YES";
                break;
            case self::OPERATION_AUTH_CAPTURE:
                $app_payment_method = self::CALLBACK_PAYMENT;
                $transaction_data['state'] = self::STATE_CAPTURED;
                $back_url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data);
                $message = "Заказ успешно оплачен.";
                $response = "OK";
                break;
        }
        
        $transaction_data = $this->saveTransaction($transaction_data, $request);
        if ($app_payment_method) {
            $result = $this->execAppCallback($app_payment_method, $transaction_data);
            if (!empty($result['error'])) {
                self::log($this->id, array('error' => 'Warning (validate error): '.$result['error']));
            }
            self::addTransactionData($transaction_data['order_id'], $result);
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
    public function supportedOperations()
    {
        return array(
            self::OPERATION_CHECK,
            self::OPERATION_AUTH_CAPTURE,
        );
    }

    protected function formalizeData($transaction_raw_data) 
    {
        $fields  = array(
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
            'SIGN',         // Подпись запроса = md5(md5(ID).md(FUNCTION).md5(RRN).md5(PAYER).md5(AMOUNT).
                            // md5(ACCOUNT).md(STATUS).md5(secret_key)) 
        );
        foreach ($fields as $key) 
        {
            if (!array_key_exists($key, $transaction_raw_data))
            {       
                self::log($this->id, array('error' => 'ERR_'.$key));     
                throw new waPaymentException('ERR', 403);
            }
        }
            
        $transaction_data = parent::formalizeData($transaction_raw_data);

        if ($transaction_raw_data['FUNCTION'] == 'check') {
            $type = self::OPERATION_CHECK;
        }
        elseif ($transaction_raw_data['FUNCTION'] == 'payment') {
            $type = self::OPERATION_AUTH_CAPTURE;
        }
        else {
            self::log($this->id, array('error' => 'ERR_FUNCTION')); 
            throw new waPaymentException('ERR', 403);
        }
        $transaction_data = array_merge($transaction_data, array(
            'type' => $type,
            'native_id' => strtolower($transaction_raw_data['ACCOUNT']),
            'amount' => isset($transaction_raw_data['AMOUNT']) ? $transaction_raw_data['AMOUNT'] : '',
            'currency_id' => isset($transaction_raw_data['CURRENCY']) ? $transaction_raw_data['CURRENCY'] : '',
            'result' => '',
            'order_id' => $this->order_id,
            'view_data' => '',
            'state' => '',
            'plugin' => $this->id,
            'merch_type' => isset($transaction_raw_data['MERCH_TYPE']) ? $transaction_raw_data['MERCH_TYPE'] : '',
            'amount_full' => isset($transaction_raw_data['AMOUNT_FULL']) ? $transaction_raw_data['AMOUNT_FULL'] : 0,
        ));

        return $transaction_data;
    }

    private function verifySign($request) {
            $sign = ifset($request['SIGN']);
            return ( $sign && ( $sign == md5( md5($request['ID']).
                    md5($request['FUNCTION']).md5($request['RRN']).
                    md5($request['PAYER']).md5($request['AMOUNT']).md5($request['ACCOUNT']).
                    md5($request['STATUS']).md5($this->secret_key) ) ) );
    }

    private function getEndpointUrl() {
        return 'https://arsenalpay.ru/payframe/pay.php';
    }

}