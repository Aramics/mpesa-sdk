# mpesa-sdk

mpesa payment library for PHP

## Installation

Project using composer.

```
$ composer require aramics/mpesa-sdk
```

### Others:

    Download and include the required file

```
    require mpesa-sdk/src/Mpesa.php
```

## Usage

### Backend

```php
<?php

use Aramics\MpesaSdk\Mpesa;

class Payment{

public $this->settings = [
    'mode' => 'sandbox', //or live
    'consumer_key' => '',
    'consumer_secret' => '',
    'phone_number' =>'', //admin mpesa phone number
    'short_code' => '',
    'stk_pass_key' => '', //LIPA stk push password
    'logger' => 'custom_log', //callback function for logging. Empty to disable error logging.
];

__construct(){
    //Create the instance
    $this->mpesa = new Mpesa($this->settings);
}



//Backend endpoint: https://services.com/backend/checkout
function checkout() {

    $customer_mpesa_phone_number = ; //get from post sanitize($_POST["phone_number"]);
    $amount = (float); //get from post input $_POST['amount'];

    //make validation to phone and amount

    // Make payment with STK Push (LIPA API)
    $timestamp = date("YmdHis", time());

    //add hmac for unique url. This increase the security
    $hmac = hash_hmac("sha1", "$amount:$customer_mpesa_phone_number:$timestamp", $this->settings['consumer_secret']);

    //make the https secure url. Ensure tls , use ngrok or telebit to test on localserver

    $callback_url = 'https://services.com/webhook/mpesa/' .$hmac;

    //send stk push to user
    $payment = $this->mpesa->stkPush(
        $amount=500, //amount
        $ref_id="someOrderID", //reference id
        $description="Payment for", //description
        $customer_phone_number,
        $callback_url, //callback/ipn to process payment
        $timestamp, //timestamp in "YmdHis" ..optional
    );

    if ($payment->success) { //push send successfully.

        $payment_ref = $payment->ref_id;
        $_SESSION['order_ref'] = $payment->ref_id;

        //make some write to the DB with the payment_ref,user_id and $hmac to be used later in callback
    }

    return $this->responseJson($payment);
}









//Backend endpoint status check: https://services.com/backend/status
public function status($txn_id = '') {

    $payment = find_payment_by_order_id or find_payment_by_session_ref; //$_SESSION['order_ref']

    $id = null;
    $success = false;

    if ($payment) {

        $status = $payment->status;
        if ($status != "pending") {
                $id = $payment->id;
        }

        $success = $payment->status == 'success';
    }

    return $this->responseJson($id ? ['id' => $id, 'success' => $success, 'message' => $payment->description] : []);
}











//webhook callback/ipn for validating payment i.e
//https://services.com/webhook/mpesa/<signature>
function callbackNotification() {
    //find the signature from the db
    $order = ;//find signature (hmac) from db

    if (!$order) {
        //make some log
        return;
    }

    $payload = $this->input->raw_input_stream;
    $event = (object)json_decode($payload);

    if (isset($event->Body->stkCallback)) {

        try {

            $stk = $event->Body->stkCallback;
            $code = $stk->ResultCode;
            $description = $stk->ResultDesc;
            $transactionReference = $stk->CheckoutRequestID;

            $amount = 0;
            $phone = '';



            //validate callback/notification is truly from mpesa
            if ($this->settings['mode'] != "sandbox" && !$this->mpesa->isValidCallback()) {

                $ip = $this->mpesa->getIPAdress();
                throw new \Exception("Mpesa: Request source is unkown ($ip) for $transactionReference", 1);
            }

            //successful
            if ($code === 0) {

                $metas = (array)$stk->CallbackMetadata->Item;

                foreach ($metas as $meta) {
                    if ($meta->Name == "Amount") {
                        $amount = (float)$meta->Value;
                    }

                    if ($meta->Name == "PhoneNumber") {
                        $phone = $meta->Value;
                    }
                }

                $timestamp = $order->timestamp;

                //enesure matched amount and phone number
                $hmac = hash_hmac("sha1", "$amount:$phone:$timestamp", $this->settings['consumer_secret']);

                if ($hmac !== $signature) {

                    throw new \Exception("Mpesa: Invalid signature for $transactionReference", 1);
                }

                //ensure reference id generated during request when signature (hmac) was generated matches with the one in db.
                if ($order->payment_ref_id !== $transactionReference) {

                    throw new \Exception("Mpesa: ref id mismatched for $transactionReference", 1);
                }

                //ensure $transactionRefrence not yet used on the db

                //finally make fulfillment using $transactionRefrence

                //you can remove the order log
            } else {
                //failed

                $event->status = "failed";
                //update order with the status "failed";
            }
        } catch (\Exception $e) {

            set_status_header(500);
            return $this->responseJson(['error' => $e->getMessage()]);
        }
    }
}


```

```

```

```

```

### Frontend

Getting phone number for the UI and showing the user payment flow

```php
    <?php
        //generate the modal html, JS and CSS
        echo Aramics\MpesaSdk\Mpesa::loadModal();

        //this will inject an object mpesaPay into the current window
        //see below for use.
    ?>
    <script>

        let from = document.getElementById("checkout-form");
        let amount = document.getElementById("amount-input").value;

        //mpesaPay.init()
        mpesaPay.init({
            timeoutMinutes: 30,
            amount: amount * 126,
            amountInUSD: amount,
            notificationCallback: notify,
            submitCallback: (phoneNumber) => {

                //disable phone input and button click.
                mpesaPay.disableActions();

                //insert phone number to form.
                form.append(`<input type="hidden" name="phone_number" value="${phoneNumber}" />`)

                //submit form through ajax. i. e the backend that send STK Push (https://services.com/backend)
                $.post(
                    'https://services.com/backend/checkout',
                    form.serialize(),
                    (json, statusText) => {
                    //We want to show message only when error i.e smooth process.
                    if (!json.success)
                        alert(json.message);

                    //STK Push sent to user, now we need to await user to pay
                    if (json.success && json.ref_id) {

                        //when user input pin and confirm, the notification will be sent to our callback
                        ///we need to check the backend to detect if order is marked paid
                        let checkInterval = checkMpesaStatus(json.ref_id);
                        let timeoutCallback = () => {
                            clearInterval(checkInterval);
                            alert("Timed out. If you have confirmed the prompt, refresh to check you order status");
                            window.location.reload();
                        }

                        //init the timer countdown for completeing the transaction...
                        mpesaPay.initTimer(timeoutCallback);
                    } else {

                        //enable button and input
                        mpesaPay.enableActions();
                    }

                }, (xhr, textStatus, errorThrown) => {
                    notify(errorThrown);

                    //enable button and input
                    mpesaPay.enableActions();
                })
            }
        });

        //show modal
        mpesaPay.openModal();

        //callback functioin to check if other is fullfiled on backend
        //check payment status every 15 seconds
        const checkMpesaStatus = (txnId) => {

            let poolInterval = setInterval(async () => {

                let resp = await fetch("https://services.com/backend/status/" + txnId)
                if (resp.status) {
                    resp = await resp.json();
                    if (resp.id) {
                        alert(resp.message);
                        setTimeout(() => {
                            window.location.reload();
                        }, 5000);
                    }
                }
            }, 15000);

            return poolInterval;
        };

</script>




## Preview
![screenshot1](https://user-images.githubusercontent.com/29895599/204027287-203e062e-11cb-44e9-b503-6486061bbde3.png)
![screenshot2](https://user-images.githubusercontent.com/29895599/204027305-450901c0-4615-4002-ae47-0245619d8c10.png)

