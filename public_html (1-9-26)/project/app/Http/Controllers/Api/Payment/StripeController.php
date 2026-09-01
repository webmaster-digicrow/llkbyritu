<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Generalsetting;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentGateway;
use App\Models\Shipping;
use Cartalyst\Stripe\Laravel\Facades\Stripe;
use Config;
use Illuminate\Http\Request;
use Validator;

class StripeController extends Controller
{

    public function __construct()
    {

        $data = PaymentGateway::whereKeyword('stripe')->first();
        $paydata = $data->convertAutoData();
        \Config::set('services.stripe.key', $paydata['key']);
        \Config::set('services.stripe.secret', $paydata['secret']);
    }

    public function store(Request $request)
    {

        if ($request->has('order_number')) {
            $order_number = $request->order_number;
            $order = Order::where('order_number', $order_number)->firstOrFail();
            $curr = Currency::where('sign', '=', $order->currency_sign)->firstOrFail();
            if ($curr->name != "USD") {
                return redirect()->back()->with('unsuccess', 'Please Select USD Currency For Stripe.');
            }

            $shipping = Shipping::findOrFail($request->shipping)->price * $order->currency_value;
            $packeging = Package::findOrFail($request->packeging)->price * $order->currency_value;

            $charge = $shipping + $packeging;
            $settings = Generalsetting::findOrFail(1);

            $item_amount = $order->pay_amount * $order->currency_value;
            $item_amount += $charge;

            $item_name = $settings->title . " Order";
            $validator = Validator::make($request->all(), [
                'cardNumber' => 'required',
                'cardCVC' => 'required',
                'month' => 'required',
                'year' => 'required',
            ]);

            if ($validator->passes()) {

                $stripe = Stripe::make(Config::get('services.stripe.secret'));
                try {
                    $token = $stripe->tokens()->create([
                        'card' => [
                            'number' => $request->cardNumber,
                            'exp_month' => $request->month,
                            'exp_year' => $request->year,
                            'cvc' => $request->cardCVC,
                        ],
                    ]);
                    if (!isset($token['id'])) {
                        return back()->with('error', 'Token Problem With Your Token.');
                    }

                    $charge = $stripe->charges()->create([
                        'card' => $token['id'],
                        'currency' => $curr->name,
                        'amount' => $item_amount,
                        'description' => $item_name,
                    ]);

                    if ($charge['status'] == 'succeeded') {
                        $order->packing_cost = $packeging;
                        $order->shipping_cost = $shipping;
                        $order->pay_amount = round($item_amount / $order->currency_value, 2);
                        $order->method = "Stripe";
                        $order->txnid = $charge['balance_transaction'];
                        $order->charge_id = $charge['id'];
                        $order->payment_status = 'Completed';
                        $order->save();
                        return redirect(route('front.payment.success', 1));
                    }

                } catch (Exception $e) {
                    return redirect(route('front.payment.success', 1));
                } catch (\Cartalyst\Stripe\Exception\CardErrorException $e) {
                    return redirect(route('front.payment.success', 0));
                } catch (\Cartalyst\Stripe\Exception\MissingParameterException $e) {
                    return redirect(route('front.payment.success', 0));
                }
            }
        }
        return response()->json(['status' => false, 'data' => [], 'error' => 'Invalid Request']);
    }

}
