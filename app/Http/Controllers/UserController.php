<?php

/**
 * User Controller
 *
 * @package     Makent
 * @subpackage  Controller
 * @category    User
 * @author      Trioangle Product Team
 * @version     0.9.1
 * @link        http://trioangle.com
 */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Start\Helpers;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Http\Helper\FacebookHelper;
use Auth;
use Validator;
use App\Models\User;
use App\Models\ProfilePicture;
use App\Models\Country;
use App\Models\Timezone;
use App\Models\PasswordResets;
use App\Models\Messages;
use App\Models\PayoutPreferences;
use App\Models\Rooms;
use App\Models\Payouts;
use App\Models\Reviews;
use App\Models\Reservation;
use App\Models\UsersVerification;
use App\Models\Wishlists;
use App\Models\ReferralSettings;
use App\Models\Referrals;
use Socialite;  // This package have all social media API integration
use Mail;
use DateTime;
use Hash;
use Excel;
use DB;
use Image;
use Session;
use App\Http\Controllers\EmailController;

// Facebook API
use Facebook\Exceptions\FacebookResponseException;
use Facebook\Exceptions\FacebookSDKException;

class UserController extends Controller
{
    protected $helper; // Global variable for Helpers instance
    private $fb;    // Global variable for FacebookHelper instance
    
    public function __construct(FacebookHelper $fb)
    {
        $this->fb = $fb;
        $this->helper = new Helpers;
    }

    /**
     * Create a new Email signup user
     *
     * @param array $request    Post method inputs
     * @return redirect     to dashboard page
     */
    public function create(Request $request, EmailController $email_controller)
    {
        // Email signup validation rules
         $rules = array(
        'first_name'      => 'required|max:255',
        'last_name'       => 'required|max:255',
        'email'           => 'required|max:255|email|unique:users',
        'password'        => 'required|min:6',
        'birthday_day'    => 'required',
        'birthday_month'  => 'required',
        'birthday_year'   => 'required',
        );

        // Email signup validation custom messages
        $messages = array(
        'required'                => ':attribute is required.',
        'birthday_day.required'   => 'Select your birth date to continue.',
        'birthday_month.required' => 'Select your birth date to continue.',
        'birthday_year.required'  => 'Select your birth date to continue.',
        );

        // Email signup validation custom Fields name
        $niceNames = array(
        'first_name'      => 'First name',
        'last_name'       => 'Last name',
        'email'           => 'Email',
        'password'        => 'Password',
        );

        $validator = Validator::make($request->all(), $rules, $messages);
        $validator->setAttributeNames($niceNames); 

        if ($validator->fails()) 
        {
            return back()->withErrors($validator)->withInput(); // Form calling with Errors and Input values
        }
        else
        {
            $user = new User;

            $user->first_name   =   $request->first_name;
            $user->last_name    =   $request->last_name;
            $user->email        =   $request->email;
            $user->password     =   bcrypt($request->password);
            $user->dob          =   $request->birthday_year.'-'.
                                    $request->birthday_month.'-'.
                                    $request->birthday_day; // Date format - Y-m-d

            $user->save();  // Create a new user

            $user_pic = new ProfilePicture;

            $user_pic->user_id      =   $user->id;
            $user_pic->src          =   "";
            $user_pic->photo_source =   'Local';

            $user_pic->save();  // Create a profile picture record

            $user_verification = new UsersVerification;

            $user_verification->user_id      =   $user->id;

            $user_verification->save();  // Create a users verification record

            $email_controller->welcome_email_confirmation($user);

            if(Session::get('referral')) {

            $referral_settings = ReferralSettings::first();

            $referral_check = Referrals::whereUserId(Session::get('referral'))->sum('creditable_amount');

            if($referral_check < $referral_settings->value(1)) {
                $referral = new Referrals;

                $referral->user_id                = Session::get('referral');
                $referral->friend_id              = $user->id;
                $referral->friend_credited_amount = $referral_settings->value(4);
                $referral->if_friend_guest_amount = $referral_settings->value(2);
                $referral->if_friend_host_amount  = $referral_settings->value(3);
                $referral->creditable_amount      = $referral_settings->value(2) + $referral_settings->value(3);
                $referral->currency_code          = $referral_settings->value(5, 'code');

                $referral->save();

                Session::forget('referral');
            }
            }
            
            if(Auth::user()->attempt(['email' => $request->email, 'password' => $request->password]))
            {
                $this->helper->flash_message('success', trans('messages.login.reg_successfully')); // Call flash message function
                return redirect()->intended('dashboard'); // Redirect to dashboard page
            }
            else
            {
                $this->helper->flash_message('danger', trans('messages.login.login_failed')); // Call flash message function
                return redirect('login'); // Redirect to login page
            }
        }
    }
    
    /**
     * Email users Login authentication
     *
     * @param array $request    Post method inputs
     * @return redirect     to dashboard page
     */
    public function authenticate(Request $request)
    {
        // Email login validation rules
        $rules = array(
        'email'           => 'required|email',
        'password'        => 'required'
        );

        // Email login validation custom messages
        $messages = array(
        'required'        => ':attribute is required.'
        );  

        // Email login validation custom Fields name
        $niceNames = array(
        'email'           => 'Email',
        'password'        => 'Password',
        );

        // set the remember me cookie if the user check the box
        $remember = ($request->remember_me) ? true : false;

        $validator = Validator::make($request->all(), $rules, $messages);
        $validator->setAttributeNames($niceNames);

        if ($validator->fails()) 
        {
            return back()->withErrors($validator)->withInput(); // Form calling with Errors and Input values
        }
        else
        {
            // Get user status
            $users = User::where('email', $request->email)->first();
            
            // Check user is active or not
            if(@$users->status != 'Inactive')
            {
            if(Auth::user()->attempt(['email' => $request->email, 'password' => $request->password], $remember))
            {
                return redirect()->intended('dashboard'); // Redirect to dashboard page
            }
            else
            {
                $this->helper->flash_message('danger', trans('messages.login.login_failed')); // Call flash message function
                return redirect('login'); // Redirect to login page
            } 
            }
            else    // Call Disabled view file for Inactive user
            {
                $data['title'] = 'Disabled ';
                return view('users.disabled', $data);
            }
        }
    }

    /**
     * Facebook User Registration and Login
     *
     * @return redirect     to dashboard page
     */
    public function facebookAuthenticate(EmailController $email_controller)
    {
        $this->fb->generateSessionFromRedirect(); // Generate Access Token Session After Redirect from Facebook

        $response = $this->fb->getData(); // Get Facebook Response

        $userNode = $response->getGraphUser(); // Get Authenticated User Data
        
        $email = ($userNode->getProperty('email') == '') ? $userNode->getId().'@fb.com' : $userNode->getProperty('email');
        
        $user = User::where('email',$email); // Check Facebook User Email Id is exists

        if($user->count() > 0 )  // If there update Facebook Id
        {
            $user = User::where('email',$email)->first();

            $user->fb_id  = $userNode->getId();

            $user->save();  // Update a Facebook id

            $user_id = $user->id; // Get Last Updated Id
        }
        else // If not create a new user without Password
        {
            $user = User::where('email', $email)->withTrashed();

            if($user->count() > 0)
            {
                $data['title'] = 'Disabled ';
                return view('users.disabled', $data);
            }

            $user = new User;

            // New user data
            $user->first_name   =   $userNode->getFirstName();
            $user->last_name    =   $userNode->getLastName();
            $user->email        =   $email;
            $user->fb_id        =   $userNode->getId();

            $user->save(); // Create a new user

            $user_id = $user->id; // Get Last Insert Id

            $user_pic = new ProfilePicture;

            $user_pic->user_id      =   $user_id;
            $user_pic->src          =   "https://graph.facebook.com/".$userNode->getId()."/picture?type=large";
            $user_pic->photo_source =   'Facebook';

            $user_pic->save(); // Save Facebook profile picture

            $user_verification = new UsersVerification;

            $user_verification->user_id      =   $user->id;

            $user_verification->save();  // Create a users verification record

            $email_controller->welcome_email_confirmation($user);

            if(Session::get('referral')) {
            $referral_settings = ReferralSettings::first();

            $referral_check = Referrals::whereUserId(Session::get('referral'))->sum('creditable_amount');

            if($referral_check < $referral_settings->value(1)) {
                $referral = new Referrals;

                $referral->user_id                = Session::get('referral');
                $referral->friend_id              = $user->id;
                $referral->friend_credited_amount = $referral_settings->value(4);
                $referral->if_friend_guest_amount = $referral_settings->value(2);
                $referral->if_friend_host_amount  = $referral_settings->value(3);
                $referral->creditable_amount      = $referral_settings->value(2) + $referral_settings->value(3);
                $referral->currency_code          = $referral_settings->value(5, 'code');

                $referral->save();

                Session::forget('referral');
            }
            }
        }

        $users = User::where('id', $user_id)->first();
        
        if(@$users->status != 'Inactive')
        {
            if(Auth::user()->loginUsingId($user_id)) // Login without using User Id instead of Email and Password
            {
                return redirect()->intended('dashboard'); // Redirect to dashboard page
            }
            else
            {
                $this->helper->flash_message('danger', trans('messages.login.login_failed')); // Call flash message function
                return redirect('login'); // Redirect to login page
            }
        }
        else // Call Disabled view file for Inactive user
        {
            $data['title'] = 'Disabled ';
            return view('users.disabled', $data);
        } 
    }

    /**
     * Google User redirect to Google Authentication page
     *
     * @return redirect     to Google page
     */
    public function googleLogin()
    {
        return Socialite::with('google')->redirect();
    }

    /**
     * Google User Registration and Login
     *
     * @return redirect     to dashboard page
     */
    public function googleAuthenticate(EmailController $email_controller)
    {
        $userNode = Socialite::with('google')->user();

        if(Session::get('verification') == 'yes') {
            return redirect('googleConnect/'.$userNode->getId());
        }

        $ex_name = explode(' ',$userNode->getName());
        $firstName = $ex_name[0];
        $lastName = $ex_name[1];
        
        $email = ($userNode->getEmail() == '') ? $userNode->getId().'@gmail.com' : $userNode->getEmail();
        
        $user = User::where('email',$email); // Check Google User Email Id is exists

        if($user->count() > 0 )  // If there update Google Id
        {
            $user = User::where('email',$email)->first();

            $user->google_id  = $userNode->getId();

            $user->save();  // Update a Google id

            $user_id = $user->id; // Get Last Updated Id
        }
        else // If not create a new user without Password
        {
            $user = User::where('email', $email)->withTrashed();

            if($user->count() > 0)
            {
                $data['title'] = 'Disabled ';
                return view('users.disabled', $data);
            }
            
            $user = new User;

            // New user data
            $user->first_name   =   $firstName;
            $user->last_name    =   $lastName;
            $user->email        =   $email;
            $user->google_id    =   $userNode->getId();

            $user->save(); // Create a new user

            $user_id = $user->id; // Get Last Insert Id

            $user_pic = new ProfilePicture;

            $user_pic->user_id      =   $user_id;
            $user_pic->src          =   $userNode->getAvatar();
            $user_pic->photo_source =   'Google';

            $user_pic->save(); // Save Google profile picture

            $user_verification = new UsersVerification;

            $user_verification->user_id      =   $user->id;

            $user_verification->save();  // Create a users verification record

            $email_controller->welcome_email_confirmation($user);

            if(Session::get('referral')) {
                
            $referral_settings = ReferralSettings::first();

            $referral_check = Referrals::whereUserId(Session::get('referral'))->sum('creditable_amount');

            if($referral_check < $referral_settings->value(1)) {
                $referral = new Referrals;

                $referral->user_id                = Session::get('referral');
                $referral->friend_id              = $user->id;
                $referral->friend_credited_amount = $referral_settings->value(4);
                $referral->if_friend_guest_amount = $referral_settings->value(2);
                $referral->if_friend_host_amount  = $referral_settings->value(3);
                $referral->creditable_amount      = $referral_settings->value(2) + $referral_settings->value(3);
                $referral->currency_code          = $referral_settings->value(5, 'code');

                $referral->save();

                Session::forget('referral');
            }
            }
        }

        $users = User::where('id', $user_id)->first();
        
        if(@$users->status != 'Inactive')
        {
            if(Auth::user()->loginUsingId($user_id)) // Login without using User Id instead of Email and Password
            {
                return redirect()->intended('dashboard'); // Redirect to dashboard page
            }
            else
            {
                $this->helper->flash_message('danger', trans('messages.login.login_failed')); // Call flash message function
                return redirect('login'); // Redirect to login page
            } 
        }
        else // Call Disabled view file for Inactive user
        {
            $data['title'] = 'Disabled ';
            return view('users.disabled', $data);
        }
    }

    /**
     * Load Dashboard view file
     *
     * @return dashboard view file
     */
    public function dashboard()
    {        
        $data['user_id'] = Auth::user()->user()->id;

        $data['all_message']  = Messages::whereIn('id', function($query)
                {
                    $query->select(DB::raw('max(id)'))
                    ->from('messages')->groupby('reservation_id');
                })->with(['user_details' => function($query) {
                                $query->with('profile_picture');
                            }])->with(['reservation' => function($query) {
                                $query->with('currency');
                            }])->with('rooms_address')->where('user_to', $data['user_id'])->where('read',0)->orderBy('id','desc')->get();
       
        return view('users.guest_dashboard', $data);
    }

    /**
     * Load Forgot Password View and Send Reset Link
     *
     * @return view forgot password page / send mail to user
     */
    public function forgot_password(Request $request, EmailController $email_controller)
    {
        if(!$_POST)
        {
            return view('home.forgot_password');
        }
        else
        {
            // Email validation rules
            $rules = array(
            'email'           => 'required|email|exists:users,email'
            );

            // Email validation custom messages
            $messages = array(
            'required'        => ':attribute is required.',
            'exists'          => 'No account exists for this email.'
            );

            // Email validation custom Fields name
            $niceNames = array(
            'email'           => 'Email'
            );

            $validator = Validator::make($request->all(), $rules, $messages);
            $validator->setAttributeNames($niceNames);

            if ($validator->fails()) 
            {
                return back()->withErrors($validator)->withInput(); // Form calling with Errors and Input values
            }
            else
            {
                $user = User::whereEmail($request->email)->first();

                $email_controller->forgot_password($user);

                $this->helper->flash_message('success', trans('messages.login.reset_link_sent',['email'=>$user->email])); // Call flash message function
                return redirect('login');
            }
        }
    }

    /**
     * Set Password View and Update Password
     *
     * @param array $request Input values
     * @return view set_password / redirect to Login
     */
    public function set_password(Request $request)
    {
        if(!$_POST)
        {
            $password_resets = PasswordResets::whereToken($request->secret);
            
            if($password_resets->count())
            {
                $password_result = $password_resets->first();

                $datetime1 = new DateTime();
                $datetime2 = new DateTime($password_result->created_at);
                $interval  = $datetime1->diff($datetime2);
                $hours     = $interval->format('%h');

                if($hours >= 1)
                {
                    // Delete used token from password_resets table
                    $password_resets->delete();

                    $this->helper->flash_message('error', trans('messages.login.token_expired')); // Call flash message function
                    return redirect('login');
                }

                $data['result'] = User::whereEmail($password_result->email)->first();
                $data['token']  = $request->secret;

                return view('home.set_password', $data);
            }
            else
            {
                $this->helper->flash_message('error', trans('messages.login.invalid_token')); // Call flash message function
                return redirect('login');
            }
        }
        else
        {
            // Password validation rules
            $rules = array(
            'password'              => 'required|min:6|max:30',
            'password_confirmation' => 'required|same:password'
            );

            // Password validation custom Fields name
            $niceNames = array(
            'password'              => 'New Password',
            'password_confirmation' => 'Confirm Password'
            );

            $validator = Validator::make($request->all(), $rules);
            $validator->setAttributeNames($niceNames);

            if ($validator->fails()) 
            {
                return back()->withErrors($validator)->withInput(); // Form calling with Errors and Input values
            }
            else
            {
                // Delete used token from password_resets table
                $password_resets = PasswordResets::whereToken($request->token)->delete();

                $user = User::find($request->id);

                $user->password = bcrypt($request->password);

                $user->save(); // Update Password in users table

                $this->helper->flash_message('success', trans('messages.login.pwd_changed')); // Call flash message function
                return redirect('login');
            }
        }
    }

    /**
     * Load Edit profile view file with user dob, timezone and country
     *
     * @return edit profile view file
     */
    public function edit()
    {
        $data['timezone'] = Timezone::get()->lists('name', 'value');
        $data['country'] = Country::get()->lists('long_name', 'short_name');

        $data['dob'] = explode('-', Auth::user()->user()->dob);
        
        return view('users.edit', $data);
    }

    /**
     * Update edit profile page data
     *
     * @return redirect     to Edit profile
     */
    public function update(Request $request, EmailController $email_controller)
    {
        $user = User::find($request->id);

        $new_email = ($user->email != $request->email) ? 'yes' : 'no';

        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->gender = $request->gender;
        $user->dob = $request->birthday_year.'-'.$request->birthday_month.'-'.$request->birthday_day;
        $user->email = $request->email;
        $user->live = $request->live;
        $user->about = $request->about;
        $user->school = $request->school;
        $user->work = $request->work;
        $user->timezone = $request->timezone;

        $user->save(); // Update user profile details

        if($new_email == 'yes')
        {
            $email_controller->change_email_confirmation($user);

            $this->helper->flash_message('success', trans('messages.profile.confirm_link_sent',['email'=>$user->email])); // Call flash message function
        }
        else
        {
            $this->helper->flash_message('success', trans('messages.profile.profile_updated')); // Call flash message function
        }

        return redirect('users/edit');
    }

    /**
     * Confirm email for new email update
     *
     * @param array $request Input values
     * @return redirect to dashboard
     */
    public function confirm_email(Request $request)
    {
        $password_resets = PasswordResets::whereToken($request->code);
        
        if($password_resets->count() && Auth::user()->user()->email == $password_resets->first()->email)
        {
            $password_result = $password_resets->first();

            $datetime1 = new DateTime();
            $datetime2 = new DateTime($password_result->created_at);
            $interval  = $datetime1->diff($datetime2);
            $hours     = $interval->format('%h');

            if($hours >= 1)
            {
                // Delete used token from password_resets table
                $password_resets->delete();

                $this->helper->flash_message('error', trans('messages.login.token_expired')); // Call flash message function
                return redirect('login');
            }

            $data['result'] = User::whereEmail($password_result->email)->first();
            $data['token']  = $request->code;

            $user = User::find($data['result']->id);

            $user->status = "Active";

            $user->save();

            $user_verification = UsersVerification::find($data['result']->id);

            $user_verification->email        =   'yes';

            $user_verification->save();  // Create a users verification record

            // Delete used token from password_resets table
            $password_resets->delete();

            $this->helper->flash_message('success', trans('messages.profile.email_confirmed')); // Call flash message function
            return redirect('dashboard');
        }
        else
        {
            $this->helper->flash_message('error', trans('messages.login.invalid_token')); // Call flash message function
            return redirect('dashboard');
        }
    }

    /**
     * User Profile Page
     *
     * @return view profile page
     */
    public function show(Request $request)
    {
        $data['result'] = User::find($request->id);

        $data['reviews_from_guests'] = Reviews::where(['user_to'=>$request->id, 'review_by'=>'guest']);
        $data['reviews_from_hosts'] = Reviews::where(['user_to'=>$request->id, 'review_by'=>'host']);

        $data['reviews_count'] = $data['reviews_from_guests']->count() + $data['reviews_from_hosts']->count();

        $data['wishlists'] = Wishlists::with(['saved_wishlists' => function($query){
                $query->with(['rooms']);
            }, 'profile_picture'])->where('user_id', $request->id)->wherePrivacy(0)->orderBy('id', 'desc')->get();

        $data['title'] = $data['result']->first_name."'s Profile ";

        return view('users.profile', $data);
    }

    /**
     * User Account Security Page
     *
     * @param array $request Input values
     * @return view security page
     */
    public function security(Request $request)
    {
        return view('account.security');
    }

    /**
     * User Change Password
     *
     * @param array $request Input values
     * @return redirect     to Security page
     */
    public function change_password(Request $request)
    {
        // Password validation rules
        $rules = array(
        'old_password'          => 'required',
        'new_password'          => 'required|min:6|max:30|different:old_password',
        'password_confirmation' => 'required|same:new_password|different:old_password'
        );

        // Password validation custom Fields name
        $niceNames = array(
        'old_password'          => 'Old Password',
        'new_password'          => 'New Password',
        'password_confirmation' => 'Confirm Password'
        );

        $validator = Validator::make($request->all(), $rules);
        $validator->setAttributeNames($niceNames);

        if ($validator->fails()) 
        {
            return back()->withErrors($validator)->withInput(); // Form calling with Errors and Input values
        }
        else
        {
            $user = User::find(Auth::user()->user()->id);

            if(!Hash::check($request->old_password, $user->password))
            {
                return back()->withInput()->withErrors(['old_password' => trans('messages.profile.pwd_not_correct')]);
            }

            $user->password = bcrypt($request->new_password);

            $user->save(); // Update password

            $this->helper->flash_message('success', trans('messages.profile.pwd_updated')); // Call flash message function
            return redirect('users/security');
        }
    }

    /**
     * Add a Payout Method and Load Payout Preferences File
     *
     * @param array $request Input values
     * @return redirect to Payout Preferences page and load payout preferences view file
     */
    public function payout_preferences(Request $request, EmailController $email_controller)
    {
        if(!$request->address1)
        {
            $data['payouts'] = PayoutPreferences::where('user_id', Auth::user()->user()->id)->orderBy('id','desc')->get();
            $data['country']   = Country::all()->lists('long_name','short_name');
            return view('account/payout_preferences', $data);
        }
        else
        {
            $payout     =   new PayoutPreferences;

            $payout->user_id       = Auth::user()->user()->id;
            $payout->address1      = $request->address1;
            $payout->address2      = $request->address2;
            $payout->city          = $request->city;
            $payout->state         = $request->state;
            $payout->postal_code   = $request->postal_code;
            $payout->country       = $request->country;
            $payout->payout_method = $request->payout_method;
            $payout->paypal_email  = $request->paypal_email;
            $payout->currency_code = 'EUR';
            
            $payout->save();

            $payout_check = PayoutPreferences::where('user_id', Auth::user()->user()->id)->where('default','yes')->get();

            if($payout_check->count() == 0)
            {
                $payout->default = 'yes';
                $payout->save();
            }

            $email_controller->payout_preferences($payout->id);

            $this->helper->flash_message('success', trans('messages.account.payout_updated')); // Call flash message function
            return redirect('users/payout_preferences/'.Auth::user()->user()->id);
        }
    }

    /**
     * Delete Payouts Default Payout Method
     *
     * @param array $request Input values
     * @return redirect to Payout Preferences page
     */
    public function payout_delete(Request $request, EmailController $email_controller)
    {
        $payout = PayoutPreferences::find($request->id);

        if($payout->default == 'yes')
        {
            $this->helper->flash_message('error', trans('messages.account.payout_default')); // Call flash message function
            return redirect('users/payout_preferences/'.Auth::user()->user()->id);
        }
        else
        {
            $payout->delete();

            $email_controller->payout_preferences($payout->id, 'delete');

            $this->helper->flash_message('success', trans('messages.account.payout_deleted')); // Call flash message function
            return redirect('users/payout_preferences/'.Auth::user()->user()->id);
        }
    }

    /**
     * Update Payouts Default Payout Method
     *
     * @param array $request Input values
     * @return redirect to Payout Preferences page
     */
    public function payout_default(Request $request, EmailController $email_controller)
    {
        $payout = PayoutPreferences::find($request->id);

        if($payout->default == 'yes')
        {
            $this->helper->flash_message('error', trans('messages.account.payout_already_defaulted')); // Call flash message function
            return redirect('users/payout_preferences/'.Auth::user()->user()->id);
        }
        else
        {
            $payout_all = PayoutPreferences::where('user_id',Auth::user()->user()->id)->update(['default'=>'no']);

            $payout->default = 'yes';
            $payout->save();

            $email_controller->payout_preferences($payout->id, 'default_update');

            $this->helper->flash_message('success', trans('messages.account.payout_defaulted')); // Call flash message function
            return redirect('users/payout_preferences/'.Auth::user()->user()->id);
        }
    }

    /**
     * Load Transaction History Page
     *
     * @param array $request Input values
     * @return view Transaction History
     */
    public function transaction_history(Request $request)
    {
        $data['lists'] = Rooms::where('user_id', Auth::user()->user()->id)->lists('name','id');
        $data['payout_methods'] = PayoutPreferences::where('user_id', Auth::user()->user()->id)->lists('paypal_email','id');

        $data['from_month'] = [];
        $data['to_month'] = [];
        $data['payout_year'] = [];

        for($i=1; $i<=12; $i++)
            $data['from_month'][$i] = 'From: '.date("F", mktime(0, 0, 0, $i, 10));

        for($i=1; $i<=12; $i++)
            $data['to_month'][$i] = 'To: '.date("F", mktime(0, 0, 0, $i, 10));

        $user_year = date('Y', strtotime(Auth::user()->user()->created_at));

        for($i=$user_year; $i<=date('Y'); $i++)
            $data['payout_year'][$i] = $i;
        
        return view('account.transaction_history', $data);
    }

    /**
     * Ajax Transaction History
     *
     * @param array $request Input values
     * @return json Payouts data
     */
    public function result_transaction_history(Request $request)
    {
        $data  = $request;

        $data  = json_decode($data['data']);
        
        $transaction = $this->transaction_result($data);

        $transaction_result = $transaction->paginate(10)->toJson();

        echo $transaction_result;
    }

    /**
     * Export Transaction History CSV file
     *
     * @param array $request Input values
     * @return file Exported CSV File
     */
    public function transaction_history_csv(Request $request)
    {
        $data  = $request;
        $limit = $request->page.'0';
        $offset = ($request->page-1).'0';

        $transaction = $this->transaction_result($data);

        $transaction = $transaction->skip($offset)->take($limit)->get();
        $transaction = $transaction->toArray();

        for($i=0; $i<count($transaction); $i++)
        {
            $transaction[$i]['type'] = 'Payout';
            $transaction[$i] = array_only($transaction[$i], ['type','date','account','currency_code','amount']);
        }

        Excel::create(strtolower($data->type).'-history', function($excel) use($transaction) {
            $excel->sheet('sheet1', function($sheet) use($transaction) {
                $sheet->fromArray($transaction);
            });
        })->export('csv');
    }

    /**
     * Transactio History Result
     *
     * @param array $data Payouts detail
     * @return array Payouts data
     */
    public function transaction_result($data)
    {
        $type          = $data->type;
        $payout_method = $data->payout_method;
        $listing       = $data->listing;
        $year          = @$data->year;
        $start_month   = @$data->start_month;
        $end_month     = @$data->end_month;

        if($type == 'completed-transactions')
            $status = 'Completed';
        else if($type == 'future-transactions')
            $status = 'Future';

        $where['user_id'] = Auth::user()->user()->id;
        $where['status']  = $status;

        if($payout_method)
            $where['account'] = PayoutPreferences::find($payout_method)->paypal_email;

        if($listing)
            $where['room_id'] = $listing;

        if($status == 'Completed')
            $transaction = Payouts::where($where)->whereYear('updated_at', '=', $year)->whereMonth('updated_at', '>=', $start_month)->whereMonth('updated_at', '<=', $end_month);
        else if($status == 'Future')
            $transaction = Payouts::where($where);

        return $transaction;
    }

    /**
     * Load Reviews for both Guest and Host with Previous reviews
     *
     * @param array $request Input values
     * @return view User Reviews file
     */
    public function reviews(Request $request)
    {
        $data['reviews_about_you'] = Reviews::where('user_to', Auth::user()->user()->id)->orderBy('id', 'desc')->get();
        $data['reviews_by_you'] = Reviews::where('user_from', Auth::user()->user()->id)->orderBy('id', 'desc')->get();

        $data['reviews_to_write'] = Reservation::with(['reviews'])->whereRaw('DATEDIFF(now(),checkout) <= 14')->whereRaw('DATEDIFF(now(),checkout) >= 1')->where(['status'=>'Accepted'])->where(function($query) {
                return $query->where('user_id', Auth::user()->user()->id)->orWhere('host_id', Auth::user()->user()->id);
            })->get();

        $data['expired_reviews'] = Reservation::with(['reviews'])->whereRaw('DATEDIFF(now(),checkout) > 14')->where(function($query) {
                return $query->where('user_id', Auth::user()->user()->id)->orWhere('host_id', Auth::user()->user()->id);
            })->get();

        $data['reviews_to_write_count'] = 0;

        for($i=0; $i<$data['reviews_to_write']->count(); $i++) {
            if($data['reviews_to_write'][$i]->review_days > 0 && $data['reviews_to_write'][$i]->reviews->count() < 2) {
                if($data['reviews_to_write'][$i]->reviews->count() == 0)
                    $data['reviews_to_write_count'] += 1;
                for($j=0; $j<$data['reviews_to_write'][$i]->reviews->count(); $j++) {
                    if(@$data['reviews_to_write'][$i]->reviews[$j]->user_from != Auth::user()->user()->id)
                        $data['reviews_to_write_count'] += 1;
                }
            }
        }

        $data['expired_reviews_count'] = 0;

        for($i=0; $i<$data['expired_reviews']->count(); $i++) {
            if($data['expired_reviews'][$i]->review_days <= 0 && $data['expired_reviews'][$i]->reviews->count() < 2) {
                if($data['expired_reviews'][$i]->reviews->count() == 0)
                    $data['expired_reviews_count'] += 1;
                for($j=0; $j<$data['expired_reviews'][$i]->reviews->count(); $j++) {
                    if(@$data['expired_reviews'][$i]->reviews->user_from != Auth::user()->user()->id)
                        $data['expired_reviews_count'] += 1;
                }
            }
        }
        
        return view('users.reviews', $data);
    }

    /**
     * Edit Reviews for both Guest and Host
     *
     * @param array $request Input values
     * @return json success and review_id
     */
    public function reviews_edit(Request $request)
    {
        $data['result'] = $reservation_details = Reservation::find($request->id);

        if(Auth::user()->user()->id == $reservation_details->user_id) {
            $reviews_check = Reviews::where(['reservation_id'=>$request->id, 'review_by'=>'guest'])->get();
            $data['review_id'] = ($reviews_check->count()) ? $reviews_check[0]->id : '';
        }
        else {
            $reviews_check = Reviews::where(['reservation_id'=>$request->id, 'review_by'=>'host'])->get();
            $data['review_id'] = ($reviews_check->count()) ? $reviews_check[0]->id : '';
        }

        if(!$request->data) {
            if($reservation_details->user_id == Auth::user()->user()->id)
                return view('users.reviews_edit_guest', $data);
            else if($reservation_details->host_id == Auth::user()->user()->id)
                return view('users.reviews_edit_host', $data);
        }
        else {
            $data  = $request;
            $data  = json_decode($data['data']);

            if($data->review_id == '')
                $reviews = new Reviews;
            else
                $reviews = Reviews::find($data->review_id);

            $reviews->reservation_id = $reservation_details->id;
            $reviews->room_id = $reservation_details->room_id;

            if($reservation_details->user_id == Auth::user()->user()->id) {
                $reviews->user_from = $reservation_details->user_id;
                $reviews->user_to = $reservation_details->host_id;
                $reviews->review_by = 'guest';
            }
            else if($reservation_details->host_id == Auth::user()->user()->id) {
                $reviews->user_from = $reservation_details->host_id;
                $reviews->user_to = $reservation_details->user_id;
                $reviews->review_by = 'host';
            }

            foreach($data as $key=>$value) {
                if($key != 'section' && $key != 'review_id') {
                    $reviews->$key = $value;
                }
            }
            $reviews->save();
            
            return json_encode(['success'=>true, 'review_id'=>$reviews->id]);
        }
    }

    /**
     * Load User Media page
     *
     * @return view User Media file
     */
    public function media()
    {
        $data['result'] = User::find(Auth::user()->user()->id);

        return view('users.media', $data);
    }

    /**
     * User Profile Image Upload
     *
     * @param array $request Input values
     * @return redirect to User Media Page
     */
    public function image_upload(Request $request)
    {
        $image  =   $request->file('profile_pic');

        if($image) {
            $extension      =   $image->getClientOriginalExtension();
            $filename       =   'profile_pic_' . time() . '.' . $extension;
            $imageRealPath  =   $image->getRealPath();
                    
            $img = Image::make($imageRealPath);

            $path = dirname($_SERVER['SCRIPT_FILENAME']).'/images/users/'.$request->user_id;
                                
            if(!file_exists($path)) {
                mkdir(dirname($_SERVER['SCRIPT_FILENAME']).'/images/users/'.$request->user_id, 0777, true);
            }

            $success = $img->save('images/users/'.$request->user_id.'/'.$filename);
        
            if(!$success) {
                $this->helper->flash_message('error', trans('messages.profile.cannot_upload')); // Call flash message function
                return back();
            }

            $user_pic = ProfilePicture::find($request->user_id);

            $user_pic->user_id      =   $request->user_id;
            $user_pic->src          =   $filename;
            $user_pic->photo_source =   'Local';

            $user_pic->save();  // Update a profile picture record

            $this->helper->flash_message('success', trans('messages.profile.picture_uploaded')); // Call flash message function
            return redirect('users/edit/media');
        }
    }

    /**
     * Send New Confirmation Email
     *
     * @param array $request Input values
     * @param array $email_controller Instance of EmailController
     * @return redirect to Dashboard
     */
    public function request_new_confirm_email(Request $request, EmailController $email_controller)
    {
        $user = User::find(Auth::user()->user()->id);

        $email_controller->new_email_confirmation($user);

        $this->helper->flash_message('success', trans('messages.profile.new_confirm_link_sent',['email'=>$user->email])); // Call flash message function
        if($request->redirect == 'verification')
            return redirect('users/edit_verification');
        else
            return redirect('dashboard');
    }

    public function verification(Request $request)
    {
        $data['fb_url'] = $this->fb->getUrlConnect();

        return view('users.verification', $data);
    }

    public function facebookConnect(Request $request)
    {
        $this->fb->generateSessionFromRedirect(); // Generate Access Token Session After Redirect from Facebook

        $response = $this->fb->getData(); // Get Facebook Response

        $userNode = $response->getGraphUser(); // Get Authenticated User Data

        $fb_id = $userNode->getId();

        $verification = UsersVerification::find(Auth::user()->user()->id);

        $verification->facebook = 'yes';
        $verification->fb_id = $fb_id;

        $verification->save();

        $this->helper->flash_message('success', trans('messages.profile.connected_successfully', ['social'=>'Facebook'])); // Call flash message function
        return redirect('users/edit_verification');
    }

    public function facebookDisconnect(Request $request)
    {
        $verification = UsersVerification::find(Auth::user()->user()->id);

        $verification->facebook = 'no';
        $verification->fb_id = '';

        $verification->save();

        $this->helper->flash_message('success', trans('messages.profile.disconnected_successfully', ['social'=>'Facebook'])); // Call flash message function
        return redirect('users/edit_verification');
    }

    /**
     * Google User redirect to Google Authentication page
     *
     * @return redirect     to Google page
     */
    public function googleLoginVerification()
    {
        Session::put('verification', 'yes');
        return Socialite::with('google')->redirect();
    }

    public function googleConnect(Request $request)
    {
        $google_id = $request->id;

        $verification = UsersVerification::find(Auth::user()->user()->id);

        $verification->google = 'yes';
        $verification->google_id = $google_id;

        $verification->save();

        $this->helper->flash_message('success', trans('messages.profile.connected_successfully', ['social'=>'Google'])); // Call flash message function
        return redirect('users/edit_verification');
    }

    public function googleDisconnect(Request $request)
    {
        $verification = UsersVerification::find(Auth::user()->user()->id);

        $verification->google = 'no';
        $verification->google_id = '';

        $verification->save();

        $this->helper->flash_message('success', trans('messages.profile.disconnected_successfully', ['social'=>'Google'])); // Call flash message function
        return redirect('users/edit_verification');
    }

    /**
     * LinkedIn User redirect to LinkedIn Authentication page
     *
     * @return redirect     to LinkedIn page
     */
    public function linkedinLoginVerification()
    {
        return Socialite::driver('linkedin')->redirect();
    }

    public function linkedinConnect(Request $request)
    {
        $userNode = Socialite::driver('linkedin')->user();
        
        $linkedin_id = $userNode->getId();

        $verification = UsersVerification::find(Auth::user()->user()->id);

        $verification->linkedin = 'yes';
        $verification->linkedin_id = $linkedin_id;

        $verification->save();

        $this->helper->flash_message('success', trans('messages.profile.connected_successfully', ['social'=>'LinkedIn'])); // Call flash message function
        return redirect('users/edit_verification');
    }

    public function linkedinDisconnect(Request $request)
    {
        $verification = UsersVerification::find(Auth::user()->user()->id);

        $verification->linkedin = 'no';
        $verification->linkedin_id = '';

        $verification->save();

        $this->helper->flash_message('success', trans('messages.profile.disconnected_successfully', ['social'=>'LinkedIn'])); // Call flash message function
        return redirect('users/edit_verification');
    }
}
