<?php

namespace App\Http\Middleware;

use App\Models\Helper\Response;
use App\Models\Helper\Validation;
use Carbon\Carbon;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Config;
use Laravel\Passport\Passport;

class CheckForAllScopes
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  mixed  ...$scopes
     * @return \Illuminate\Http\Response
     *
     * @throws \Illuminate\Auth\AuthenticationException|\Laravel\Passport\Exceptions\MissingScopeException
     */
    public function handle($request, $next, ...$scopes)
    {
        if (! $request->user() || ! $request->user()->token()) {
            throw new AuthenticationException();
        }

        foreach ($scopes as $scope) {

            /*if($scope == 'admin'){
                $method = $request->route()->methods[0];
                if($method == 'POST') {
                    return response()->json(new Response($request->token, ['form' => ['Disabled for the demo']], 201, null, 201));
                }else if($method == 'DELETE'){
                    return response()->json(Validation::error($request->token,
                        'Deleting is disable for the demo'));
                }
            }*/


            if ($request->user()->tokenCan($scope)) {

                $now = Carbon::now();
                $diff = Carbon::parse($request->user()->token()->expires_at)->diffInMinutes($now);

                if($diff < 1){
                    $token = $request->user()->token();

                    /*
                     * A customer token is replaced with one of the same
                     * lifetime it was issued with. It always used to get 30
                     * days, which would turn a one-day sign-in ("Keep me
                     * signed in" left unticked) into a month for anyone who
                     * happened to be active in its last minute.
                     */
                    $expires = $scope === 'user' && $token->created_at && $token->expires_at
                        ? $now->copy()->addSeconds(max(60, Carbon::parse($token->created_at)->diffInSeconds(Carbon::parse($token->expires_at))))
                        : $now->copy()->addDays(Config::get('constants.auth.EXPIRATION_IN_DAYS'));

                    $token->delete();
                    Passport::personalAccessTokensExpireIn($expires);
                    // See UsersController::issueUserSession: the lifetime is
                    // read when the token server is built, so rebuild it.
                    app()->forgetInstance(\League\OAuth2\Server\AuthorizationServer::class);
                    $request['token'] = $request->user()->createToken($scope, [$scope])->accessToken;
                }

                return $next($request);
            }
        }

        return response()->json(new Response(null, null, 204,'Not Authorized.'), 201);
    }
}
