<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserPreference;
use App\Events\Registered;
use App\Events\PasswordResetRequested;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /**
     * Register a new user with email and password
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'language' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'is_social' => false,
        ]);

        $verificationToken = Str::random(64);
        $user->email_verification_token = Hash::make($verificationToken);
        $user->email_verification_token_expires_at = Carbon::now()->addMinutes(120);
        $user->save();

        // Create default user preferences
        UserPreference::create([
            'user_id' => $user->id,
            'newsletter_subscription' => true,
            'language' => $request->language ?? 'fr',
        ]);

        // Send verification email using custom event
        event(new Registered($user));

        return response()->json([
            'status' => 'success',
            'message' => 'User registered successfully. Please verify your email.',
            'user' => $user,
        ], 201);
    }

    /**
     * Login user with email and password
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if user exists
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials',
            ], 401);
        }

        // Check if user is using social authentication
        if ($user->is_social) {
            return response()->json([
                'status' => 'error',
                'message' => 'This account uses social login. Please sign in with Facebook or Google.',
            ], 401);
        }

        // Attempt to log the user in
        if (!Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials',
            ], 401);
        }

        // Check if email is verified
        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email not verified. Please check your email for verification link.',
                'email_verified' => false,
                'user' => $user
            ], 403);
        }

        // Create token
        $token = $user->createToken('auth_token')->accessToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Login or register with social account
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function socialLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'social_id' => 'required|string',
            'social_type' => 'required|string|in:facebook,google',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if user exists by social_id
        $user = User::where('social_id', $request->social_id)
            ->where('social_type', $request->social_type)
            ->first();

        // If user doesn't exist with social_id, check by email
        if (!$user) {
            $user = User::where('email', $request->email)->first();

            // If user exists but with different authentication method
            if ($user && !$user->is_social) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This email is already registered with email/password. Please login with your password.',
                ], 409);
            }

            // Create new user if not found
            if (!$user) {
                $user = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'social_id' => $request->social_id,
                    'social_type' => $request->social_type,
                    'is_social' => true,
                    'email_verified_at' => now(), // Social logins are pre-verified
                ]);

                // Create default user preferences
                UserPreference::create([
                    'user_id' => $user->id,
                    'newsletter_subscription' => true,
                    'language' => $request->language ?? 'fr',
                ]);
            }
            // Update user if found by email but not by social_id
            else {
                $user->update([
                    'social_id' => $request->social_id,
                    'social_type' => $request->social_type,
                    'is_social' => true,
                    'email_verified_at' => now(),
                ]);
            }
        }

        // Create token
        $token = $user->createToken('auth_token')->accessToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Social login successful',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Logout user (revoke token)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function logout(Request $request)
    {
        $request->user()->tokens->each(function ($token) {
            $token->revoke();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Email verification
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function verify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email_verification_token_expires_at', '>', Carbon::now())
            ->whereNotNull('email_verification_token')
            ->first();

        if (!$user || !Hash::check($request->token, $user->email_verification_token)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired token.'
            ], 400);
        }

        $user->email_verified_at = now();
        $user->email_verification_token = null;
        $user->email_verification_token_expires_at = null;
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Email verified successfully.'
        ], 200);
    }

    /**
     * Resend verification email
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function resendVerification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Email already verified',
            ]);
        }

        // Générer un nouveau token et utiliser l'événement personnalisé au lieu de sendEmailVerificationNotification
        $verificationToken = Str::random(64);
        $user->email_verification_token = Hash::make($verificationToken);
        $user->email_verification_token_expires_at = Carbon::now()->addMinutes(120);
        $user->save();

        event(new Registered($user));

        return response()->json([
            'status' => 'success',
            'message' => 'Verification link sent',
        ]);
    }

    /**
     * Send password reset link
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if user exists
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        // Check if user is using social authentication
        if ($user && $user->is_social) {
            return response()->json([
                'status' => 'error',
                'message' => 'This account uses social login. You cannot reset its password.',
            ], 401);
        }

        // Generate token for password reset
        $token = Str::random(60);

        // Save token in the password_reset_tokens table
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($token),
                'created_at' => now()
            ]
        );

        // Trigger password reset event
        event(new PasswordResetRequested($user, $token));

        return response()->json([
            'status' => 'success',
            'message' => 'Reset password email sent',
        ]);
    }

    /**
     * Reset password
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if user exists
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        // Check if user is using social authentication
        if ($user && $user->is_social) {
            return response()->json([
                'status' => 'error',
                'message' => 'This account uses social login. You cannot reset its password.',
            ], 401);
        }

        // Get the password reset record
        $passwordReset = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$passwordReset) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired reset token',
            ], 400);
        }

        // Check if token is valid
        if (!Hash::check($request->token, $passwordReset->token)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid reset token',
            ], 400);
        }

        // Check if token is expired (typically 60 minutes)
        $tokenCreatedAt = Carbon::parse($passwordReset->created_at);
        if (Carbon::now()->diffInMinutes($tokenCreatedAt) > 60) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reset token has expired',
            ], 400);
        }

        // Update the user's password
        $user->password = Hash::make($request->password);
        $user->setRememberToken(Str::random(60));
        $user->save();

        // Delete the password reset record
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Password has been reset successfully',
        ]);
    }

    /**
     * Change user password by providing the current password
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();

        // Check if user is using social authentication
        if ($user->is_social) {
            return response()->json([
                'status' => 'error',
                'message' => 'This account uses social login. You cannot change its password.',
            ], 401);
        }

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Current password is incorrect',
            ], 401);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Password has been changed successfully',
        ]);
    }


}