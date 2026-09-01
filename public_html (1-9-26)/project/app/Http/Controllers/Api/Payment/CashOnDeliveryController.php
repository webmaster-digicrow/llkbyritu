<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Package;
use App\Models\Shipping;
use Illuminate\Http\Request;

class CashOnDeliveryController extends Controller
{

    public function store(Request $request)
    {
        if ($request->has('order_number')) {
            $order_number = $request->order_number;
            $order = Order::where('order_number', $order_number)->firstOrFail();
            
            $shipping = Shipping::findOrFail($request->shipping)->price * $order->currency_value;
            $packeging = Package::findOrFail($request->packeging)->price * $order->currency_value;

            $charge = $shipping + $packeging;

            $item_amount = $order->pay_amount * $order->currency_value;
            $item_amount += $charge;

            $order->packing_cost = $packeging;
            $order->shipping_cost = $shipping;
            $order->pay_amount = round($item_amount / $order->currency_value, 2);
            $order->method = $request->method;
            $order->txnid = $request->txnid;
            $order->payment_status = 'Pending';
            $order->save();
            return redirect(route('front.payment.success', 1));
        } else {
            return redirect()->back()->with('unsuccess', 'Something Went Wrong.');
        }
    }

}
