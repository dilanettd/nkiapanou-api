<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    /**
     * Store a new contact message
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'phone' => 'nullable|string|max:20',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $contact = Contact::create($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Your message has been sent successfully!'
        ]);
    }

    /**
     * Get all contact messages (admin only)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
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
        $isReadFilter = $request->has('is_read') ? $request->query('is_read') : null;

        // Requête de base
        $query = Contact::query();

        // Appliquer les filtres
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        // Filtre sur les messages lus/non lus
        if ($isReadFilter !== null) {
            $query->where('is_read', $isReadFilter);
        }

        // Tri
        $query->orderBy($sortBy, $sortDirection);

        // Pagination
        $contacts = $query->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'status' => 'success',
            'data' => [
                'contacts' => $contacts->items(),
                'current_page' => $contacts->currentPage(),
                'per_page' => $contacts->perPage(),
                'total' => $contacts->total(),
                'last_page' => $contacts->lastPage(),
            ],
        ]);
    }

    /**
     * Mark contact message as read
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function markAsRead(Request $request, $id)
    {
        // Vérifier si l'utilisateur est un admin
        $currentUser = Auth::user();
        if (!$currentUser->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        $contact = Contact::find($id);

        if (!$contact) {
            return response()->json([
                'status' => 'error',
                'message' => 'Contact message not found',
            ], 404);
        }

        $contact->markAsRead();

        return response()->json([
            'status' => 'success',
            'message' => 'Contact message marked as read',
            'contact' => $contact->fresh(),
        ]);
    }

    /**
     * Mark multiple contact messages as read
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function markMultipleAsRead(Request $request)
    {
        // Vérifier si l'utilisateur est un admin
        $currentUser = Auth::user();
        if (!$currentUser->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:contacts,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $ids = $request->input('ids');

        Contact::whereIn('id', $ids)->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => count($ids) . ' contact messages marked as read',
        ]);
    }

    /**
     * Get a specific contact message
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        // Vérifier si l'utilisateur est un admin
        $currentUser = Auth::user();
        if (!$currentUser->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        $contact = Contact::find($id);

        if (!$contact) {
            return response()->json([
                'status' => 'error',
                'message' => 'Contact message not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $contact,
        ]);
    }

    /**
     * Count unread contact messages (admin only)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function countUnread(Request $request)
    {
        // Vérifier si l'utilisateur est un admin
        $currentUser = Auth::user();
        if (!$currentUser->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        $count = Contact::where('is_read', false)->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'unread_count' => $count
            ],
        ]);
    }

    /**
     * Delete a contact message
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        // Vérifier si l'utilisateur est un admin
        $currentUser = Auth::user();
        if (!$currentUser->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        $contact = Contact::find($id);

        if (!$contact) {
            return response()->json([
                'status' => 'error',
                'message' => 'Contact message not found',
            ], 404);
        }

        $contact->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Contact message deleted successfully',
        ]);
    }
}