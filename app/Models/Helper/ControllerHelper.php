<?php

namespace App\Models\Helper;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class ControllerHelper extends Controller
{
    protected $user;
    protected $isVendor = false;
    protected $isSuperAdmin = false;
    /**
     * The staff member behind this request's bearer token, or null.
     *
     * The admin guard resolves any Passport token to the admin with the same
     * numeric id, so a customer's token ("user" scope) for customer 101
     * came back as admin 101. Admin routes also run scope:admin, but the
     * storefront routes in these controllers do not, so a customer whose id
     * matched a staff id acted with that staff member's permissions. Only a
     * token issued at admin sign-in (scope "admin") counts.
     */
    public static function staffFromToken()
    {
        $admin = Auth::guard('admin')->user();

        return ($admin && $admin->tokenCan('admin')) ? $admin : null;
    }

    public function __construct()
    {
        $this->middleware(function ($request, $next){
            $this->user = self::staffFromToken();
            if(!is_null($this->user)){
                foreach ($this->user->roles->pluck('name') as $i){
                    if($i == 'vendor') {
                        $this->isVendor = true;
                        break;
                    } else if($i == 'superadmin') {
                        $this->isSuperAdmin = true;
                        break;
                    }
                }
            }

            return $next($request);
        });
    }
}
