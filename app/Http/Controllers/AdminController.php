<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    /**
     * Display a listing of the admins.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $admins = Admin::with('user')->paginate(15);

        return response()->json([
            'data' => $admins->items(),
            'current_page' => $admins->currentPage(),
            'last_page' => $admins->lastPage(),
            'per_page' => $admins->perPage(),
            'total' => $admins->total()
        ]);
    }

    /**
     * Store a newly created admin in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => [
                'required',
                'exists:users,id',
                'unique:admins,user_id'
            ],
            'role' => ['required', Rule::in(['admin', 'superadmin', 'editor'])],
            'status' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $admin = Admin::create([
            'user_id' => $request->user_id,
            'role' => $request->role,
            'status' => $request->has('status') ? $request->status : true,
        ]);

        $admin->load('user');

        return response()->json([
            'message' => 'Admin created successfully',
            'data' => $admin
        ], 201);
    }

    /**
     * Display the specified admin.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $admin = Admin::with('user')->findOrFail($id);

        return response()->json([
            'data' => $admin
        ]);
    }

    /**
     * Admin login with email and password
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function loginAdmin(Request $request)
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

        // Check if user is an admin
        $admin = Admin::where('user_id', $user->id)->where('status', true)->first();

        if (!$admin) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have admin privileges',
            ], 403);
        }

        // Attempt to log the user in
        if (!Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials',
            ], 401);
        }

        // Create token with admin scope
        $token = $user->createToken('admin_token', ['admin'])->accessToken;

        // Add admin information to user object
        $user->admin = true;
        $user->admin_role = $admin->role;

        return response()->json([
            'status' => 'success',
            'message' => 'Admin login successful',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Update the specified admin in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $admin = Admin::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'role' => ['sometimes', 'required', Rule::in(['admin', 'superadmin', 'editor'])],
            'status' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->has('role')) {
            $admin->role = $request->role;
        }

        if ($request->has('status')) {
            $admin->status = $request->status;
        }

        $admin->save();
        $admin->load('user');

        return response()->json([
            'message' => 'Admin updated successfully',
            'data' => $admin
        ]);
    }

    /**
     * Remove the specified admin from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $admin = Admin::findOrFail($id);
        $admin->delete();

        return response()->json([
            'message' => 'Admin record deleted successfully'
        ]);
    }

    /**
     * Toggle the active status of an admin.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function toggleStatus($id)
    {
        $admin = Admin::findOrFail($id);
        $admin->status = !$admin->status;
        $admin->save();

        $admin->load('user');

        return response()->json([
            'message' => 'Admin status toggled successfully',
            'data' => $admin
        ]);
    }

    /**
     * Create a new user and admin record simultaneously.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function createWithUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in(['admin', 'superadmin', 'editor'])],
            'status' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Créer un nouvel utilisateur
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Créer l'enregistrement d'administrateur associé
        $admin = Admin::create([
            'user_id' => $user->id,
            'role' => $request->role,
            'status' => $request->has('status') ? $request->status : true,
        ]);

        $admin->load('user');

        return response()->json([
            'message' => 'User and admin created successfully',
            'data' => $admin
        ], 201);
    }
}