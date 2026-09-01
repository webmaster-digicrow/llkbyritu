<?php

namespace App\Http\Controllers\Api\Payment;
use App\Http\Controllers\Controller;
use App\Models\Generalsetting;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Models\Shipping;
use App\Models\Package;

class PaystackController extends Controller
{

    public function store(Request $request)
    {
        
         if(!$request->has('order_number')){
             return response()->json(['status' => false, 'data' => [], 'error' => 'Invalid Request']);
         }
         
        $order_number = $request->order_number;
        $order = Order::where('order_number',$order_number)->firstOrFail();
       
        $shipping = Shipping::findOrFail($request->shipping)->price * $order->currency_value;
        $packeging = Package::findOrFail($request->packeging)->price * $order->currency_value;
        $charge = $shipping + $packeging;
        $item_amount = $order->pay_amount ;
        $item_amount += $charge;
        $order['txnid'] = $request->ref_id;
        $order->packing_cost = $packeging;
        $order->shipping_cost = $shipping;
        $order->payment_status = 'Completed';
        $order->pay_amount = round($item_amount / $order->currency_value, 2);
        $order->method = "Paystack";
        $order->update();
        return redirect(route('front.payment.success',1));
        
    }

}