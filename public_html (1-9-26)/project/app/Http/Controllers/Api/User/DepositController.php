<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Models\User;
use App\Models\PaymentGateway;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Validator;

class DepositController extends Controller
{
    
       public function sendDeposit($number){
    $deposit = Deposit::where('deposit_number',$number)->first();
 
    $curr = Currency::where('name', '=', $deposit->currency_code)->firstOrFail();
    $gateways = PaymentGateway::scopeHasGateway($curr->id);
    $paystack = PaymentGateway::whereKeyword('paystack')->first();
    $paystackData = $paystack->convertAutoData();

    if($deposit->status == 1){
        return response()->json(['status'=>false,'data'=>[],'error'=>"Deposit Allready Added."]);
    }
    return view('user.deposit.payment',compact('deposit','gateways','paystackData'));
}

    public function deposits()
    {
        try {
            $user = Auth::guard('api')->user();
            foreach ($user->deposits as $deposit) {
                if ($deposit->status != 1) {
                    $deposit['payment_url'] = route('user.deposit.send', $deposit->deposit_number);
                    $dipositss[] = $deposit;
                } else {
                    $dipositss[] = $deposit;
                }
            }

            return response()->json(['status' => true, 'data' => $dipositss, 'error' => []]);
        } catch (\Exception $e) {
            return response()->json(['status' => true, 'data' => [], 'error' => ['message' => $e->getMessage()]]);
        }
    }

    public function transactions()
    {
        try {
            $user = Auth::guard('api')->user();
            return response()->json(['status' => true, 'data' => $user->transactions, 'error' => []]);
        } catch (\Exception $e) {
            return response()->json(['status' => true, 'data' => [], 'error' => ['message' => $e->getMessage()]]);
        }
    }

    public function transactionDetails(Request $request)
    {
        try {

            //--- Validation Section

            $rules = [
                'id' => 'required',
            ];
            $customs = [
                'id.required' => 'Transaction ID is required.',
            ];
            $validator = Validator::make($request->all(), $rules, $customs);

            if ($validator->fails()) {
                return response()->json(['status' => false, 'data' => [], 'error' => $validator->errors()]);
            }

            //--- Validation Section Ends

            $id = $request->id;
            $data = Transaction::find($id);
            if (!$data) {
                return response()->json(['status' => true, 'data' => [], 'error' => ['message' => 'Invalid ID.']]);
            }
            return response()->json(['status' => true, 'data' => $data, 'error' => []]);
        } catch (\Exception $e) {
            return response()->json(['status' => true, 'data' => [], 'error' => ['message' => $e->getMessage()]]);
        }
    }

    public function store(Request $request)
    {

        try {

            //--- Validation Section

            $rules = [
                'amount' => 'required',
                'currency_code' => 'required',

            ];
            $customs = [
                'amount.required' => 'Payment Amount is required.',
                'currency_code.required' => 'Currency Field is required.',
            ];
            $validator = Validator::make($request->all(), $rules, $customs);

            if ($validator->fails()) {
                return response()->json(['status' => false, 'data' => [], 'error' => $validator->errors()]);
            }

            //--- Validation Section Ends

            $input = $request->all();

            if (!Auth::guard('api')->check()) {
                return response()->json(['status' => false, 'data' => [], 'error' => ["message" => 'Unauthenticated.']]);
            }

            $curr = Currency::where('name', '=', $request->currency_code)->first();
            $user = Auth::guard('api')->user();
            $deposit_number = Str::random(4) . time();

            $deposit = new Deposit;
            $deposit->user_id = $user->id;
            $deposit->currency_value = $curr->value;
            $deposit->currency = $curr->name;
            $deposit->amount = $request->amount / $curr->value;
            $deposit->currency_code = $curr->name;
            $deposit->method = $request->method;
            $deposit->txnid = $request->txnid;
            $deposit->status = 0;
            $deposit->deposit_number = $deposit_number;
            $deposit->save();

            return response()->json(['status' => true, 'data' => route('user.deposit.send', $deposit_number), 'error' => []]);

        } catch (\Exception $e) {
            return response()->json(['status' => true, 'data' => [], 'error' => ['message' => $e->getMessage()]]);
        }
    }

    
}
