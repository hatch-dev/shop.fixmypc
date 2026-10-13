<?php

namespace App\Http\Controllers;

use App\Models\AuthBanner;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\FileHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Utils;
use App\Models\Helper\Validation;
use Illuminate\Http\Request;

/**
 * Sign-in banners: the artwork + copy that fills the left side of the
 * shop sign-in, register and forgot-password screens.
 */
class AuthBannersController extends ControllerHelper
{
    /**
     * Sign-in banners are site-wide, so a vendor has no business editing them
     * even when their role carries the banner permission.
     */
    private function blockVendor()
    {
        return $this->isVendor
            ? response()->json(Validation::unauthorized())
            : false;
    }

    public function all(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'banner.view')) {
                return $can;
            }

            $data = AuthBanner::orderBy('sort', 'ASC')
                ->orderBy('id', 'ASC')
                ->get();

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function find(Request $request, $id)
    {
        try {
            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'banner.view')) {
                return $can;
            }

            $banner = AuthBanner::find($id);

            if (is_null($banner)) {
                return response()->json(Validation::noDataLang($lang));
            }

            return response()->json(new Response($request->token, $banner));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function action(Request $request, $id = null)
    {
        try {
            if ($blocked = $this->blockVendor()) {
                return $blocked;
            }

            $lang = $request->header('language');

            if ($error = $this->validateBanner($request)) {
                return response()->json($error);
            }

            $payload = $this->payload($request);

            if ($id) {
                if ($can = Utils::userCan($this->user, 'banner.edit')) {
                    return $can;
                }

                $banner = AuthBanner::find($id);

                if (is_null($banner)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                $banner->update($payload);

            } else {
                if ($can = Utils::userCan($this->user, 'banner.create')) {
                    return $can;
                }

                if (!isset($payload['sort'])) {
                    $payload['sort'] = (int)AuthBanner::max('sort') + 1;
                }

                $banner = AuthBanner::create($payload);
            }

            return response()->json(new Response($request->token, AuthBanner::find($banner->id)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Image upload. Kept separate so the artwork can be replaced without
     * re-submitting the whole form.
     */
    public function upload(Request $request, $id = null)
    {
        try {
            if ($blocked = $this->blockVendor()) {
                return $blocked;
            }

            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, $id ? 'banner.edit' : 'banner.create')) {
                return $can;
            }

            if (!$request->hasFile('photo')) {
                return response()->json(Validation::error($request->token, 'No image was uploaded'));
            }

            $image_info = FileHelper::uploadImage($request['photo'], 'auth-banner');

            $banner = $id ? AuthBanner::find($id) : null;

            if (is_null($banner)) {
                $banner = AuthBanner::create([
                    'image' => $image_info['name'],
                    'sort' => (int)AuthBanner::max('sort') + 1
                ]);

            } else {
                $previous = $banner->image;

                if ($banner->update(['image' => $image_info['name']]) && $previous) {
                    FileHelper::deleteFile($previous);
                }
            }

            return response()->json(new Response($request->token, AuthBanner::find($banner->id)));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    public function delete(Request $request, $id)
    {
        try {
            if ($blocked = $this->blockVendor()) {
                return $blocked;
            }

            $lang = $request->header('language');

            if ($can = Utils::userCan($this->user, 'banner.delete')) {
                return $can;
            }

            foreach (explode(',', $id) as $i) {

                $banner = AuthBanner::find($i);

                if (is_null($banner)) {
                    return response()->json(Validation::noDataLang($lang));
                }

                $image = $banner->image;

                if ($banner->delete() && $image) {
                    FileHelper::deleteFile($image);
                }
            }

            return response()->json(new Response($request->token, null, 200, ''));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Persist the rotation order after a move up / move down.
     * Expects `ids` as an array of banner ids in their new order.
     */
    public function reorder(Request $request)
    {
        try {
            if ($blocked = $this->blockVendor()) {
                return $blocked;
            }

            if ($can = Utils::userCan($this->user, 'banner.edit')) {
                return $can;
            }

            foreach (array_values($request->input('ids', [])) as $position => $bannerId) {
                AuthBanner::where('id', $bannerId)->update(['sort' => $position + 1]);
            }

            $data = AuthBanner::orderBy('sort', 'ASC')
                ->orderBy('id', 'ASC')
                ->get();

            return response()->json(new Response($request->token, $data));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /**
     * Every place a banner button can point at: the shop's fixed screens plus
     * the categories, brands and CMS pages that exist right now. Grouped so
     * the picker in the admin can show headings.
     *
     * The dynamic paths mirror frontend-panel: /all/{slug} for a category,
     * /{slug}/brand?brand={id} for a brand (see mixin/util.js brandLink) and
     * /page/{slug} for a CMS page.
     */
    public function links(Request $request)
    {
        try {
            if ($can = Utils::userCan($this->user, 'banner.view')) {
                return $can;
            }

            $groups = [
                ['group' => 'Shop', 'items' => [
                    ['label' => 'Home', 'url' => '/'],
                    ['label' => 'All categories', 'url' => '/categories'],
                    ['label' => 'All brands', 'url' => '/brands'],
                    ['label' => 'Clearance', 'url' => '/clearance'],
                    ['label' => 'Flash sale', 'url' => '/flash-sale'],
                    ['label' => 'Search', 'url' => '/search'],
                ]],
                ['group' => 'Checkout', 'items' => [
                    ['label' => 'Basket', 'url' => '/cart'],
                    ['label' => 'Checkout', 'url' => '/checkout'],
                    ['label' => 'Track an order', 'url' => '/track-order'],
                    ['label' => 'Shipping information', 'url' => '/shipping'],
                ]],
                ['group' => 'Account', 'items' => [
                    ['label' => 'Sign in', 'url' => '/login'],
                    ['label' => 'Create an account', 'url' => '/register'],
                    ['label' => 'Forgot password', 'url' => '/forgot-password'],
                    ['label' => 'Account dashboard', 'url' => '/user/dashboard'],
                    ['label' => 'My orders', 'url' => '/user/orders'],
                    ['label' => 'Wishlist', 'url' => '/user/wishlists'],
                    ['label' => 'Vouchers', 'url' => '/user/vouchers'],
                    ['label' => 'Savers club', 'url' => '/user/savers-club'],
                    ['label' => 'Become a seller', 'url' => '/seller/register'],
                ]],
            ];

            $categories = \App\Models\Category::where('status', 1)
                ->whereNotNull('slug')
                ->orderBy('slug')
                ->get(['id', 'slug']);

            if ($categories->count()) {
                $groups[] = [
                    'group' => 'Categories',
                    'items' => $categories->map(function ($i) {
                        return ['label' => $i->slug, 'url' => '/all/' . $i->slug];
                    })->values()
                ];
            }

            $brands = \App\Models\Brand::where('status', 1)
                ->whereNotNull('slug')
                ->orderBy('slug')
                ->get(['id', 'slug']);

            if ($brands->count()) {
                $groups[] = [
                    'group' => 'Brands',
                    'items' => $brands->map(function ($i) {
                        return ['label' => $i->slug, 'url' => '/' . $i->slug . '/brand?brand=' . $i->id];
                    })->values()
                ];
            }

            $pages = \App\Models\Page::whereNotNull('slug')
                ->orderBy('slug')
                ->get(['id', 'slug']);

            if ($pages->count()) {
                $groups[] = [
                    'group' => 'Pages',
                    'items' => $pages->map(function ($i) {
                        return ['label' => $i->slug, 'url' => '/page/' . $i->slug];
                    })->values()
                ];
            }

            return response()->json(new Response($request->token, $groups));

        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    private function validateBanner(Request $request)
    {
        if (!trim((string)$request->input('title'))) {
            return Validation::error($request->token, 'Headline is required');
        }

        $options = [
            'placement' => [AuthBanner::PLACEMENTS, 'placement']
        ];

        foreach ($options as $key => $rule) {
            if ($request->filled($key) && !in_array($request->input($key), $rule[0], true)) {
                return Validation::error($request->token, 'Invalid ' . $rule[1]);
            }
        }

        foreach (['bg_from', 'bg_to', 'text_color'] as $key) {
            if ($request->filled($key) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $request->input($key))) {
                return Validation::error($request->token, 'Colours must be a 6-digit hex value');
            }
        }

        // Focal point is a percentage across the frame; size is a percentage
        // of it, allowed past 100 so artwork can be stretched to fill.
        $ranges = [
            'image_x' => [0, 100, 'focal point'],
            'image_y' => [0, 100, 'focal point'],
            'image_w' => [10, 400, 'image width'],
            'image_h' => [10, 400, 'image height']
        ];

        foreach ($ranges as $key => $rule) {

            if (!$request->filled($key)) {
                continue;
            }

            $value = $request->input($key);

            if (!is_numeric($value) || $value < $rule[0] || $value > $rule[1]) {
                return Validation::error(
                    $request->token,
                    "The {$rule[2]} must be between {$rule[0]} and {$rule[1]}"
                );
            }
        }


        $starts = $request->input('starts_at');
        $ends = $request->input('ends_at');

        if ($starts && $ends && $starts > $ends) {
            return Validation::error($request->token, 'The end date must be on or after the start date');
        }

        return null;
    }

    /**
     * Empty strings arrive from the form as "", which should clear the column
     * rather than store a blank -- dates in particular must be null.
     */
    private function payload(Request $request)
    {
        $payload = [];

        $keys = [
            'name', 'eyebrow', 'title', 'sub',
            'cta', 'href',
            'image', 'bg_from', 'bg_to',
            'text_color',
            'placement', 'starts_at', 'ends_at'
        ];

        foreach ($keys as $key) {
            if (!$request->has($key)) {
                continue;
            }

            $value = $request->input($key);
            $value = is_string($value) ? trim($value) : $value;

            $payload[$key] = ($value === '' || $value === null) ? null : $value;
        }

        foreach (['image_x', 'image_y', 'image_w', 'image_h'] as $key) {
            if ($request->filled($key)) {
                $payload[$key] = (int)$request->input($key);
            }
        }

        foreach (['status', 'href_new_tab'] as $flag) {
            if ($request->has($flag)) {
                $payload[$flag] = (int)$request->input($flag) ? 1 : 0;
            }
        }

        if ($request->has('sort')) {
            $payload['sort'] = (int)$request->input('sort');
        }

        // These always need a value, even when the form sends them empty.
        $defaults = [
            'text_color' => '#FFFFFF',
            'image_x' => 50,
            'image_y' => 50,
            'image_w' => 100,
            'image_h' => 100,
            'placement' => 'auth'
        ];

        foreach ($defaults as $key => $value) {
            $payload[$key] = $payload[$key] ?? $value;
        }

        return $payload;
    }
}
