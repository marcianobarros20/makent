<?php

/**
 * Home Cities Model
 *
 * @package     Makent
 * @subpackage  Model
 * @category    Home Cities
 * @author      Trioangle Product Team
 * @version     0.9.1
 * @link        http://trioangle.com
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeCities extends Model
{
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'home_cities';

    public $timestamps = false;

    public $appends = ['image_url'];

    public function getImageUrlAttribute()
    {
    	return url().'/images/home_cities/'.$this->attributes['image'];
    }
}
