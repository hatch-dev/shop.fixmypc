<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class HeaderLink extends Model
{
    use HasFactory;


    protected $fillable = [
        'title', 'url', 'type', 'admin_id'
    ];

    protected $hidden = [
        'admin_id'
    ];

    /**
     * Split header links into what the header shows where: the Left and
     * Right lists, in order, and the Hot Deals and Clearance buttons (one
     * row each, or null to keep the storefront's own label and address).
     *
     * Anything that was not Left used to land in Right, which would now pull
     * the two buttons into the Right list - and the Right list's save deletes
     * every row it was not sent.
     */
    public static function grouped($links)
    {
        $types = Config::get('constants.headerLinkType');
        $data = ['left' => [], 'right' => [], 'hot_deals' => null, 'clearance' => null];

        foreach ($links as $link) {
            switch ((int)$link->type) {
                case $types['LEFT']:
                    $data['left'][] = $link;
                    break;
                case $types['RIGHT']:
                    $data['right'][] = $link;
                    break;
                case $types['HOT_DEALS']:
                    $data['hot_deals'] = $link;
                    break;
                case $types['CLEARANCE']:
                    $data['clearance'] = $link;
                    break;
            }
        }

        return $data;
    }

}


