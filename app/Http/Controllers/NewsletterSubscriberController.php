<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NewsletterSubscriberController extends Controller
{
    /**
     * Display a listing of newsletter subscribers.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // Vérifier les permissions d'administrateur
        $this->checkAdminAccess();

        $query = NewsletterSubscriber::query();

        // Filtrer par recherche (email ou nom)
        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('email', 'like', "%{$searchTerm}%")
                    ->orWhere('name', 'like', "%{$searchTerm}%");
            });
        }

        // Filtrer par statut
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Pagination
        $perPage = $request->get('per_page', 20);
        $subscribers = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => [
                'subscribers' => $subscribers->items(),
                'pagination' => [
                    'current_page' => $subscribers->currentPage(),
                    'per_page' => $subscribers->perPage(),
                    'total' => $subscribers->total(),
                    'last_page' => $subscribers->lastPage(),
                ]
            ]
        ]);
    }

    /**
     * Store a newly created newsletter subscriber.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $this->checkAdminAccess();

        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:newsletter_subscribers,email',
            'name' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,unsubscribed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $subscriber = NewsletterSubscriber::create([
            'email' => $request->email,
            'name' => $request->name,
            'status' => $request->status ?? 'active',
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $subscriber
        ], 201);
    }

    /**
     * Display the specified subscriber.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $this->checkAdminAccess();

        $subscriber = NewsletterSubscriber::findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $subscriber
        ]);
    }

    /**
     * Update the specified subscriber.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $this->checkAdminAccess();

        $subscriber = NewsletterSubscriber::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'email' => 'sometimes|required|email|unique:newsletter_subscribers,email,' . $id,
            'name' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,unsubscribed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $subscriber->update($request->only(['email', 'name', 'status']));

        return response()->json([
            'status' => 'success',
            'data' => $subscriber
        ]);
    }

    /**
     * Remove the specified subscriber.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $this->checkAdminAccess();

        $subscriber = NewsletterSubscriber::findOrFail($id);
        $subscriber->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Subscriber deleted successfully'
        ]);
    }

    /**
     * Export selected subscribers to CSV.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportSelected(Request $request)
    {
        $this->checkAdminAccess();

        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'exists:newsletter_subscribers,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $subscribers = NewsletterSubscriber::whereIn('id', $request->ids)->get();

        // Vérifier si nous devons exporter uniquement les emails actifs
        if ($request->has('active_only') && $request->active_only) {
            $subscribers = $subscribers->where('status', 'active');
        }

        return $this->generateCsvResponse($subscribers, $request->format ?? 'full');
    }

    /**
     * Export all subscribers to CSV.
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportAll(Request $request)
    {
        $this->checkAdminAccess();

        $query = NewsletterSubscriber::query();

        // Filtrer par statut si spécifié
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        } else if ($request->has('active_only') && $request->active_only) {
            // Par défaut, n'exporter que les utilisateurs actifs si demandé
            $query->where('status', 'active');
        }

        $subscribers = $query->get();

        return $this->generateCsvResponse($subscribers, $request->format ?? 'full');
    }

    /**
     * Public endpoint to subscribe to newsletter.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function subscribe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Vérifier si l'email existe déjà
        $existing = NewsletterSubscriber::where('email', $request->email)->first();

        if ($existing) {
            // Si l'abonné existe mais est désabonné, le réactiver
            if ($existing->status === 'unsubscribed') {
                $existing->update(['status' => 'active']);
                return response()->json([
                    'status' => 'success',
                    'message' => 'You have been successfully re-subscribed to our newsletter.'
                ]);
            }

            // Sinon, informer que l'email est déjà abonné
            return response()->json([
                'status' => 'info',
                'message' => 'This email is already subscribed to our newsletter.'
            ]);
        }

        // Créer un nouvel abonné
        NewsletterSubscriber::create([
            'email' => $request->email,
            'name' => $request->name,
            'status' => 'active',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'You have been successfully subscribed to our newsletter.'
        ]);
    }

    /**
     * Public endpoint to unsubscribe from newsletter.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function unsubscribe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $subscriber = NewsletterSubscriber::where('email', $request->email)->first();

        if (!$subscriber) {
            return response()->json([
                'status' => 'error',
                'message' => 'This email is not subscribed to our newsletter.'
            ], 404);
        }

        $subscriber->update(['status' => 'unsubscribed']);

        return response()->json([
            'status' => 'success',
            'message' => 'You have been successfully unsubscribed from our newsletter.'
        ]);
    }

    /**
     * Generate CSV response from subscribers collection.
     *
     * @param \Illuminate\Database\Eloquent\Collection $subscribers
     * @param string $format Format of the CSV (full, emails_only)
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    private function generateCsvResponse($subscribers, $format = 'full')
    {
        $filename = 'newsletter-subscribers-' . date('Y-m-d');

        // Si format est emails_only, ajuster le nom du fichier
        if ($format === 'emails_only') {
            $filename = 'newsletter-emails-' . date('Y-m-d');
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0'
        ];

        $callback = function () use ($subscribers, $format) {
            $file = fopen('php://output', 'w');

            // Add CSV headers based on format
            if ($format === 'emails_only') {
                fputcsv($file, ['Email']);
            } else {
                fputcsv($file, ['ID', 'Email', 'Name', 'Status', 'Date d\'inscription']);
            }

            // Add data rows
            foreach ($subscribers as $subscriber) {
                if ($format === 'emails_only') {
                    fputcsv($file, [$subscriber->email]);
                } else {
                    fputcsv($file, [
                        $subscriber->id,
                        $subscriber->email,
                        $subscriber->name,
                        $subscriber->status,
                        $subscriber->created_at->format('Y-m-d H:i:s')
                    ]);
                }
            }

            fclose($file);
        };

        return new StreamedResponse($callback, 200, $headers);
    }

    /**
     * Méthode utilitaire pour vérifier les permissions d'administrateur.
     * 
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    private function checkAdminAccess()
    {
        $user = Auth::user();
        if (!$user || !$user->isAdmin()) {
            abort(403, 'You do not have permission to access this resource');
        }
    }
}