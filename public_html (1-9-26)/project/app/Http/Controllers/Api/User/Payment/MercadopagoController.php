<?php

namespace App\Http\Controllers\Api\User\Payment;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use MercadoPago;

class MercadopagoController extends Controller
{

    public function store(Request $request)
    {
        $deposit = Deposit::where('deposit_number', $request->deposit_number)->first();
        $input = $request->all();
        $user = \App\Models\User::findOrFail($deposit->user_id);
        $data = PaymentGateway::whereKeyword('mercadopago')->first();
        $paydata = $data->convertAutoData();

        MercadoPago\SDK::setAccessToken($paydata['token']);
        $payment = new MercadoPago\Payment();
        $payment->transaction_amount = (string) $deposit->amount;
        $payment->token = $input['token'];
        $payment->description = 'MercadoPago Payment';
        $payment->installments = 1;
        $payment->payer = array(
            "email" => $user['email'],
        );
        $payment->save();

        if ($payment->status == 'approved') {
            $user->balance = $user->balance + ($deposit->amount);
            $user->save();
            $deposit['status'] = 1;
            $deposit['method'] = 'Mercadopago';
            $deposit['txnid'] =$payment->id;
            $deposit->update();
            return redirect(route('front.payment.success', 1));
        }
        return redirect(route('front.payment.success', 0));

    }

}
