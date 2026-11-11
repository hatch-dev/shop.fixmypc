<?php

namespace App\Http\Controllers;

use App\Models\Helper\AdminNotifications;
use App\Models\Helper\ControllerHelper;
use App\Models\Helper\Response;
use App\Models\Helper\Validation;
use Illuminate\Http\Request;

/**
 * The admin header's notification bell (App\Models\Helper\AdminNotifications).
 *
 * No permission of its own: every source inside is filtered by the permission
 * its own screen needs, so an admin with none of them simply gets an empty bell.
 */
class AdminNotificationsController extends ControllerHelper
{
    public function index(Request $request)
    {
        try {
            if (is_null($this->user)) {
                return response()->json(Validation::unauthorized());
            }

            $data = AdminNotifications::feed($this->user, $this->isSuperAdmin, $this->isVendor, $request->limit ?: 20);

            return response()->json(new Response($request->token, $data));
        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }

    /** Body: {"ids": ["order:85", ...]} or {"all": true}. */
    public function read(Request $request)
    {
        try {
            if (is_null($this->user)) {
                return response()->json(Validation::unauthorized());
            }

            if ($request->boolean('all')) {
                AdminNotifications::markAllRead($this->user);
            } else {
                AdminNotifications::markRead($this->user, (array)$request->input('ids', []));
            }

            return response()->json(new Response($request->token,
                AdminNotifications::feed($this->user, $this->isSuperAdmin, $this->isVendor, $request->limit ?: 20)
            ));
        } catch (\Exception $ex) {
            return response()->json(Validation::error($request->token, $ex->getMessage()));
        }
    }
}
