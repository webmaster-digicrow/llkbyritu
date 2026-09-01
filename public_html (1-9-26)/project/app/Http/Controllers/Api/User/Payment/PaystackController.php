<?php

namespace App\Http\Controllers\Api\User\Payment;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaystackController extends Controller
{

    public function store(Request $request)
    {
        
         if(!$request->has('deposit_number')){
             return response()->json(['status' => false, 'data' => [], 'error' => 'Invalid Request']);
         }
         
         
        $deposit_number = $request->deposit_number;
        $order = Deposit::where('deposit_number',$deposit_number)->first();
   
        $item_amount = $order->pay_amount ;
        $order['txnid'] = $request->ref_id;
        $order->status = 1;
        $order->amount = round($item_amount / $order->currency_value, 2);
        $order->method = "Paystack";
        $order->update();

        $user = \App\Models\User::findOrFail($order->user_id);
        $user->balance = $user->balance + ($order->amount);
        $user->save();

    if ($order->status == 1) {
            $transaction = new \App\Models\Transaction;
            $transaction->txn_number = Str::random(3).substr(time(), 6,8).Str::random(3);
            $transaction->user_id = $order->user_id;
            $transaction->amount = $order->amount;
            $transaction->user_id = $order->user_id;
            $transaction->currency_sign = $order->currency;
            $transaction->currency_code = $order->currency_code;
            $transaction->currency_value= $order->currency_value;
            $transaction->method = $order->method;
            $transaction->txnid = $order->txnid;
            $transaction->details = 'Payment Deposit';
            $transaction->type = 'plus';
            $transaction->save();
        }
        return redirect(route('user.success',1));
        
    }

}