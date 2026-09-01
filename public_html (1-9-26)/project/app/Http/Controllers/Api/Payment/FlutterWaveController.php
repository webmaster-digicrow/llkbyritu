<?php

namespace App\Http\Controllers\Api\Payment;

use App\Classes\GeniusMailer;use App\Http\Controllers\Payment\Checkout\CheckoutBaseControlller;
use App\Models\Cart;use App\Models\Currency;

use App\Models\Order;
use App\Models\Package;

use App\Models\PaymentGateway;
use App\Models\Shipping;
use Illuminate\Http\Request;
use Session;

class FlutterWaveController extends CheckoutBaseControlller
{
    public $public_key;
    private $secret_key;

    public function __construct()
    {
        parent::__construct();
        $data = PaymentGateway::whereKeyword('flutterwave')->first();
        $paydata = $data->convertAutoData();
        $this->public_key = $paydata['public_key'];
        $this->secret_key = $paydata['secret_key'];
    }

    public function store(Request $request)
    {
        $order_number = $request->order_number;
        $order = Order::where('order_number', $order_number)->firstOrFail();

        $curr = Currency::where('sign', '=', $order->currency_sign)->firstOrFail();
        if ($curr->name != "USD") {
            return redirect()->back()->with('unsuccess', 'Please Select USD Currency For Flutterwave.');
        }

        $shipping = Shipping::findOrFail($request->shipping)->price * $order->currency_value;
        $packeging = Package::findOrFail($request->packeging)->price * $order->currency_value;

        $charge = $shipping + $packeging;

        $item_amount = $order->pay_amount * $order->currency_value;
        $item_amount += $charge;

        $cancel_url = route('payment.checkout') . "?order_number=" . $order->order_number;
        $notify_url = route('api.flutter.notify');

        Session::put('order_data', $order);
        Session::put('order_payment_id', $order['order_number']);

        // SET CURL

        $curl = curl_init();

        $customer_email = $request->customer_email;
        $amount = $item_amount;
        $currency = $curr->name;
        $txref = $order['order_number']; // ensure you generate unique references per transaction.
        $PBFPubKey = $this->public_key; // get your public key from the dashboard.
        $redirect_url = $notify_url;
        $payment_plan = ""; // this is only required for recurring payments.

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.ravepay.co/flwv3-pug/getpaidx/api/v2/hosted/pay",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode([
                'amount' => 5,
                'customer_email' => $order->customer_email,
                'currency' => $currency,
                'txref' => $txref,
                'PBFPubKey' => $PBFPubKey,
                'redirect_url' => $redirect_url,
                'payment_plan' => $payment_plan,
            ]),
            CURLOPT_HTTPHEADER => [
                "content-type: application/json",
                "cache-control: no-cache",
            ],
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        if ($err) {
            // there was an error contacting the rave API
            return redirect($cancel_url)->with('unsuccess', 'Curl returned error: ' . $err);
        }

        $transaction = json_decode($response);

        if (!$transaction->data && !$transaction->data->link) {
            // there was an error from the API
            return redirect($cancel_url)->with('unsuccess', 'API returned error: ' . $transaction->message);
        }

        return redirect($transaction->data->link);

    }

    public function notify(Request $request)
    {

        $input_data = $request->all();

        if ($request->cancelled == "true") {
            return redirect(route('front.payment.success', 0));
        }

        $success_url = route('front.payment.success', 1);
        $cancel_url = route('front.payment.success', 0);

        /** Get the payment ID before session clear **/
        $payment_id = Session::get('order_payment_id');

        if (isset($input_data['txref'])) {

            $ref = $payment_id;

            $query = array(
                "SECKEY" => $this->secret_key,
                "txref" => $ref,
            );

            $data_string = json_encode($query);

            $ch = curl_init('https://api.ravepay.co/flwv3-pug/getpaidx/api/v2/verify');
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

            $response = curl_exec($ch);

            curl_close($ch);

            $resp = json_decode($response, true);
            
            if ($resp['status'] = "success") {
                if (!empty($resp['data'])) {

                    $paymentStatus = $resp['data']['status'];
                    $chargeResponsecode = $resp['data']['chargecode'];

                    if (($chargeResponsecode == "00" || $chargeResponsecode == "0") && ($paymentStatus == "successful")) {
                        $order = Order::where('order_number', $payment_id)->firstOrFail();
                        $data['payment_status'] = 'Completed';
                        $order->update($data);
                        return redirect($success_url);
                    }
                }
            }
            return redirect($cancel_url);
        }
        return redirect($cancel_url);
    }
}
