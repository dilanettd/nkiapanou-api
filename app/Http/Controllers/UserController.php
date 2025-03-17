<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\UserAddress;
use App\Models\Admin;
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
            if ($user->profile_image && file_exists(public_path(parse_url($user->profile_image, PHP_URL_PATH)))) {
                unlink(public_path(parse_url($user->profile_image, PHP_URL_PATH)));
            }

            // Generate a unique filename
            $fileName = uniqid() . '_' . time() . '.' . $request->file('profile_image')->getClientOriginalExtension();

            // Destination path within public directory
            $destinationPath = 'uploads/profiles';

            // Create directory if it doesn't exist
            if (!file_exists(public_path($destinationPath))) {
                mkdir(public_path($destinationPath), 0755, true);
            }

            // Move the uploaded file to the public directory
            $request->file('profile_image')->move(public_path($destinationPath), $fileName);

            // Generate the URL for direct access
            $url = url($destinationPath . '/' . $fileName);

            // Update user with direct URL
            $user->update(['profile_image' => $url]);

            return response()->json([
                'status' => 'success',
                'message' => 'Profile image updated successfully',
                'profile_image' => $url,
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

    /**
     * Get all users (admin only)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getUsers(Request $request)
    {
        // Vérifier si l'utilisateur est un admin
        $currentUser = Auth::user();
        if (!$currentUser->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        // Paramètres de pagination et filtrage
        $page = $request->query('page', 1);
        $limit = $request->query('limit', 10);
        $search = $request->query('search');
        $sortBy = $request->query('sort_by', 'created_at');
        $sortDirection = $request->query('sort_direction', 'desc');
        $adminFilter = $request->has('admin') ? $request->query('admin') : null;
        $isSocialFilter = $request->has('is_social') ? $request->query('is_social') : null;

        // Requête de base
        $query = User::query();

        // Appliquer les filtres
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        // Filtre sur les admin
        if ($adminFilter !== null) {
            if ($adminFilter) {
                $query->whereHas('admin');
            } else {
                $query->doesntHave('admin');
            }
        }

        // Filtre sur les comptes sociaux
        if ($isSocialFilter !== null) {
            $query->where('is_social', $isSocialFilter);
        }

        // Tri
        $query->orderBy($sortBy, $sortDirection);

        // Pagination
        $users = $query->paginate($limit, ['*'], 'page', $page);

        // Charger le rôle d'admin si présent
        foreach ($users as $user) {
            if ($user->admin) {
                $user->admin_role = $user->admin->role;
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'users' => $users->items(),
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    /**
     * Get a specific user by ID (admin only)
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function getUserById(Request $request, $id)
    {
        // Vérifier si l'utilisateur est un admin
        $currentUser = Auth::user();
        if (!$currentUser->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        $user = User::with(['preferences', 'addresses'])->find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        // Ajouter des informations sur le rôle d'admin
        if ($user->admin) {
            $user->admin_role = $user->admin->role;
        }

        return response()->json([
            'status' => 'success',
            'data' => $user,
        ]);
    }

    /**
     * Delete a user (admin only)
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function deleteUser(Request $request, $id)
    {
        // Vérifier si l'utilisateur est un admin
        $currentUser = Auth::user();
        if (!$currentUser->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        // Empêcher la suppression d'un superadmin par un admin normal
        if ($user->isSuperAdmin() && !$currentUser->isSuperAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to delete a super admin',
            ], 403);
        }

        // Supprimer l'image de profil
        if ($user->profile_image && file_exists(storage_path('app/public/' . $user->profile_image))) {
            unlink(storage_path('app/public/' . $user->profile_image));
        }

        // Supprimer l'utilisateur
        $user->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Convert a social account to a regular account
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function convertSocialAccount(Request $request, $id)
    {
        // Vérifier si l'utilisateur est un admin
        $currentUser = Auth::user();
        if (!$currentUser->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        if (!$user->is_social) {
            return response()->json([
                'status' => 'error',
                'message' => 'This account is not a social account',
            ], 400);
        }

        // Mettre à jour pour convertir en compte normal
        $user->update([
            'is_social' => false,
            'social_id' => null,
            'social_type' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Social account converted successfully',
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Toggle admin status for a user
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function toggleAdminStatus(Request $request, $id)
    {
        // Vérifier si l'utilisateur est un superadmin
        $currentUser = Auth::user();
        if (!$currentUser->isSuperAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only super admins can change admin status',
            ], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $makeAdmin = $request->input('admin', false);

        if ($makeAdmin) {
            // Si l'utilisateur n'est pas déjà admin, créer un enregistrement admin
            if (!$user->isAdmin()) {
                Admin::create([
                    'user_id' => $user->id,
                    'role' => 'manager', // Rôle par défaut pour les nouveaux admins
                    'status' => true,    // Actif par défaut
                ]);
            }
        } else {
            // Si l'utilisateur est admin, supprimer l'enregistrement admin
            if ($user->isAdmin()) {
                $user->admin()->delete();
            }
        }

        $user = $user->fresh();
        if ($user->admin) {
            $user->admin_role = $user->admin->role;
        }

        return response()->json([
            'status' => 'success',
            'message' => $makeAdmin ? 'User promoted to admin' : 'Admin privileges removed',
            'data' => $user,
        ]);
    }

    /**
     * Change user's password
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function changePassword(Request $request)
    {
        $user = Auth::user();

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

        // Vérifier que le mot de passe actuel est correct
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Current password is incorrect',
            ], 400);
        }

        // Mettre à jour le mot de passe
        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Password changed successfully',
        ]);
    }
}