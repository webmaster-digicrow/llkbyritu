<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Front\FrontBaseController;
use App\Models\Currency;
use App\Models\Deposit;
use App\Models\Order;
use App\Models\PaymentGateway;
use DB;
use Illuminate\Http\Request;

class CheckoutController extends FrontBaseController
{

    public function loadpayment(Request $request, $slug1, $slug2)
    {
        if ($request->has('order_number')) {
            $order_number = $request->order_number;
            $order = Order::where('order_number', $order_number)->firstOrFail();
            $curr = Currency::where('sign', '=', $order->currency_sign)->firstOrFail();
            $payment = $slug1;
            $pay_id = $slug2;
            $gateway = '';
            if ($pay_id != 0) {
                $gateway = PaymentGateway::findOrFail($pay_id);
            }
            return view('payment.load.payment', compact('payment', 'pay_id', 'gateway', 'curr'));
        }
    }

    public function depositloadpayment(Request $request, $slug1, $slug2)
    {

        if ($request->has('deposit_number')) {
            $deposit_number = $request->deposit_number;
            $deposit = Deposit::where('deposit_number', $deposit_number)->firstOrFail();
            $curr = Currency::where('name', $deposit->currency_code)->firstOrFail();
            $payment = $slug1;
            $pay_id = $slug2;
            $gateway = '';
            if ($pay_id != 0) {
                $gateway = PaymentGateway::findOrFail($pay_id);
            }
            return view('payment.load.payment', compact('payment', 'pay_id', 'gateway', 'curr'));
        }
    }

    public function checkout(Request $request)
    {
        if ($request->has('order_number')) {
            $order_number = $request->order_number;
            $order = Order::where('order_number', $order_number)->firstOrFail();
            $package_data = DB::table('packages')->where('user_id', '=', 0)->get();
            $shipping_data = DB::table('shippings')->where('user_id', '=', 0)->get();

            $curr = Currency::where('sign', '=', $order->currency_sign)->firstOrFail();
            $gateways = PaymentGateway::scopeHasGateway($curr->id);

            $paystack = PaymentGateway::whereKeyword('paystack')->first();
            $paystackData = $paystack->convertAutoData();

            if ($order->payment_status == 'Pending') {
                return view('payment.checkout', compact('order', 'package_data', 'shipping_data', 'gateways', 'paystackData'));
            }
        }
    }
}
