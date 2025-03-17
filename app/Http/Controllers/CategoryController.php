<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Display a listing of the categories.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // Par défaut, n'affiche que les catégories parentes
        $query = Category::query();

        if ($request->has('parent_only') && $request->parent_only === 'true') {
            $query->whereNull('parent_id');
        }

        // Filtrage par statut
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Recherche
        if ($request->has('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                    ->orWhere('description', 'like', "%{$searchTerm}%");
            });
        }

        // Tri
        $sortBy = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');

        if (!in_array($sortBy, ['name', 'created_at'])) {
            $sortBy = 'name';
        }

        if (!in_array($sortDirection, ['asc', 'desc'])) {
            $sortDirection = 'asc';
        }

        $query->orderBy($sortBy, $sortDirection);

        // Pagination
        $limit = $request->get('limit', 10);
        $categories = $query->paginate($limit);

        // Ajouter les sous-catégories pour chaque catégorie
        foreach ($categories as $category) {
            $category->subcategories = Category::where('parent_id', $category->id)
                ->orderBy('name')
                ->get();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'categories' => $categories->items(),
                'current_page' => $categories->currentPage(),
                'per_page' => $categories->perPage(),
                'total' => $categories->total(),
                'last_page' => $categories->lastPage(),
            ]
        ]);
    }

    /**
     * Get all parent categories
     *
     * @return \Illuminate\Http\Response
     */
    public function getParentCategories()
    {
        $categories = Category::whereNull('parent_id')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        // Charger les sous-catégories
        foreach ($categories as $category) {
            $category->subcategories = Category::where('parent_id', $category->id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get();
        }

        return response()->json([
            'status' => 'success',
            'data' => $categories
        ]);
    }

    /**
     * Get all categories (for dropdown lists)
     *
     * @return \Illuminate\Http\Response
     */
    public function getAllCategories()
    {
        // Récupérer toutes les catégories parentes
        $parentCategories = Category::whereNull('parent_id')
            ->orderBy('name')
            ->get();

        // Récupérer toutes les sous-catégories
        foreach ($parentCategories as $parent) {
            $parent->subcategories = Category::where('parent_id', $parent->id)
                ->orderBy('name')
                ->get();
        }

        return response()->json([
            'status' => 'success',
            'data' => $parentCategories
        ]);
    }

    /**
     * Store a newly created category in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'status' => 'required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Créer la catégorie (le slug sera généré automatiquement grâce au mutator dans le modèle)
        $category = Category::create($request->all());

        // Si c'est une sous-catégorie, récupérer la catégorie parente
        if ($category->parent_id) {
            $category->parent = Category::find($category->parent_id);
        } else {
            // Si c'est une catégorie parente, initialiser un tableau vide pour les sous-catégories
            $category->subcategories = [];
        }

        return response()->json([
            'status' => 'success',
            'data' => $category
        ], 201);
    }

    /**
     * Display the specified category.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Category not found'
            ], 404);
        }

        // Charger la relation parent si elle existe
        if ($category->parent_id) {
            $category->load('parent');
        }

        // Charger les sous-catégories
        $category->subcategories = Category::where('parent_id', $category->id)
            ->orderBy('name')
            ->get();

        // Compter les produits
        $category->products_count = Product::where('category_id', $category->id)->count();

        return response()->json([
            'status' => 'success',
            'data' => $category
        ]);
    }

    /**
     * Display the specified category by slug.
     *
     * @param  string  $slug
     * @return \Illuminate\Http\Response
     */
    public function showBySlug($slug)
    {
        $category = Category::where('slug', $slug)->first();

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Category not found'
            ], 404);
        }

        // Charger la relation parent si elle existe
        if ($category->parent_id) {
            $category->load('parent');
        }

        // Charger les sous-catégories
        $category->subcategories = Category::where('parent_id', $category->id)
            ->orderBy('name')
            ->get();

        // Compter les produits
        $category->products_count = Product::where('category_id', $category->id)->count();

        return response()->json([
            'status' => 'success',
            'data' => $category
        ]);
    }

    /**
     * Update the specified category in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Category not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'status' => 'sometimes|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Vérifier qu'une catégorie ne puisse pas être sa propre parente
        if ($request->has('parent_id') && $request->parent_id == $id) {
            return response()->json([
                'status' => 'error',
                'message' => 'A category cannot be its own parent'
            ], 422);
        }

        // Mettre à jour la catégorie
        $category->update($request->all());

        // Recharger la catégorie avec ses relations
        if ($category->parent_id) {
            $category->load('parent');
        }

        // Charger les sous-catégories
        $category->subcategories = Category::where('parent_id', $category->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $category
        ]);
    }

    /**
     * Remove the specified category from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Category not found'
            ], 404);
        }

        // Vérifier si la catégorie a des sous-catégories
        $hasSubcategories = Category::where('parent_id', $id)->exists();
        if ($hasSubcategories) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete a category that has subcategories'
            ], 400);
        }

        // Vérifier si la catégorie a des produits
        $hasProducts = Product::where('category_id', $id)->exists();
        if ($hasProducts) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete a category that has products'
            ], 400);
        }

        // Supprimer l'image si elle existe
        if ($category->image) {
            $path = str_replace(url('/'), '', $category->image);
            $path = ltrim($path, '/');

            if (file_exists(public_path($path))) {
                unlink(public_path($path));
            }
        }

        $category->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Category deleted successfully'
        ]);
    }

    /**
     * Update the status of a category.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function updateStatus(Request $request, $id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Category not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $category->update([
            'status' => $request->status
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $category
        ]);
    }

    /**
     * Upload category image.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function uploadImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Générer un nom de fichier unique
        $fileName = uniqid() . '_' . time() . '.' . $request->file('image')->getClientOriginalExtension();

        // Chemin de destination dans le répertoire public
        $destinationPath = 'uploads/categories';

        // Créer le répertoire s'il n'existe pas
        if (!file_exists(public_path($destinationPath))) {
            mkdir(public_path($destinationPath), 0755, true);
        }

        // Déplacer le fichier téléchargé vers le répertoire public
        $request->file('image')->move(public_path($destinationPath), $fileName);

        // Générer l'URL pour un accès direct
        $url = url($destinationPath . '/' . $fileName);

        return response()->json([
            'status' => 'success',
            'url' => $url
        ]);
    }

    /**
     * Get products from a category.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function getCategoryProducts(Request $request, $id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Category not found'
            ], 404);
        }

        $query = Product::where('category_id', $id);

        // Appliquer les filtres
        if ($request->has('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                    ->orWhere('description', 'like', "%{$searchTerm}%");
            });
        }

        if ($request->has('min_price')) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('discount_price')
                    ->where('price', '>=', $request->min_price)
                    ->orWhere('discount_price', '>=', $request->min_price);
            });
        }

        if ($request->has('max_price')) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('discount_price')
                    ->where('price', '<=', $request->max_price)
                    ->orWhere('discount_price', '<=', $request->max_price);
            });
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Tri
        $sortBy = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');

        if ($sortBy === 'price') {
            $query->orderByRaw("COALESCE(discount_price, price) {$sortDirection}");
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }

        // Charger les relations
        $query->with(['images', 'category']);

        // Pagination
        $limit = $request->get('limit', 8);
        $products = $query->paginate($limit);

        return response()->json([
            'status' => 'success',
            'data' => [
                'products' => $products->items(),
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
            ]
        ]);
    }

    /**
     * Get all products from a category including subcategories.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function getAllCategoryProducts(Request $request, $id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Category not found'
            ], 404);
        }

        // Obtenir les IDs de cette catégorie et de toutes ses sous-catégories
        $categoryIds = [$id];
        $subcategories = Category::where('parent_id', $id)->get();
        foreach ($subcategories as $subcategory) {
            $categoryIds[] = $subcategory->id;
        }

        $query = Product::whereIn('category_id', $categoryIds);

        // Appliquer les filtres
        if ($request->has('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                    ->orWhere('description', 'like', "%{$searchTerm}%");
            });
        }

        if ($request->has('min_price')) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('discount_price')
                    ->where('price', '>=', $request->min_price)
                    ->orWhere('discount_price', '>=', $request->min_price);
            });
        }

        if ($request->has('max_price')) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('discount_price')
                    ->where('price', '<=', $request->max_price)
                    ->orWhere('discount_price', '<=', $request->max_price);
            });
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Tri
        $sortBy = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');

        if ($sortBy === 'price') {
            $query->orderByRaw("COALESCE(discount_price, price) {$sortDirection}");
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }

        // Charger les relations
        $query->with(['images', 'category']);

        // Pagination
        $limit = $request->get('limit', 8);
        $products = $query->paginate($limit);

        return response()->json([
            'status' => 'success',
            'data' => [
                'products' => $products->items(),
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
            ]
        ]);
    }

    /**
     * Toggle the status of a category between active and inactive.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function toggleStatus($id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Category not found'
            ], 404);
        }

        // Basculer entre active et inactive
        $newStatus = $category->status === 'active' ? 'inactive' : 'active';

        $category->update([
            'status' => $newStatus
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $category
        ]);
    }

}