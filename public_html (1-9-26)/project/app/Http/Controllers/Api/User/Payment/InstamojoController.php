<?php

namespace App\Http\Controllers\Api\User\Payment;

use App\Classes\Instamojo;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Deposit;
use App\Models\Generalsetting;
use App\Models\PaymentGateway;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InstamojoController extends Controller
{
    public function store(Request $request)
    {
        $data = PaymentGateway::whereKeyword('instamojo')->first();
        if (!$request->has('deposit_number')) {
            return response()->json(['status' => false, 'data' => [], 'error' => 'Invalid Request']);
        }

        $deposit_number = $request->deposit_number;
        $order = Deposit::where('deposit_number', $deposit_number)->firstOrFail();
        $curr = Currency::where('name', '=', $order->currency_code)->firstOrFail();

        if ($curr->name != "INR") {
            return redirect()->back()->with('unsuccess', 'Please Select INR Currency For Instamojo.');
        }

    
        $settings = Generalsetting::findOrFail(1);
        $item_name = $settings->title . " Order";
        $user_email = User::findOrFail($order->user_id)->email;

        $item_amount = round($order->amount * $order->currency_value,2);

        $notify_url = action('Api\User\Payment\InstamojoController@notify');

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
            $order['flutter_id'] = $response['id'];
            $order['amount'] = round($item_amount / $order->currency_value, 2);
            $order['method'] = $request->method;
            $order->update();

            // store in transaction table
            if ($order->status == 1) {
                $transaction = new Transaction;
                $transaction->txn_number = Str::random(3) . substr(time(), 6, 8) . Str::random(3);
                $transaction->user_id = $order->user_id;
                $transaction->amount = $order->amount;
                $transaction->user_id = $order->user_id;
                $transaction->currency_sign = $order->currency;
                $transaction->currency_code = $order->currency_code;
                $transaction->currency_value = $order->currency_value;
                $transaction->method = $order->method;
                $transaction->txnid = $order->txnid;
                $transaction->details = 'Payment Deposit';
                $transaction->type = 'plus';
                $transaction->save();
            }

            return redirect($redirect_url);
        } catch (Exception $e) {
            print('Error: ' . $e->getMessage());
        }

    }

    public function notify(Request $request)
    {

        $data = $request->all();

        $order = Deposit::where('flutter_id', '=', $data['payment_request_id'])->first();

        $cancel_url = route('user.deposit.send', $order->order_number);
        $user = \App\Models\User::findOrFail($order->user_id);
        $user->balance = $user->balance + ($order->amount);
        $user->save();

        if (isset($order)) {
            $order['txnid'] = $data['payment_id'];
            $order['status'] = 1;
            $order->update();
            return redirect(route('user.success', 1));
        }
        return $cancel_url;
    }

}
