<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\UserAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    /**
     * Get the authenticated user
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getProfile(Request $request)
    {
        $user = Auth::user();

        // Load user preferences and addresses
        $user->load(['preferences', 'addresses']);

        return response()->json([
            'status' => 'success',
            'user' => $user,
        ]);
    }

    /**
     * Update the user's profile
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'phone_number' => 'sometimes|string|max:20',
            'city' => 'sometimes|string|max:100',
            'country' => 'sometimes|string|max:100',
            'address' => 'sometimes|string|max:100',
            'postal_code' => 'sometimes|string|max:100',
            'name' => 'sometimes|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Update user details (only phone_number, city, country)
        $userData = $request->only(['phone_number', 'city', 'country', 'name', 'postal_code', 'address']);
        $user->update($userData);

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Update user profile image
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateProfileImage(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'profile_image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Handle file upload
        if ($request->hasFile('profile_image')) {
            // Delete old image if exists
            if ($user->profile_image && file_exists(storage_path('app/public/' . $user->profile_image))) {
                unlink(storage_path('app/public/' . $user->profile_image));
            }

            // Store the new image
            $path = $request->file('profile_image')->store('profile_images', 'public');
            $user->update(['profile_image' => $path]);

            return response()->json([
                'status' => 'success',
                'message' => 'Profile image updated successfully',
                'profile_image' => asset('storage/' . $path),
                'user' => $user->fresh(),
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'No image file provided',
        ], 400);
    }

    /**
     * Update user preferences
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updatePreferences(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'newsletter_subscription' => 'sometimes|boolean',
            'preferred_categories' => 'sometimes|array',
            'preferred_payment_method' => 'sometimes|string',
            'language' => 'sometimes|string|in:fr,en',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Get user preferences or create if not exists
        $preferences = $user->preferences;
        if (!$preferences) {
            $preferences = new UserPreference(['user_id' => $user->id]);
        }

        // Update preferences
        $preferencesData = $request->only([
            'newsletter_subscription',
            'preferred_categories',
            'preferred_payment_method',
            'language'
        ]);

        $preferences->fill($preferencesData);
        $preferences->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Preferences updated successfully',
            'preferences' => $preferences->fresh(),
        ]);
    }

    /**
     * Get user addresses
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getAddresses(Request $request)
    {
        $user = Auth::user();
        $addresses = $user->addresses;

        return response()->json([
            'status' => 'success',
            'addresses' => $addresses,
        ]);
    }

    /**
     * Add a new address for the user
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function addAddress(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'address_type' => 'required|string|in:shipping,billing',
            'is_default' => 'sometimes|boolean',
            'recipient_name' => 'required|string|max:255',
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:100',
            'state_province' => 'nullable|string|max:100',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|max:100',
            'phone_number' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $addressData = $request->all();
        $addressData['user_id'] = $user->id;

        $address = UserAddress::create($addressData);

        // If this is set as default, remove default flag from other addresses of same type
        if ($request->is_default) {
            UserAddress::where('user_id', $user->id)
                ->where('address_type', $request->address_type)
                ->where('id', '!=', $address->id)
                ->update(['is_default' => false]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Address added successfully',
            'address' => $address,
        ], 201);
    }

    /**
     * Update an existing address
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function updateAddress(Request $request, $id)
    {
        $user = Auth::user();

        $address = UserAddress::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$address) {
            return response()->json([
                'status' => 'error',
                'message' => 'Address not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'address_type' => 'sometimes|string|in:shipping,billing',
            'is_default' => 'sometimes|boolean',
            'recipient_name' => 'sometimes|string|max:255',
            'address_line1' => 'sometimes|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'sometimes|string|max:100',
            'state_province' => 'nullable|string|max:100',
            'postal_code' => 'sometimes|string|max:20',
            'country' => 'sometimes|string|max:100',
            'phone_number' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Update the address
        $address->update($request->all());

        // If this is set as default, remove default flag from other addresses of same type
        if ($request->is_default) {
            UserAddress::where('user_id', $user->id)
                ->where('address_type', $address->address_type)
                ->where('id', '!=', $address->id)
                ->update(['is_default' => false]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Address updated successfully',
            'address' => $address->fresh(),
        ]);
    }

    /**
     * Delete an address
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function deleteAddress(Request $request, $id)
    {
        $user = Auth::user();

        $address = UserAddress::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$address) {
            return response()->json([
                'status' => 'error',
                'message' => 'Address not found',
            ], 404);
        }

        // Check if this is a default address
        $wasDefault = $address->is_default;
        $addressType = $address->address_type;

        // Delete the address
        $address->delete();

        // If deleted address was default, set another address as default
        if ($wasDefault) {
            $newDefault = UserAddress::where('user_id', $user->id)
                ->where('address_type', $addressType)
                ->first();

            if ($newDefault) {
                $newDefault->update(['is_default' => true]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Address deleted successfully',
        ]);
    }

    /**
     * Set an address as default
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function setDefaultAddress(Request $request, $id)
    {
        $user = Auth::user();

        $address = UserAddress::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$address) {
            return response()->json([
                'status' => 'error',
                'message' => 'Address not found',
            ], 404);
        }

        // Remove default flag from other addresses of same type
        UserAddress::where('user_id', $user->id)
            ->where('address_type', $address->address_type)
            ->update(['is_default' => false]);

        // Set this address as default
        $address->update(['is_default' => true]);

        return response()->json([
            'status' => 'success',
            'message' => 'Address set as default successfully',
            'address' => $address->fresh(),
        ]);
    }
}