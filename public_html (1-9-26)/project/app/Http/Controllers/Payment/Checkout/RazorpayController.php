<?php

namespace App\Http\Controllers\Payment\Checkout;

use App\{
    Models\Cart,
    Models\Order,
    Models\PaymentGateway,
    Classes\GeniusMailer
};
use App\Models\Country;
use App\Models\Reward;
use App\Models\State;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Session;
use OrderHelper;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class RazorpayController extends CheckoutBaseControlller
{
    public function __construct()
    {
        parent::__construct();
        $data = PaymentGateway::whereKeyword('razorpay')->first();
        $paydata = $data->convertAutoData();
        $this->keyId = $paydata['key'];
        $this->keySecret = $paydata['secret'];
        $this->displayCurrency = 'INR';
        $this->api = new Api($this->keyId, $this->keySecret);
    }


    public function store(Request $request)
    {
        $input = $request->all();
        $data = PaymentGateway::whereKeyword('razorpay')->first();
        $total = $request->total;


        if($this->curr->name != "INR")
        {
            return redirect()->back()->with('unsuccess',__('Please Select INR Currency For This Payment.'));
        }
        if($request->pass_check) {
            $auth = OrderHelper::auth_check($input); // For Authentication Checking
            if(!$auth['auth_success']){
                return redirect()->back()->with('unsuccess',$auth['error_message']);
            }
        }

        if (!Session::has('cart')) {
            return redirect()->route('front.cart')->with('success',__("You don't have any product to checkout."));
        }

        $order['item_name'] = $this->gs->title." Order";
        $order['item_number'] = Str::random(4).time();
        $order['item_amount'] = round($total,2);
        $cancel_url = route('front.payment.cancle');
        $notify_url = route('front.razorpay.notify');


        $orderData = [
            'receipt'         => $order['item_number'],
            'amount'          => $order['item_amount'] * 100, // 2000 rupees in paise
            'currency'        => 'INR',
            'payment_capture' => 1 // auto capture
        ];

        $razorpayOrder = $this->api->order->create($orderData);

        // Build a compact product snapshot for Razorpay notes (used to recover if session is lost)
        // Format: "product_id:qty|product_id:qty|..."  kept under 255 chars
        $cartSnapshot = '';
        if (Session::has('cart')) {
            $cartItems = Session::get('cart');
            $pairs = [];
            if ($cartItems && isset($cartItems->items)) {
                foreach ($cartItems->items as $item) {
                    $pid = $item['item']['id'] ?? 0;
                    $qty = $item['qty'] ?? 1;
                    $pairs[] = $pid . ':' . $qty;
                }
            }
            $cartSnapshot = implode('|', $pairs);
            if (strlen($cartSnapshot) > 255) {
                $cartSnapshot = substr($cartSnapshot, 0, 255);
            }
        }

        Session::put('input_data',$input);
        Session::put('order_data',$order);
        Session::put('order_payment_id', $razorpayOrder['id']);

        $displayAmount = $amount = $orderData['amount'];

        if ($this->displayCurrency !== 'INR')
        {
            $url = "https://api.fixer.io/latest?symbols=$this->displayCurrency&base=INR";
            $exchange = json_decode(file_get_contents($url), true);

            $displayAmount = $exchange['rates'][$this->displayCurrency] * $amount / 100;
        }

        $checkout = 'automatic';

        if (isset($_GET['checkout']) and in_array($_GET['checkout'], ['automatic', 'manual'], true))
        {
            $checkout = $_GET['checkout'];
        }

        $data = [
            "key"               => $this->keyId,
            "amount"            => $amount,
            "name"              => $order['item_name'],
            "description"       => $order['item_name'],
            "prefill"           => [
                "name"              => $request->customer_name,
                "email"             => $request->customer_email,
                "contact"           => $request->customer_phone,
            ],
            "notes"             => [
                "address"           => substr((string)$request->customer_address, 0, 255),
                "merchant_order_id" => $order['item_number'],
                "cart_products"     => $cartSnapshot,      // "product_id:qty|product_id:qty"
                "customer_name"     => substr((string)$request->customer_name, 0, 255),
                "customer_email"    => substr((string)$request->customer_email, 0, 255),
                "amount_inr"        => $order['item_amount'],
            ],
            "theme"             => [
                "color"             => "{{$this->gs->colors}}"
            ],
            "order_id"          => $razorpayOrder['id'],
        ];

        if ($this->displayCurrency !== 'INR')
        {
            $data['display_currency']  = $this->displayCurrency;
            $data['display_amount']    = $displayAmount;
        }

        $json = json_encode($data);
        $displayCurrency = $this->displayCurrency;


        view()->share('langg', $this->language);
        return view( 'frontend.razorpay-checkout', compact( 'data','displayCurrency','json','notify_url' ) );
    }

    public function notify(Request $request)
    {
        $input      = Session::get('input_data');
        $order_data = Session::get('order_data');
        $success_url = route('front.payment.return');
        $cancel_url  = route('front.payment.cancle');
        $input_data  = $request->all();

        /** Get the Razorpay order ID before any session changes **/
        $payment_id = Session::get('order_payment_id');

        // -----------------------------------------------------------------------
        // GUARD: Session data is missing (expired / cookie loss / Safari etc.)
        // Payment may already have been captured — log everything so you can
        // manually reconcile from the Razorpay dashboard.
        // -----------------------------------------------------------------------
        if (empty($input) || empty($order_data) || empty($payment_id)) {
            Log::error('Razorpay Checkout: Session data missing on notify callback. ' .
                'Payment captured but order NOT created. Manual reconciliation required.', [
                'razorpay_payment_id' => $input_data['razorpay_payment_id'] ?? 'N/A',
                'razorpay_order_id'   => $input_data['razorpay_order_id']   ?? 'N/A',
                'razorpay_signature'  => $input_data['razorpay_signature']  ?? 'N/A',
                'session_had_input'   => !empty($input),
                'session_had_order'   => !empty($order_data),
                'session_had_pay_id'  => !empty($payment_id),
            ]);
            return redirect($cancel_url)
                ->with('unsuccess', 'Your payment was received but your session expired. ' .
                    'Please contact support with your Payment ID: ' .
                    ($input_data['razorpay_payment_id'] ?? 'unknown'));
        }

        // -----------------------------------------------------------------------
        // GUARD: razorpay_payment_id must be present in the POST callback
        // -----------------------------------------------------------------------
        if (empty($input_data['razorpay_payment_id'])) {
            Log::warning('Razorpay Checkout: notify() called without razorpay_payment_id.', [
                'post_data' => $input_data,
            ]);
            return redirect($cancel_url);
        }

        // -----------------------------------------------------------------------
        // SIGNATURE VERIFICATION
        // Bug fixed: SignatureVerificationError was not imported — it is now.
        // -----------------------------------------------------------------------
        $success = true;

        try {
            $attributes = [
                'razorpay_order_id'   => $payment_id,
                'razorpay_payment_id' => $input_data['razorpay_payment_id'],
                'razorpay_signature'  => $input_data['razorpay_signature'],
            ];

            $this->api->utility->verifyPaymentSignature($attributes);

        } catch (SignatureVerificationError $e) {
            Log::error('Razorpay Checkout: Signature verification failed.', [
                'razorpay_payment_id' => $input_data['razorpay_payment_id'],
                'razorpay_order_id'   => $payment_id,
                'error'               => $e->getMessage(),
            ]);
            $success = false;
        } catch (\Exception $e) {
            Log::error('Razorpay Checkout: Unexpected error during signature verification.', [
                'razorpay_payment_id' => $input_data['razorpay_payment_id'],
                'error'               => $e->getMessage(),
            ]);
            $success = false;
        }

        if ($success !== true) {
            return redirect($cancel_url);
        }

        // -----------------------------------------------------------------------
        // ORDER CREATION — wrapped in a DB transaction so it's all-or-nothing
        // -----------------------------------------------------------------------
        try {
            \DB::transaction(function () use ($input, $order_data, $input_data, &$order, &$cart) {

                $oldCart  = Session::get('cart');
                $cart     = new Cart($oldCart);
                OrderHelper::license_check($cart); // For License Checking

                $t_oldCart = Session::get('cart');
                $t_cart    = new Cart($t_oldCart);
                $new_cart  = [
                    'totalQty'   => $t_cart->totalQty,
                    'totalPrice' => $t_cart->totalPrice,
                    'items'      => $t_cart->items,
                ];
                $new_cart = json_encode($new_cart);

                $temp_affilate_users = OrderHelper::product_affilate_check($cart); // For Product Based Affilate Checking
                $affilate_users      = $temp_affilate_users == null ? null : json_encode($temp_affilate_users);

                $order = new Order;

                $input['cart']           = $new_cart;
                $input['user_id']        = Auth::check() ? Auth::user()->id : NULL;
                $input['affilate_users'] = $affilate_users;
                $input['pay_amount']     = $order_data['item_amount'] / $this->curr->value;
                $input['order_number']   = $order_data['item_number'];
                $input['wallet_price']   = (isset($input['wallet_price']) && $input['wallet_price'] != "")
                                            ? $input['wallet_price'] / $this->curr->value
                                            : 0;
                $input['payment_status'] = "Completed";
                $input['txnid']          = $input_data['razorpay_payment_id'];

                if (isset($input['tax']) && $input['tax'] != "") {
                    if (isset($input['tax_type']) && $input['tax_type'] == 'state_tax') {
                        $input['tax_location'] = State::findOrFail($input['tax'])->state;
                    } else {
                        $input['tax_location'] = Country::findOrFail($input['tax'])->country_name;
                    }
                }
                $input['tax'] = Session::get('current_tax');

                if ($input['dp'] == 1) {
                    $input['status'] = 'completed';
                }

                if (Session::has('affilate')) {
                    $val = $order_data['item_amount'] / $this->curr->value;
                    $val = $val / 100;
                    $sub = $val * $this->gs->affilate_charge;
                    if ($temp_affilate_users != null) {
                        $t_sub = 0;
                        foreach ($temp_affilate_users as $t_cost) {
                            $t_sub += $t_cost['charge'];
                        }
                        $sub = $sub - $t_sub;
                    }
                    if ($sub > 0) {
                        $user = OrderHelper::affilate_check(Session::get('affilate'), $sub, $input['dp']); // For Affiliate Checking
                        $input['affilate_user']   = Session::get('affilate');
                        $input['affilate_charge'] = $sub;
                    }
                }

                $order->fill($input)->save();
                $order->tracks()->create(['title' => 'Pending', 'text' => 'You have successfully placed your order.']);
                $order->notifications()->create();

                if ($input['coupon_id'] != "") {
                    OrderHelper::coupon_check($input['coupon_id']); // For Coupon Checking
                }

                OrderHelper::size_qty_check($cart);         // For Size Quantity Checking
                OrderHelper::stock_check($cart);             // For Stock Checking
                OrderHelper::vendor_order_check($cart, $order); // For Vendor Order Checking

            });

        } catch (\Exception $e) {
            Log::error('Razorpay Checkout: Order creation failed inside DB transaction. ' .
                'Payment was captured but order was NOT saved. Manual reconciliation required.', [
                'razorpay_payment_id' => $input_data['razorpay_payment_id'],
                'order_number'        => $order_data['item_number'] ?? 'N/A',
                'error'               => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
            ]);
            return redirect($cancel_url)
                ->with('unsuccess', 'Your payment was received but an error occurred saving your order. ' .
                    'Please contact support with Payment ID: ' . $input_data['razorpay_payment_id']);
        }

        // -----------------------------------------------------------------------
        // POST-ORDER CLEANUP (outside transaction — non-critical)
        // -----------------------------------------------------------------------
        Session::put('temporder', $order);
        Session::put('tempcart', $cart);
        Session::forget('cart');
        Session::forget('already');
        Session::forget('coupon');
        Session::forget('coupon_total');
        Session::forget('coupon_total1');
        Session::forget('coupon_percentage');

        if ($order->user_id != 0 && $order->wallet_price != 0) {
            OrderHelper::add_to_transaction($order, $order->wallet_price); // Store To Transactions
        }

        if (Auth::check()) {
            if ($this->gs->is_reward == 1) {
                $num     = $order->pay_amount;
                $rewards = Reward::get();
                $smallest = [];
                foreach ($rewards as $i) {
                    $smallest[$i->order_amount] = abs($i->order_amount - $num);
                }
                if (!empty($smallest)) {
                    asort($smallest);
                    $final_reword = Reward::where('order_amount', key($smallest))->first();
                    Auth::user()->update(['reward' => (Auth::user()->reward + $final_reword->reward)]);
                }
            }
        }

        //Sending Email To Buyer
        // $data = [
        //     'to' => $order->customer_email,
        //     'type' => "new_order",
        //     'cname' => $order->customer_name,
        //     'oamount' => "",
        //     'aname' => "",
        //     'aemail' => "",
        //     'wtitle' => "",
        //     'onumber' => $order->order_number,
        // ];

        // $mailer = new GeniusMailer();
        // $mailer->sendAutoOrderMail($data,$order->id);

        // //Sending Email To Admin
        // $data = [
        //     'to' => $this->ps->contact_email,
        //     'subject' => "New Order Recieved!!",
        //     'body' => "Hello Admin!<br>Your store has received a new order.<br>Order Number is ".$order->order_number.".Please login to your panel to check. <br>Thank you.",
        // ];
        // $mailer = new GeniusMailer();
        // $mailer->sendCustomMail($data);

        return redirect($success_url);
    }

}
