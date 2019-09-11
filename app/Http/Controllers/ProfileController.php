<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Auth;
use Cache;
use App\Follower;
use App\FollowRequest;
use App\Profile;
use App\User;
use App\UserFilter;
use League\Fractal;
use App\Util\Lexer\Nickname;
use App\Util\Webfinger\Webfinger;
use App\Transformer\ActivityPub\ProfileOutbox;
use App\Transformer\ActivityPub\ProfileTransformer;

class ProfileController extends Controller
{
    public function show(Request $request, $username)
    {
        $user = Profile::whereUsername($username)->firstOrFail();
        if($user->domain) {
            return redirect($user->remote_url);
        }
        if($user->status != null) {
            return $this->accountCheck($user);
        } else {
            return $this->buildProfile($request, $user);
        }
    }

    // TODO: refactor this mess
    protected function buildProfile(Request $request, $user)
    {
        $username = $user->username;
        $loggedIn = Auth::check();
        $isPrivate = false;
        $isBlocked = false;

        if($user->status != null) {
            return ProfileController::accountCheck($user);
        }

        if ($user->remote_url) {
            $settings = new \StdClass;
            $settings->crawlable = false;
            $settings->show_profile_follower_count = true;
            $settings->show_profile_following_count = true;
        } else {
            $settings = $user->user->settings;
        }

        if ($request->wantsJson() && config('federation.activitypub.enabled')) {
            return $this->showActivityPub($request, $user);
        }

        if ($user->is_private == true) {
            $isPrivate = $this->privateProfileCheck($user, $loggedIn);
        }

        if ($loggedIn == true) {
            $isBlocked = $this->blockedProfileCheck($user);
        }

        $owner = $loggedIn && Auth::id() === $user->user_id;
        $is_following = ($owner == false && Auth::check()) ? $user->followedBy(Auth::user()->profile) : false;

        if ($isPrivate == true || $isBlocked == true) {
            $requested = Auth::check() ? FollowRequest::whereFollowerId(Auth::user()->profile_id)
                ->whereFollowingId($user->id)
                ->exists() : false;
            return view('profile.private', compact('user', 'is_following', 'requested'));
        } 

        $is_admin = is_null($user->domain) ? $user->user->is_admin : false;
        $profile = $user;
        $settings = [
            'crawlable' => $settings->crawlable,
            'following' => [
                'count' => $settings->show_profile_following_count,
                'list' => $settings->show_profile_following
            ], 
            'followers' => [
                'count' => $settings->show_profile_follower_count,
                'list' => $settings->show_profile_followers
            ]
        ];
        return view('profile.show', compact('user', 'profile', 'settings', 'owner', 'is_following', 'is_admin'));
    }

    public function permalinkRedirect(Request $request, $username)
    {
        $user = Profile::whereUsername($username)->firstOrFail();
        $settings = User::whereUsername($username)->firstOrFail()->settings;

        if ($request->wantsJson() && config('federation.activitypub.enabled')) {
            return $this->showActivityPub($request, $user);
        }

        return redirect($user->url());
    }

    protected function privateProfileCheck(Profile $profile, $loggedIn)
    {
        if (!Auth::check()) {
            return true;
        }

        $user = Auth::user()->profile;
        if($user->id == $profile->id || !$profile->is_private) {
            return false;
        }

        $follows = Follower::whereProfileId($user->id)->whereFollowingId($profile->id)->exists();
        if ($follows == false) {
            return true;
        }
        
        return false;
    }

    protected function blockedProfileCheck(Profile $profile)
    {
        $pid = Auth::user()->profile->id;
        $blocks = UserFilter::whereUserId($profile->id)
                ->whereFilterType('block')
                ->whereFilterableType('App\Profile')
                ->pluck('filterable_id')
                ->toArray();
        if (in_array($pid, $blocks)) {
            return true;
        }

        return false;
    }

    public static function accountCheck(Profile $profile)
    {
        switch ($profile->status) {
            case 'disabled':
            case 'suspended':
            case 'delete':
                return view('profile.disabled');
                break;
            
            default:
                # code...
                break;
        }

        return abort(404);
    }

    public function showActivityPub(Request $request, $user)
    {
        abort_if(!config('federation.activitypub.enabled'), 404);
        
        if($user->status != null) {
            return ProfileController::accountCheck($user);
        }
        $fractal = new Fractal\Manager();
        $resource = new Fractal\Resource\Item($user, new ProfileTransformer);
        $res = $fractal->createData($resource)->toArray();
        return response(json_encode($res['data']))->header('Content-Type', 'application/activity+json');
    }

    public function showAtomFeed(Request $request, $user)
    {
        abort_if(!config('federation.atom.enabled'), 404);

        $profile = $user = Profile::whereNull('status')->whereNull('domain')->whereUsername($user)->whereIsPrivate(false)->firstOrFail();
        if($profile->status != null) {
            return $this->accountCheck($profile);
        }
        if($profile->is_private || Auth::check()) {
            $blocked = $this->blockedProfileCheck($profile);
            $check = $this->privateProfileCheck($profile, null);
            if($check || $blocked) {
                return redirect($profile->url());
            }
        }
        $items = $profile->statuses()->whereHas('media')->whereIn('visibility',['public', 'unlisted'])->orderBy('created_at', 'desc')->take(10)->get();
        return response()->view('atom.user', compact('profile', 'items'))
        ->header('Content-Type', 'application/atom+xml');
    }

    public function meRedirect()
    {
        abort_if(!Auth::check(), 404);
        return redirect(Auth::user()->url());
    }
}
