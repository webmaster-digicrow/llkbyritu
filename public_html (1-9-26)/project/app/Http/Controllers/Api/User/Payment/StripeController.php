<?php

namespace App\Http\Controllers\Api\User\Payment;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\PaymentGateway;
use App\Models\Transaction;
use Cartalyst\Stripe\Laravel\Facades\Stripe;
use Config;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        $deposit = Deposit::where('deposit_number', $request->deposit_number)->first();
       
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
                    'currency' => $deposit->currency_code,
                    'amount' => $deposit->amount,
                    'description' => 'Deposit',
                ]);

                if ($charge['status'] == 'succeeded') {
                    $deposit = Deposit::where('deposit_number', $request->deposit_number)->first();
                    $user = \App\Models\User::findOrFail($deposit->user_id);
                    $user->balance = $user->balance + ($deposit->amount);
                    $user->save();
                    $deposit['status'] = 1;
                    $deposit['method'] = 'Stripe';
                    $deposit['txnid'] = $charge['balance_transaction'];
                    $deposit->update();
                }

                // store in transaction table
                if ($deposit->status == 1) {
                    $transaction = new Transaction;
                    $transaction->txn_number = Str::random(3) . substr(time(), 6, 8) . Str::random(3);
                    $transaction->user_id = $deposit->user_id;
                    $transaction->amount = $deposit->amount;
                    $transaction->user_id = $deposit->user_id;
                    $transaction->currency_sign = $deposit->currency;
                    $transaction->currency_code = $deposit->currency_code;
                    $transaction->currency_value = $deposit->currency_value;
                    $transaction->method = $deposit->method;
                    $transaction->txnid = $deposit->txnid;
                    $transaction->details = 'Payment Deposit';
                    $transaction->type = 'plus';
                    $transaction->save();
                }

                return redirect(route('user.success', 1));

            } catch (Exception $e) {
                return back()->with('unsuccess', $e->getMessage());
            } catch (\Cartalyst\Stripe\Exception\CardErrorException $e) {
                return back()->with('unsuccess', $e->getMessage());
            } catch (\Cartalyst\Stripe\Exception\MissingParameterException $e) {
                return back()->with('unsuccess', $e->getMessage());
            }
        }
        return back()->with('unsuccess', 'Please Enter Valid Credit Card Informations.');
    }

}
