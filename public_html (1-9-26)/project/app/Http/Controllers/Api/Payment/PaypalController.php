<?php

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Payment\Checkout\CheckoutBaseControlller;
use App\Models\Currency;
use App\Models\Generalsetting;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentGateway;
use App\Models\Shipping;
use Illuminate\Http\Request;use Illuminate\Support\Facades\Auth;use Illuminate\Support\Str;use OrderHelper;
use PayPal\Api\Amount;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;
use Session;

class PaypalController extends CheckoutBaseControlller
{

    private $_api_context;
    public function __construct()
    {
        parent::__construct();
        $data = PaymentGateway::whereKeyword('paypal')->first();
        $paydata = $data->convertAutoData();
        $paypal_conf = \Config::get('paypal');
        $paypal_conf['client_id'] = $paydata['client_id'];
        $paypal_conf['secret'] = $paydata['client_secret'];
        $paypal_conf['settings']['mode'] = $paydata['sandbox_check'] == 1 ? 'sandbox' : 'live';
        $this->_api_context = new ApiContext(new OAuthTokenCredential(
            $paypal_conf['client_id'],
            $paypal_conf['secret'])
        );

        $this->_api_context->setConfig($paypal_conf['settings']);

    }

    public function store(Request $request)
    {
        // dd($request->all(),1);
        $order_number = $request->order_number;
        $order = Order::where('order_number', $order_number)->firstOrFail();
        $curr = Currency::where('sign', '=', $order->currency_sign)->firstOrFail();
        if ($curr->name != "USD") {
            return redirect()->back()->with('unsuccess', 'Please Select USD Currency For Paypal.');
        }
        $input = $request->all();
        $shipping = Shipping::findOrFail($request->shipping)->price * $order->currency_value;
        $packeging = Package::findOrFail($request->packeging)->price * $order->currency_value;

        $charge = $shipping + $packeging;
        $settings = Generalsetting::findOrFail(1);

        $item_amount = $order->pay_amount * $order->currency_value;
        $item_amount += $charge;

        $cancel_url = route('api.paypal.cancle');
        $notify_url = route('api.paypal.notify');

        $payer = new Payer();
        $payer->setPaymentMethod('paypal');
        $item_1 = new Item();
        $item_1->setName($item_amount) /** item name **/
            ->setCurrency($curr->name)
            ->setQuantity(1)
            ->setPrice($item_amount); /** unit price **/
        $item_list = new ItemList();
        $item_list->setItems(array($item_1));
        $amount = new Amount();
        $amount->setCurrency($curr->name)
            ->setTotal($item_amount);
        $transaction = new Transaction();
        $transaction->setAmount($amount)
            ->setItemList($item_list)
            ->setDescription('Via Paypal');
        $redirect_urls = new RedirectUrls();
        $redirect_urls->setReturnUrl($notify_url) /** Specify return URL **/
            ->setCancelUrl($cancel_url);
        $payment = new Payment();
        $payment->setIntent('Sale')
            ->setPayer($payer)
            ->setRedirectUrls($redirect_urls)
            ->setTransactions(array($transaction));

        try {
            $payment->create($this->_api_context);
        } catch (\PayPal\Exception\PPConnectionException $ex) {
            return redirect()->back()->with('unsuccess', $ex->getMessage());
        }
        foreach ($payment->getLinks() as $link) {
            if ($link->getRel() == 'approval_url') {
                $redirect_url = $link->getHref();
                break;
            }
        }
        /** add payment ID to session **/
        Session::put('order_number', $order_number);
        Session::put('order_payment_id', $payment->getId());
        if (isset($redirect_url)) {
            /** redirect to paypal **/
            return \Redirect::away($redirect_url);
        }

        return redirect()->back()->with('unsuccess', __('Unknown error occurred'));

    }

    public function notify(Request $request)
    {
   
        $order_number = Session::get('order_number');
        $success_url = route('front.payment.success',1);
        $cancel_url = route('payment.checkout') . "?order_number=" .$order_number;
        $input_data = $request->all();
        /** Get the payment ID before session clear **/
        $payment_id = Session::get('order_payment_id');
        //dd($input, $order_data, $success_url, $cancel_url, $input_data, $payment_id);
        /** clear the session payment ID **/
        if (empty($input_data['PayerID']) || empty($input_data['token'])) {
            return redirect($cancel_url);
        }
        $payment = Payment::get($payment_id, $this->_api_context);
        $execution = new PaymentExecution();
        $execution->setPayerId($input_data['PayerID']);
        /**Execute the payment **/

        $result = $payment->execute($execution, $this->_api_context);

        if ($result->getState() == 'approved') {
            $order = Order::where('order_number', $order_number)->firstOrFail();
            $resp = json_decode($payment, true);
            $data['payment_status'] = 'Completed';
            $data['txnid'] = $resp['transactions'][0]['related_resources'][0]['sale']['id'];
     
            $order->update($data);
            return redirect($success_url);

        }
        return redirect($cancel_url);
    }

}
