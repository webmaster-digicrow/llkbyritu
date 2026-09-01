<?php

namespace App\Http\Controllers\Api\Payment;

use App\Classes\Instamojo;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Generalsetting;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentGateway;
use App\Models\Shipping;
use Illuminate\Http\Request;

class InstamojoController extends Controller
{

    public function store(Request $request)
    {

        if (!$request->has('order_number')) {
            return response()->json(['status' => false, 'data' => [], 'error' => 'Invalid Request']);
        }

        $order_number = $request->order_number;
        $order = Order::where('order_number', $order_number)->firstOrFail();
        $curr = Currency::where('sign', '=', $order->currency_sign)->firstOrFail();

        if ($curr->name != "INR") {
            return redirect()->back()->with('unsuccess', 'Please Select INR Currency For Instamojo.');
        }

     
        $shipping = Shipping::findOrFail($request->shipping)->price * $order->currency_value;
        $packeging = Package::findOrFail($request->packeging)->price * $order->currency_value;

        $charge = $shipping + $packeging;
        $settings = Generalsetting::findOrFail(1);
        $item_name = $settings->title . " Order";
        $user_email = $order->customer_email;

        $item_amount = round($order->pay_amount * $order->currency_value, 2);
        $item_amount += $charge;

        $notify_url = action('Api\Payment\InstamojoController@notify');
        $data = PaymentGateway::whereKeyword('instamojo')->first();
 
        $paydata = $data->convertAutoData();
        if($paydata['sandbox_check'] == 1){
            $api = new Instamojo($paydata['key'], $paydata['token'], 'https://test.instamojo.com/api/1.1/');
            }
            else {
            $api = new Instamojo($paydata['key'], $paydata['token']);
            }

        try {
            $response = $api->paymentRequestCreate(array(
                "purpose" => $item_name,
                "amount" => $item_amount,
                "send_email" => true,
                "email" => $user_email,
                "redirect_url" => $notify_url,
            ));

            $redirect_url = $response['longurl'];
            $order->pay_id = $order['pay_id'] = $response['id'];
            $order['pay_amount'] = round($item_amount / $order->currency_value, 2);
            $order['shipping_cost'] = $packeging;
            $order['packing_cost'] = $packeging;
            $order['method'] = $request->method;
            $order->update();
            return redirect($redirect_url);
        } catch (Exception $e) {
            print('Error: ' . $e->getMessage());
        }

    }

    public function notify(Request $request)
    {

        $data = $request->all();

        $order = Order::where('pay_id', '=', $data['payment_request_id'])->first();
        $cancel_url = route('payment.checkout') . "?order_number=" . $order->order_number;

        if (isset($order)) {
            $data['txnid'] = $data['payment_id'];
            $data['payment_status'] = 'Completed';
            $order->update($data);
            return redirect(route('front.payment.success', 1));
        }
        return $cancel_url;
    }

}
