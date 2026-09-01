<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentGateway;
use App\Models\Shipping;
use Illuminate\Http\Request;
use MercadoPago;

class MercadopagoController extends Controller
{

    public function store(Request $request)
    {
        $input = $request->all();
        if (!$request->has('order_number')) {
            return response()->json(['status' => false, 'data' => [], 'error' => 'Invalid Request']);
        }
        $order_number = $request->order_number;
        $order = Order::where('order_number', $order_number)->firstOrFail();
        $curr = Currency::where('sign', '=', $order->currency_sign)->firstOrFail();
        if ($curr->name != "USD") {
            return redirect()->back()->with('unsuccess', 'Please Select USD Currency For Stripe.');
        }
        $data = PaymentGateway::whereKeyword('mercadopago')->first();

        $shipping = Shipping::findOrFail($request->shipping)->price * $order->currency_value;
        $packeging = Package::findOrFail($request->packeging)->price * $order->currency_value;

        $charge = $shipping + $packeging;

        $item_amount = $order->pay_amount * $order->currency_value;
        $item_amount += $charge;

        $paydata = $data->convertAutoData();

        MercadoPago\SDK::setAccessToken($paydata['token']);
        $payment = new MercadoPago\Payment();
        $payment->transaction_amount = (string) $item_amount;
        $payment->token = $input['token'];
        $payment->description = 'MercadoPago Payment';
        $payment->installments = 1;
        $payment->payer = array(
            "email" => $order['customer_email'],
        );
        $payment->save();

        if ($payment->status == 'approved') {
            $order->txnid = $payment->id;
            $order->method = "Stripe";
            $order->payment_status = 'Completed';
            $order->save();
            return redirect(route('front.payment.success', 1));
        }
        return redirect(route('front.payment.success', 0));

    }

}
