<?php

/**
 * User Model
 *
 * @package     Makent
 * @subpackage  Model
 * @category    User
 * @author      Trioangle Product Team
 * @version     0.9.1
 * @link        http://trioangle.com
 */

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use DB;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Messages;
use DateTime;

class User extends Model implements AuthenticatableContract, CanResetPasswordContract
{
    use Authenticatable, CanResetPassword;

    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['first_name', 'last_name', 'email', 'password', 'dob'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['password', 'remember_token'];

    protected $appends = ['dob_dmy','age','full_name'];

    protected $dates = ['deleted_at'];

    // Join with profile_picture table
    public function profile_picture()
    {
        return $this->belongsTo('App\Models\ProfilePicture','id','user_id');
    }

    // Join with users_verification table
    public function users_verification()
    {
        return $this->belongsTo('App\Models\UsersVerification','id','user_id');
    }

    // Join with saved_wishlists table
    public function saved_wishlists()
    {
        return $this->belongsTo('App\Models\SavedWishlists','id','user_id');
    }

    // Join with wishlists table
    public function wishlists()
    {
        return $this->belongsTo('App\Models\Wishlists','id','user_id');
    }

    // Join with referrals table
    public function referrals()
    {
        return $this->belongsTo('App\Models\Referrals','id','user_id');
    }

    // Inbox unread message count
    public function inbox_count()
    {
        return Messages::where('user_to', $this->attributes['id'])->where('read', 0)->count();
    }

    // Join with reviews table
    public function reviews()
    {
        return $this->hasMany('App\Models\Reviews','user_to','id');
    }

    // Get status Active users count
    public static function count()
    {
        return DB::table('users')->whereStatus('Active')->count();
    }

    // Convert y-m-d date of birth date into d-m-y
    public function getDobDmyAttribute()
    {
        if(@$this->attributes['dob'] != '0000-00-00')
            return date('d-m-Y', strtotime(@$this->attributes['dob']));
        else
            return '';
    }

    public function getAgeAttribute()
    {
        $dob = @$this->attributes['dob'];
        if(!empty($dob) && $dob != '0000-00-00')
        {
            $birthdate = new DateTime($dob);
            $today   = new DateTime('today');
            $age = $birthdate->diff($today)->y;
            return $age;
        }
        else
        {
            return 0;
        }
    }

    public function getSinceAttribute()
    {
        return date('F Y', strtotime($this->attributes['created_at']));
    }

    public function getFullNameAttribute()
    {
        return ucfirst(@$this->attributes['first_name']).' '.ucfirst(@$this->attributes['last_name']);
    }

    public function getFirstNameAttribute()
    {
        return ucfirst($this->attributes['first_name']);
    }

    public function getLastNameAttribute()
    {
        return ucfirst($this->attributes['last_name']);
    }
}
