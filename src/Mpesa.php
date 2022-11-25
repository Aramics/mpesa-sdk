<?php

namespace Aramics\MpesaSdk;

/**
 * This file implements Mpesa.
 *
 * @author     Aramics
 * @since      2022
 */

/**
 * This class describes mpesa.
 */
class Mpesa
{
    public $settings;

    const MPESA_LIVE_IP_ADDRESSES = [
        '196.201.214.200',
        '196.201.214.206',
        '196.201.213.114',
        '196.201.214.207',
        '196.201.214.208',
        '196. 201.213.44',
        '196.201.212.127',
        '196.201.212.138',
        '196.201.212.129',
        '196.201.212.136',
        '196.201.212.74',
        '196.201.212.69'
    ];

    /**
     * Make instance
     *
     * @param array $settings
     * 
     * /*
     * array of parameters:
     *   [
     *        'consumer_key' => '',
     *        'consumer_secret' => '',
     *        'phone_number' => '', //admin mpesa phone number
     *        'short_code' => '',
     *        'stk_pass_key' => '', //LIPA stk push password
     *        'logger' => 'log', //callback function for logging     
     *   ]
     */
    public function __construct($settings)
    {
        $this->settings = (object)$settings;
    }


    /**
     * Get url of an endpoin using endpoint key
     *
     * @param string $endpoint
     * @return string
     */
    public function getUrl($endpoint)
    {
        $urls = [
            "access_token" => 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
            "stk_push" => 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
        ];

        if (empty($this->settings->mode) || $this->settings->mode == 'sandbox') {
            $urls = [
                "access_token" => 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
                "stk_push" => 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
            ];
        }

        return $urls[$endpoint];
    }


    /**
     * Create and return access token form keys
     *
     * @return string
     */
    public function getAccessToken()
    {
        $mpesa = $this->settings;
        $consumer_key = $mpesa->consumer_key;
        $consumer_secret = $mpesa->consumer_secret;
        $auth = base64_encode("$consumer_key:$consumer_secret");
        $token = '';

        try {

            $query = $this->httpRequest($this->getUrl('access_token'), [
                'headers' => ["Authorization: Basic $auth"],
            ]);

            if (!isset($query->access_token))
                throw new \Exception($query->errorMessage, 1);

            $token = $query->access_token;
        } catch (\Throwable $th) {

            if (function_exists($this->settings->logger)) {

                call_user_func($this->settings->logger, [$th->getMessage()]);
            }
        }

        return $token;
    }


    /**
     * Make lipa push
     *
     * @param float $amount
     * @param string $ref_id
     * @param string $description
     * @param string $customer_mpesa_phone_number
     * @param string $callback_url
     * @param string $timestamp
     * @param string $accessToken
     * @return object
     */
    public function stkPush(
        float $amount,
        string $ref_id,
        string $description,
        string $customer_mpesa_phone_number,
        string $callback_url,
        $timestamp = null,
        $access_token = null
    ) {

        $timestamp = $timestamp ?? date("YmdHis", time());

        $mpesa = $this->settings;
        $phone = $mpesa->phone_number;
        $short_code = $mpesa->short_code;
        $stk_pass_key = $mpesa->stk_pass_key;

        $password = base64_encode($short_code . $stk_pass_key . $timestamp);

        $mpesa_payload = [
            "BusinessShortCode" => $short_code,
            "Password" => $password,
            "Timestamp" => $timestamp,
            "TransactionType" => "CustomerPayBillOnline",
            "Amount" => $amount,
            "PartyA" => $phone,
            "PartyB" => $short_code,
            "PhoneNumber" => $customer_mpesa_phone_number,
            "CallBackURL" => $callback_url,
            "AccountReference" => $ref_id,
            "TransactionDesc" => $description
        ];

        $success = false;
        $message = '';
        $id = '';

        try {

            $accessToken = $access_token ?? $this->getAccessToken();

            if (empty($accessToken))
                throw new \Exception(_l('error getting access code.'), 1);

            $query = $this->httpRequest($this->getUrl('stk_push'), [
                'headers' => [
                    "Authorization: Bearer $accessToken",
                    'Content-Type: application/json'
                ],
                'method' => 'POST',
                'data' => json_encode($mpesa_payload)
            ]);

            $success = ($query->ResponseCode ?? $query->responseCode ?? $query->errorCode) == 0;
            $message = $query->CustomerMessage ?? $query->responseDesc ?? $query->errorMessage;
            $id = $query->CheckoutRequestID ?? $query->responseId ?? $query->requestId;
        } catch (\Throwable $th) {

            if (function_exists($this->settings->logger)) {

                call_user_func($this->settings->logger, [$th->getMessage()]);
            }

            $message = $th->getMessage();
        }

        return (object) [
            'success' => $success,
            'message' => $message,
            'ref_id' => $id
        ];
    }

    /**
     * Determine if webook callaback notification is truly from mpesa
     *
     * @return boolean
     */
    public function isValidCallback()
    {

        //validate by ip whitelisting
        $validIP = $this->getIPAdress(true);

        if (empty($validIP))
            return false;

        return in_array($validIP, self::MPESA_LIVE_IP_ADDRESSES);
    }


    /**
     * Get ip address from request
     * 
     * Ref: https://www.javatpoint.com/how-to-get-the-ip-address-in-php
     *
     * @return string
     */
    public function getIPAdress($require_valid = false)
    {
        //whether ip is from the share internet  
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
        //whether ip is from the proxy  
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        //whether ip is from the remote address  
        else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        if ($require_valid && !filter_var($ip, FILTER_VALIDATE_IP))
            return '';

        return $ip;
    }

    /**
     * make http request using curl
     *
     * @param string $url
     * @param array $options
     * @throws Exception
     * @return object
     */
    private function httpRequest($url, $options)
    {
        /* eCurl */
        $curl = curl_init($url);

        $verify_ssl = (int)($options['sslverify'] ?? 0);
        $timeout = (int)($options['timeout'] ?? 30);

        if ($options) {

            $method = strtoupper($options["method"] ?? "GET");

            /* Data */
            $data = @$options["data"];

            /* Headers */
            $headers = (array)@$options["headers"];

            /* Set JSON data to POST */
            if ($method === "POST") {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            }

            /* Define content type */
            if ($headers)
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYHOST => $verify_ssl,
            CURLOPT_TIMEOUT        => (int)$timeout,
        ]);


        /* make request */
        $result = curl_exec($curl);

        /* errro */
        $error  = '';

        if (!$curl || !$result) {
            $error = 'Curl Error - "' . curl_error($curl) . '" - Code: ' . curl_errno($curl);
            throw new \Exception($error, 1);
        }

        /* close curl */
        curl_close($curl);

        return (object)json_decode($result);
    }

    /**
     * Load html js and css for the modal prompt.
     * @TODO: seprate this to separate file in future and serve.
     *
     * @return void
     */
    public static function loadModal()
    {
?>

<div class="modal fade show justify-content-center align-items-end align-items-md-center" id="mpesa-modal" tabindex="-1"
    role="dialog">
    <div class="modal-dialog modal-sm m-0" style="width: 100%;">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" aria-label="Close" onclick="mpesaPay.closeModal()"><span
                        aria-hidden="true">&times;</span></button>
                <h4 class="modal-title"><?= _l('mpesa'); ?></h4>
            </div>
            <div class="modal-body mb-10">


                <div class="form-group">
                    <label class="control-label" for="example-input-normal"><?= _l('phone_number') ?></label>
                    <input type="text" name="mpesa_phone_number" value="" class="form-control" required>
                </div>

                <div id="mpesa-timer" class="d-none text-center">
                    <span class="rouned loading primary hourglass"></span>
                    <p class="mt-2">
                        <span id="mpesa-amount-2" class="font-weight-bold"></span><br />
                        Waiting confirmation<br />
                        <small>Check your phone for mpesa PIN prompt.</small>
                    </p>

                    <span id="time" class="font-weight-bold"></span> remains
                </div>
                <button type="button" class="btn btn-primary btn-block" id="pay-with-stk-push"
                    onclick="mpesaPay.pay_with_stk_push();" disabled>Pay <span id="mpesa-amount"></span></button>

                <p id="conversion-block" class="mt-2"></p>
            </div>
        </div>
    </div>
</div>

<!-- mpesa module -->
<style>
.loading {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    --primary-color: #745af2;
}

.loading::after {
    content: " ";
    display: inline-block;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 6px solid #fff;
    border-color: #fff transparent #fff transparent;
}

.loading.primary::after {
    border: 6px solid var(--primary-color);
    border-color: var(--primary-color) transparent var(--primary-color) transparent;
}

.hourglass:after {
    animation: loading-hourglass 1.2s linear infinite;
}

@keyframes loading-hourglass {
    0% {
        transform: rotate(0);
        animation-timing-function: cubic-bezier(0.55, 0.055, 0.675, 0.19);
    }

    50% {
        transform: rotate(900deg);
        animation-timing-function: cubic-bezier(0.215, 0.61, 0.355, 1);
    }

    100% {
        transform: rotate(1800deg);
    }
}
</style>
<script id="mpesa-js">
const mpesaPay = {
    modal: null,
    submitCallback: null,
    timeoutMinutes: 2.0,
    notificationCallback: alert,
    timer: null,
    phoneInput: null,
    submitButton: null,
    conversionBlock: null,
    amount: 0,
    amountInUSD: 0,
    currency: 'KES',
    usd: 'USD',
    closeModal() {
        this.modal.classList.remove('d-flex', 'show');
    },
    openModal() {
        this.modal.classList.add('show', 'd-flex');
    },
    init(config) {
        this.modal = document.getElementById('mpesa-modal');
        this.submitCallback = config.submitCallback;
        this.notificationCallback = config.notificationCallback;
        this.timeoutMinutes = config.timeoutMinutes;
        this.timer = document.getElementById('mpesa-timer');
        this.phoneInput = document.querySelector("#mpesa-modal input[name=mpesa_phone_number]");
        this.submitButton = document.getElementById("pay-with-stk-push");
        this.conversionBlock = document.getElementById("conversion-block");
        this.amount = config.amount;
        this.amountInUSD = config.amountInUSD ?? 0;
        if (this.amount > 0) {

            let amountText = this.amount.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,') + ' ' + this.currency;
            document.getElementById('mpesa-amount').innerText = amountText;
            document.getElementById('mpesa-amount-2').innerText = amountText;

            this.submitButton.removeAttribute('disabled');
            if (this.amountInUSD > 0) {
                this.conversionBlock.innerText =
                    `1 ${this.usd} = ${(this.amount/this.amountInUSD).toFixed(2)} ${this.currency}`;
            }
        }
    },
    initTimer(timeoutCallback) {

        if (!this.timer) return;

        let currentTime = this.timeoutMinutes;

        let interval = setInterval(() => {

            currentTime = currentTime - 0.0166667;

            if (currentTime <= 0) {

                this.timer.classList.add('d-none');

                clearInterval(interval);

                if (timeoutCallback)
                    timeoutCallback();

                return;
            }

            this.timer.querySelector('#time').innerText = (currentTime < 1 ? parseInt(currentTime * 60)
                .toString() : parseFloat(currentTime).toFixed(2)) + (currentTime < 1 ? ' seconds' :
                ' minutes');

        }, 1000);

        //show time
        this.timer.classList.remove('d-none');
        this.submitButton.classList.add('d-none');
    },
    enableActions() {

        this.submitButton.removeAttribute('disabled');
        this.phoneInput.removeAttribute('disabled');
        this.submitButton.classList.remove('loading', 'hourglass');
        this.conversionBlock.classList.remove('d-none');
    },
    disableActions() {

        this.submitButton.setAttribute('disabled', 'disabled');
        this.phoneInput.setAttribute('disabled', 'disabled');
        this.submitButton.classList.add('loading', 'hourglass');
        this.conversionBlock.classList.add('d-none');
    },
    pay_with_stk_push() {

        phoneNumber = this.phoneInput.value;

        if (!phoneNumber) {
            this.notificationCallback("<?= _l('invalid phone number') ?>", 'warning');
            return;
        }

        if (!this.submitCallback) {
            this.notificationCallback("<?= _l('invalid submit callback') ?>", 'warning');
            return;
        }

        //make modal loading
        this.submitCallback(phoneNumber);
    }
};
</script>
<?php
    }
}