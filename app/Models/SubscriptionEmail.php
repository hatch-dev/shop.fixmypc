<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionEmail extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'source',
    ];

    /**
     * The surfaces a sign-up can come from.
     *
     * A closed list because `source` decides what a subscriber was promised:
     * CLEARANCE was told they would hear about clearance deals, NEWSLETTER
     * agreed to the monthly letter. Anything else arriving from the page is
     * not a consent this shop can evidence, so it is not recorded as one.
     */
    const SOURCE_CLEARANCE = 'clearance';
    const SOURCE_NEWSLETTER = 'newsletter';

    public static function sources()
    {
        return [self::SOURCE_CLEARANCE, self::SOURCE_NEWSLETTER];
    }
}
