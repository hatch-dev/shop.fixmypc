<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuthBanner extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'eyebrow',
        'title',
        'sub',
        'cta',
        'href',
        'href_new_tab',
        'image',
        'image_x',
        'image_y',
        'image_w',
        'image_h',
        'bg_from',
        'bg_to',
        'text_color',
        'placement',
        'starts_at',
        'ends_at',
        'sort',
        'status'
    ];

    protected $casts = [
        'sort' => 'integer',
        'status' => 'integer',
        'href_new_tab' => 'integer',
        'image_x' => 'integer',
        'image_y' => 'integer',
        'image_w' => 'integer',
        'image_h' => 'integer'
    ];

    /**
     * Screens a banner can be pinned to. "auth" means every auth screen, the
     * rest name a single one.
     */
    public const PLACEMENTS = ['auth', 'login', 'signup', 'forgot', 'reset'];

    /**
     * The columns the auth screens are actually served. Anything not listed
     * here stays inside the admin.
     */
    public const FEED_COLUMNS = [
        'id', 'eyebrow', 'title', 'sub',
        'cta', 'href', 'href_new_tab',
        'image', 'image_x', 'image_y', 'image_w', 'image_h',
        'bg_from', 'bg_to',
        'text_color', 'placement'
    ];

    /**
     * Only banners that are switched on and inside their date window.
     */
    public function scopeLive($query)
    {
        $today = now()->toDateString();

        return $query->where('status', 1)
            ->where(function ($q) use ($today) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $today);
            });
    }

    /**
     * A banner set to "auth" shows on every screen; one set to a single screen
     * shows only there. No placement asked for means every banner.
     */
    public function scopeForPlacement($query, $placement)
    {
        if (!$placement) {
            return $query;
        }

        return $query->whereIn('placement', array_unique(['auth', $placement]));
    }
}
