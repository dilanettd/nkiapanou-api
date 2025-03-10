<?php

namespace App\Http\Controllers;

use App\Models\UserAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class UserAddressController extends Controller
{
    /**
     * Display all addresses for the authenticated user.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $userId = Auth::id();
        $addresses = UserAddress::where('user_id', $userId)->get();

        return response()->json([
            'status' => 'success',
            'data' => $addresses
        ]);
    }

    /**
     * Store a newly created address.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'address_type' => 'required|in:shipping,billing',
            'is_default' => 'required|boolean',
            'recipient_name' => 'required|string|max:255',
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'state_province' => 'nullable|string|max:255',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|max:255',
            'phone_number' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = Auth::id();

        // If this is set as default, update any existing default addresses of the same type
        if ($request->is_default) {
            UserAddress::where('user_id', $userId)
                ->where('address_type', $request->address_type)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        // Create new address
        $address = new UserAddress();
        $address->user_id = $userId;
        $address->address_type = $request->address_type;
        $address->is_default = $request->is_default;
        $address->recipient_name = $request->recipient_name;
        $address->address_line1 = $request->address_line1;
        $address->address_line2 = $request->address_line2;
        $address->city = $request->city;
        $address->state_province = $request->state_province;
        $address->postal_code = $request->postal_code;
        $address->country = $request->country;
        $address->phone_number = $request->phone_number;
        $address->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Address created successfully',
            'data' => $address
        ], 201);
    }

    /**
     * Display the specified address.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $userId = Auth::id();
        $address = UserAddress::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$address) {
            return response()->json([
                'status' => 'error',
                'message' => 'Address not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $address
        ]);
    }

    /**
     * Update the specified address.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'address_type' => 'sometimes|required|in:shipping,billing',
            'is_default' => 'sometimes|required|boolean',
            'recipient_name' => 'sometimes|required|string|max:255',
            'address_line1' => 'sometimes|required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'sometimes|required|string|max:255',
            'state_province' => 'nullable|string|max:255',
            'postal_code' => 'sometimes|required|string|max:20',
            'country' => 'sometimes|required|string|max:255',
            'phone_number' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = Auth::id();
        $address = UserAddress::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$address) {
            return response()->json([
                'status' => 'error',
                'message' => 'Address not found'
            ], 404);
        }

        // If this is being set as default and it's not already default, update other addresses
        if (isset($request->is_default) && $request->is_default && !$address->is_default) {
            UserAddress::where('user_id', $userId)
                ->where('address_type', $request->address_type ?? $address->address_type)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        // Update address fields
        if (isset($request->address_type))
            $address->address_type = $request->address_type;
        if (isset($request->is_default))
            $address->is_default = $request->is_default;
        if (isset($request->recipient_name))
            $address->recipient_name = $request->recipient_name;
        if (isset($request->address_line1))
            $address->address_line1 = $request->address_line1;
        if (isset($request->address_line2))
            $address->address_line2 = $request->address_line2;
        if (isset($request->city))
            $address->city = $request->city;
        if (isset($request->state_province))
            $address->state_province = $request->state_province;
        if (isset($request->postal_code))
            $address->postal_code = $request->postal_code;
        if (isset($request->country))
            $address->country = $request->country;
        if (isset($request->phone_number))
            $address->phone_number = $request->phone_number;

        $address->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Address updated successfully',
            'data' => $address
        ]);
    }

    /**
     * Remove the specified address.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $userId = Auth::id();
        $address = UserAddress::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$address) {
            return response()->json([
                'status' => 'error',
                'message' => 'Address not found'
            ], 404);
        }

        // Check if it's a default address
        if ($address->is_default) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete a default address. Please set another address as default first.'
            ], 400);
        }

        $address->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Address deleted successfully'
        ]);
    }

    /**
     * Set an address as the default for its type.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function setDefault($id)
    {
        $userId = Auth::id();
        $address = UserAddress::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$address) {
            return response()->json([
                'status' => 'error',
                'message' => 'Address not found'
            ], 404);
        }

        // Update any existing default addresses of this type
        UserAddress::where('user_id', $userId)
            ->where('address_type', $address->address_type)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        // Set this address as default
        $address->is_default = true;
        $address->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Address set as default successfully',
            'data' => $address
        ]);
    }

    /**
     * Get all addresses of a specific type for the user.
     *
     * @param  string  $type
     * @return \Illuminate\Http\Response
     */
    public function getByType($type)
    {
        if (!in_array($type, ['shipping', 'billing'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid address type. Must be either "shipping" or "billing".'
            ], 400);
        }

        $userId = Auth::id();
        $addresses = UserAddress::where('user_id', $userId)
            ->where('address_type', $type)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $addresses
        ]);
    }

    /**
     * Get the default address of a specific type for the user.
     *
     * @param  string  $type
     * @return \Illuminate\Http\Response
     */
    public function getDefaultByType($type)
    {
        if (!in_array($type, ['shipping', 'billing'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid address type. Must be either "shipping" or "billing".'
            ], 400);
        }

        $userId = Auth::id();
        $address = UserAddress::where('user_id', $userId)
            ->where('address_type', $type)
            ->where('is_default', true)
            ->first();

        if (!$address) {
            return response()->json([
                'status' => 'error',
                'message' => "No default {$type} address found."
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $address
        ]);
    }
}