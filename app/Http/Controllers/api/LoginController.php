<?php

namespace App\Http\Controllers\api;

use App\Enums\Roles;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class LoginController extends Controller
{

    use ApiResponse;

    public function login(Request $request)
    {
        // Validate input fields
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        // Check if the user has a provider (social login) record
        $user = User::where('email', $credentials['email'])->first();

        if ($user) {
            // Check if the user has logged in using a social provider
            if (sizeof($user->provider)) {
                return $this->generateResponse((object)[
                    'message' => 'This account is associated with a social login. Please use the social login method.',
                    'status' => false,
                    'code' => 401
                ]);  // Forbidden status for incorrect login method
            }
        }

        // Attempt login with provided credentials
        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')]
            ]);
        }

        // Fetch the logged-in user
        $loggedInUser = $request->user();

        // Check if the user's account is suspended
        if ($loggedInUser->status == 0) {
            // Log out the user and invalidate the session
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Return error message for suspended account
            throw ValidationException::withMessages([
                'email' => 'Your account is suspended, please contact SpotSeeker.lk.'
            ]);
        }

        // Assign role and generate an authentication token
        $loggedInUser->role = $loggedInUser->getRoleNames()[0] ?? 'user'; // Default to 'user' if no role
        $loggedInUser->token = $loggedInUser->createToken($loggedInUser->email)->plainTextToken;
        $loggedInUser->verified = $user->hasVerifiedMobile();

        // Return success response with user data
        return $this->successResponse("Login successful", $loggedInUser);
    }

    public function register(Request $request)
    {
        // Step 1: Validate input data
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone_no' => 'required|numeric|digits:10|unique:users,phone_no',
            'verification_method' => 'required|string'
        ]);

        if ($validator->fails()) {
            return $this->generateResponse((object)['message' => 'validation failed', 'errors' => $validator->errors(), "status" => false, "code" => 422]);
        }

        $user = $this->registerUser($request->name, $request->email, $request->password, $request->phone_no, $request->verification_method, "", true);

        return $this->generateResponse((object)[
            'message' => 'Registration successful! A verification code has been sent to your mobile number.',
            'data' => $user,
            "status" => true,
        ]);
    }

    public function logout(Request $request)
    {
        if (Auth::user()) {
            Auth::user()->tokens()->delete();

            $authObj = (object)[
                "message" => 'logged out successfully',
                "status" => true
            ];

            return $this->generateResponse($authObj);
        }

        $authFailObj = (object)[
            "message" => 'user not found',
            "status" => false,
            "code" => 500
        ];

        return $this->generateResponse($authFailObj);
    }

    protected function socialLogin(Request $request)
    {
        DB::beginTransaction();
        try {
            // Validate input
            $request->validate([
                'token' => ['required'],
                'provider' => ['required']
            ]);

            // Authenticate the user using Socialite
            $user = null;
            if ($request->provider == 'google') {
                $user = Socialite::driver('google')->stateless()->userFromToken($request->token);
            } else if ($request->provider == 'apple') {
                $user = Socialite::driver("apple")->stateless()->userFromToken($request->token);
            } else if ($request->provider == 'facebook') {
                $user = Socialite::driver("facebook")->stateless()->userFromToken($request->token);
            } else {
                throw new Exception("Unsupported provider");
            }

            // Check if the user exists in the database
            $loggedInUser = User::where('email', $user->email)->first();

            if (!$loggedInUser) {
                // Register new user if not exists
                $loggedInUser = $this->registerUser($user->name, $user->email, "", null, null, $user->avatar, false, $request->provider, $user);
            } else {

                $parts = explode(' ', $user->name);
                $first_name = $parts[0];
                $last_name = isset($parts[1]) ? $parts[1] : '';

                $loggedInUser->name = $user->name;
                $loggedInUser->first_name = $first_name;
                $loggedInUser->last_name = $last_name;
                $loggedInUser->profile_photo_path = $user->avatar;

                // Save the user
                $loggedInUser->save();
            }

            $accessToken = $loggedInUser->createToken($loggedInUser->email)->plainTextToken;


            // Prepare authentication data
            $authDataObj = [
                'first_name' => $loggedInUser->first_name,
                'last_name' => $loggedInUser->last_name,
                'email' => $loggedInUser->email,
                'phone_no' => $loggedInUser->phone_no,
                'nic' => $loggedInUser->nic,
                'role' => $loggedInUser->getRoleNames()->first() ?? 'user',
                'token' => $accessToken,
                'profile_photo_url' => $loggedInUser->profile_photo_path,
                'verified' => $loggedInUser->hasVerifiedMobile()
            ];

            // Success response
            $authObj = (object)[
                "message" => 'Authenticated successfully',
                "status" => true,
                "data" => $authDataObj
            ];

            DB::commit();

            return $this->generateResponse($authObj);
        } catch (Exception $e) {
            Log::error($e);
            DB::rollBack();
            // Error response
            $authObj = (object)[
                "message" => $e->getMessage(),
                "status" => false,
                "code" => 401
            ];

            return $this->generateResponse($authObj);
        }
    }

    public function forgotPassword(Request $request)
    {
        // Validate the incoming email request
        $validated = $request->validate([
            'email' => 'required|email'
        ]);

        // Attempt to send the password reset link
        $status = Password::sendResetLink($validated);

        // Log the status of the reset link
        Log::info('Password reset status: ' . $status);

        // Return a response based on the status
        if ($status === Password::RESET_LINK_SENT) {
            return $this->successResponse(
                'Reset password email sent successfully.',
                $status
            );
        }

        return $this->errorResponse(
            'Failed to send reset password email.',
            $status,
            400
        );
    }


    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? $this->successResponse("password reset successful", $status)
            : $this->errorResponse("password reset failed", $status, 400);
    }

    protected function managerSocialLogin(Request $request)
    {
        try {
            // Validate input
            $request->validate([
                'token' => ['required'],
                'provider' => ['required']
            ]);

            // Authenticate the user using Socialite
            $user = null;

            if ($request->provider == 'google') {
                $user = Socialite::driver('google')->stateless()->userFromToken($request->token);
            } else if ($request->provider == 'apple') {
                $user = Socialite::driver("apple")->stateless()->userFromToken($request->token);
            } else {
                throw new Exception("Unsupported provider");
            }

            // Check if the user exists in the database
            $loggedInUser = User::where('email', $user->email)->first();

            if (!$loggedInUser) {
                throw new Exception("User not found");
            } else if ($loggedInUser->status == 0) {

                $authFailObj = (object)[
                    "message" => 'Your Account is suspended, please contact SpotSeeker.lk.',
                    "status" => false,
                    "code" => 401
                ];

                return $this->generateResponse($authFailObj);
            } else if (!$loggedInUser->hasRole([Roles::ADMIN->value, Roles::MANAGER->value, Roles::COORDINATOR->value])) {
                $authFailObj = (object)[
                    "message" => 'Email & Password does not match with our record.',
                    "status" => false,
                    "code" => 403
                ];

                return $this->generateResponse($authFailObj);
            }

            // Prepare authentication data
            $authDataObj = [
                'username' => $loggedInUser->name,
                'email' => $loggedInUser->email,
                'role' => $loggedInUser->getRoleNames()->first() ?? Roles::COORDINATOR->value, // Assuming 'user' as a default role
                'token' => $loggedInUser->createToken($loggedInUser->email)->plainTextToken
            ];

            // Success response
            $authObj = (object)[
                "message" => 'Authenticated successfully',
                "status" => true,
                "data" => $authDataObj
            ];
        } catch (Exception $e) {
            // Error response
            $authObj = (object)[
                "message" => $e->getMessage(),
                "status" => false,
                "code" => 400
            ];
        }

        return $this->generateResponse($authObj);
    }

    public function managerLogin(Request $request)
    {
        try {
            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            if (!Auth::attempt($credentials)) {

                $authFailObj = (object)[
                    "message" => 'Email & Password does not match with our record.',
                    "status" => false,
                    "code" => 401
                ];

                return $this->generateResponse($authFailObj);
            }

            if (Auth::user()->status == 0) {

                $authFailObj = (object)[
                    "message" => 'Your Account is suspended, please contact SpotSeeker.lk.',
                    "status" => false,
                    "code" => 401
                ];

                return $this->generateResponse($authFailObj);
            } else if (!Auth::user()->hasRole([Roles::ADMIN->value, Roles::MANAGER->value, Roles::COORDINATOR->value])) {
                $authFailObj = (object)[
                    "message" => 'Email & Password does not match with our record.',
                    "status" => false,
                    "code" => 403
                ];

                return $this->generateResponse($authFailObj);
            }

            $authDataObj = [
                'username' => Auth::user()->name,
                'email' => Auth::user()->email,
                'role' => Auth::user()->getRoleNames()->first() ?? 'user', // Assuming 'user' as a default role
                'token' => Auth::user()->createToken(Auth::user()->email)->plainTextToken
            ];

            $authObj = (object)[
                "message" => 'authenticated successfully',
                "status" => true,
                "data" => $authDataObj
            ];

            return $this->generateResponse($authObj);
        } catch (Exception $e) {
            $authObj = (object)[
                "message" => $e->getMessage(),
                "status" => false,
                "code" => 401
            ];
        }
        return $this->generateResponse($authObj);
    }

    public function managerLogOut(Request $request)
    {
        if (Auth::user()) {
            Auth::user()->tokens()->delete();

            $authObj = (object)[
                "message" => 'logged out successfully',
                "status" => true
            ];

            return $this->generateResponse($authObj);
        }

        $authFailObj = (object)[
            "message" => 'user not found',
            "status" => false,
            "code" => 500
        ];

        return $this->generateResponse($authFailObj);
    }

    private function registerUser($name, $email, $password, $phone_no, $verification_method = null, $profile_photo_path = null, $verify = false, $provider = 'credentials', $socialUser = null)
    {
        $parts = explode(' ', $name);
        $first_name = $parts[0];
        $last_name = isset($parts[1]) ? $parts[1] : '';

        $user = User::create([
            'name' => $name,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'password' => Hash::make($password),
            'phone_no' => $phone_no,
            'verification_method' => $verification_method,
            'profile_photo_path' => $profile_photo_path
        ]);

        $role = Role::where(['name' => 'user'])->first();

        $user->syncRoles([$role->id]);

        if ($verify) {
            event(new Registered($user));
        }

        $user->token = $user->createToken($user->email)->plainTextToken;

        if ($provider != 'credentials') {
            $user->provider()->updateOrCreate(
                ['provider' => $provider],
                [
                    'provider_id' => $socialUser->id,
                    'avatar' => $socialUser->avatar
                ]
            );
        }

        return $user;
    }
}
