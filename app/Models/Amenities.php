<?php

/**
 * Amenities Model
 *
 * @package     Makent
 * @subpackage  Model
 * @category    Amenities
 * @author      Trioangle Product Team
 * @version     0.9.1
 * @link        http://trioangle.com
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use DB;

class Amenities extends Model
{
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'amenities';

    public $timestamps = false;

    // Get all Active status records
    public static function active_all()
    {
    	return Amenities::whereStatus('Active')->get();
    }

    // Get Selected All Amenities Data
    public static function selected($room_id)
    {
        $result = DB::select("select amenities.name as name, amenities.id as id, amenities.icon, rooms.id as status from amenities left join rooms on find_in_set(amenities.id, rooms.amenities) and rooms.id = $room_id");
        return $result;
    }

    // Get Selected Security Amenities Data
    public static function selected_security($room_id)
    {
        $result = DB::select("select amenities.name as name, amenities.id as id, amenities.icon, rooms.id as status from amenities left join rooms on find_in_set(amenities.id, rooms.amenities) and rooms.id = $room_id where type_id = 4");
        return $result;
    }
}
