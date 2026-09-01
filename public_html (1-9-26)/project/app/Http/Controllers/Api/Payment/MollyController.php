<?php

namespace App\Http\Controllers\Api\Payment;

use Mollie\Laravel\Facades\Mollie;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Generalsetting;
use App\Models\Order;
use App\Models\Shipping;
use App\Models\Package;
use Illuminate\Http\Request;
use Session;

class MollyController extends Controller
{
 

public function store(Request $request){

     if(!$request->has('order_number')){
         return response()->json(['status' => false, 'data' => [], 'error' => 'Invalid Request']);
     }

    $order_number = $request->order_number;
    $order = Order::where('order_number',$order_number)->firstOrFail();
    $curr = Currency::where('sign','=',$order->currency_sign)->firstOrFail();
    
    $available_currency = array(
        'AED',
        'AUD',
        'BGN',
        'BRL',
        'CAD',
        'CHF',
        'CZK',
        'DKK',
        'EUR',
        'GBP',
        'HKD',
        'HRK',
        'HUF',
        'ILS',
        'ISK',
        'JPY',
        'MXN',
        'MYR',
        'NOK',
        'NZD',
        'PHP',
        'PLN',
        'RON',
        'RUB',
        'SEK',
        'SGD',
        'THB',
        'TWD',
        'USD',
        'ZAR'
        );
        if(!in_array($curr->name,$available_currency))
        {
        return redirect()->back()->with('unsuccess','Invalid Currency For Molly Payment.');
        }


        $input = $request->all();
        

        $settings = Generalsetting::findOrFail(1);
        $shipping = Shipping::findOrFail($request->shipping)->price * $order->currency_value;
        $packeging = Package::findOrFail($request->packeging)->price * $order->currency_value;
        $input['shipping'] = $shipping;
        $input['packeging'] = $packeging;
        $charge = $shipping + $packeging;
        $settings = Generalsetting::findOrFail(1);
       
        $item_amount = round($order->pay_amount / $curr->value, 2);
        $item_amount += $charge;
        
        
        $order['item_name'] = $settings->title." Order";
        $order['item_amount'] = $item_amount;
        
        
        $data['return_url'] = route('payment.checkout')."?order_number=".$order->order_number;
        $data['cancel_url'] = route('payment.checkout')."?order_number=".$order->order_number;

        $payment = Mollie::api()->payments()->create([
            'amount' => [
                'currency' => $curr->name,
                'value' => ''.sprintf('%0.2f', $order['item_amount']).'', // You must send the correct number of decimals, thus we enforce the use of strings
            ],
            'description' => $settings->title." Order" ,
            'redirectUrl' => route('api.molly.notify'),
            ]);

        Session::put('payment_id',$payment->id);
        Session::put('molly_data',$order->id);
        Session::put('paypal_data',$input);
        $payment = Mollie::api()->payments()->get($payment->id);

        return redirect($payment->getCheckoutUrl(), 303);
 }



public function notify(Request $request){
        
        $paypal_data = Session::get('paypal_data');
        $order = Order::findOrFail(Session::get('molly_data'));
        $cancel_url = route('payment.checkout')."?order_number=".$order->order_number;
        $payment = Mollie::api()->payments()->get(Session::get('payment_id'));
        if($payment->status == 'paid'){
        $order['txnid'] = $payment->id;
        $order['method'] = 'Molly';
        $order->packing_cost = $paypal_data['packeging'];
        $order->shipping_cost = $paypal_data['shipping'];
        $order['payment_status'] = 'Completed';
        $order->update();
         return redirect(route('front.payment.success',1));
        }
        else {
            return redirect($cancel_url);
        }
}



}
