<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * Display a listing of the products.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = Product::with(['images', 'category']);

        // Apply search filter
        if ($request->has('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                    ->orWhere('description', 'like', "%{$searchTerm}%")
                    ->orWhere('sku', 'like', "%{$searchTerm}%");
            });
        }

        // Apply category filter
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Apply price filters
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

        // Apply status filter
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Apply featured filter
        if ($request->has('featured')) {
            $query->where('featured', $request->featured === 'true' || $request->featured === '1');
        }

        // Apply sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');

        // Validate sort parameters
        if (!in_array($sortBy, ['name', 'price', 'created_at'])) {
            $sortBy = 'created_at';
        }
        if (!in_array($sortDirection, ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }

        // Handle price sorting with discount price
        if ($sortBy === 'price') {
            $query->orderByRaw("COALESCE(discount_price, price) {$sortDirection}");
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }

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
     * Store a newly created product in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0|lt:price',
            'category_id' => 'required|exists:categories,id',
            'status' => 'required|in:active,inactive,out_of_stock',
            'featured' => 'boolean',
            'weight' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|string|max:255',
            'origin_country' => 'nullable|string|max:255',
            'sku' => 'required|string|max:255|unique:products,sku',
            'packaging' => 'nullable|string|max:255',
            'low_stock_threshold' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Create the slug
        $slug = Str::slug($request->name);
        $originalSlug = $slug;
        $count = 1;

        // Ensure slug uniqueness
        while (Product::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $count;
            $count++;
        }

        // Create product
        $product = Product::create(array_merge(
            $request->except('images'),
            ['slug' => $slug]
        ));

        // Load category relation
        $product->load('category');

        return response()->json([
            'status' => 'success',
            'data' => $product
        ], 201);
    }

    /**
     * Display the specified product.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $product = Product::with(['images', 'category'])->find($id);

        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $product
        ]);
    }

    /**
     * Display the specified product by slug.
     *
     * @param  string  $slug
     * @return \Illuminate\Http\Response
     */
    public function showBySlug($slug)
    {
        $product = Product::with(['images', 'category'])->where('slug', $slug)->first();

        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $product
        ]);
    }

    /**
     * Update the specified product in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'price' => 'sometimes|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0|lt:price',
            'quantity' => 'sometimes|integer|min:0',
            'category_id' => 'sometimes|exists:categories,id',
            'status' => 'sometimes|in:active,inactive,out_of_stock',
            'featured' => 'sometimes|boolean',
            'weight' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|string|max:255',
            'origin_country' => 'nullable|string|max:255',
            'sku' => 'sometimes|string|max:255|unique:products,sku,' . $id,
            'packaging' => 'nullable|string|max:255',
            'low_stock_threshold' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Update slug if name changes
        if ($request->has('name') && $request->name !== $product->name) {
            $slug = Str::slug($request->name);
            $originalSlug = $slug;
            $count = 1;

            // Ensure slug uniqueness
            while (Product::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug = $originalSlug . '-' . $count;
                $count++;
            }

            $request->merge(['slug' => $slug]);
        }

        $product->update($request->all());

        // Reload product with relations
        $product = Product::with(['images', 'category'])->find($id);

        return response()->json([
            'status' => 'success',
            'data' => $product
        ]);
    }

    /**
     * Remove the specified product from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $product = Product::with('images')->find($id);

        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        // Delete all associated images from storage
        foreach ($product->images as $image) {
            // Get image path relative to the public directory
            $path = str_replace(url('/'), '', $image->image_path);
            $path = ltrim($path, '/');

            // Delete from public storage
            if (file_exists(public_path($path))) {
                unlink(public_path($path));
            }
        }

        // Delete product (will cascade delete images from database)
        $product->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Product deleted successfully'
        ]);
    }

    /**
     * Update product status.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function updateStatus(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,inactive,out_of_stock',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $product->update([
            'status' => $request->status
        ]);

        // Reload product with relations
        $product = Product::with(['images', 'category'])->find($id);

        return response()->json([
            'status' => 'success',
            'data' => $product
        ]);
    }

    /**
     * Toggle product featured status.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function toggleFeatured($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        $product->update([
            'featured' => !$product->featured
        ]);

        // Reload product with relations
        $product = Product::with(['images', 'category'])->find($id);

        return response()->json([
            'status' => 'success',
            'data' => $product
        ]);
    }

    /**
     * Upload product image to public directory.
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

        // Generate a unique filename
        $fileName = uniqid() . '_' . time() . '.' . $request->file('image')->getClientOriginalExtension();

        // Destination path within public directory
        $destinationPath = 'uploads/products';

        // Create directory if it doesn't exist
        if (!file_exists(public_path($destinationPath))) {
            mkdir(public_path($destinationPath), 0755, true);
        }

        // Move the uploaded file to the public directory
        $request->file('image')->move(public_path($destinationPath), $fileName);

        // Generate the URL for direct access
        $url = url($destinationPath . '/' . $fileName);

        return response()->json([
            'status' => 'success',
            'url' => $url
        ]);
    }

    /**
     * Add image to product.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $productId
     * @return \Illuminate\Http\Response
     */
    public function addProductImage(Request $request, $productId)
    {
        $product = Product::find($productId);

        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'image_path' => 'required|string|url',
            'is_primary' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // If setting as primary, reset all other images
        if ($request->is_primary) {
            ProductImage::where('product_id', $productId)
                ->update(['is_primary' => false]);
        }

        // Create new product image
        $image = ProductImage::create([
            'product_id' => $productId,
            'image_path' => $request->image_path,
            'is_primary' => $request->is_primary ?? false,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $image
        ], 201);
    }

    /**
     * Update product image.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $productId
     * @param  int  $imageId
     * @return \Illuminate\Http\Response
     */
    public function updateProductImage(Request $request, $productId, $imageId)
    {
        $product = Product::find($productId);

        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        $image = ProductImage::where('id', $imageId)
            ->where('product_id', $productId)
            ->first();

        if (!$image) {
            return response()->json([
                'status' => 'error',
                'message' => 'Image not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'is_primary' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // If setting as primary, reset all other images
        if ($request->is_primary) {
            ProductImage::where('product_id', $productId)
                ->where('id', '!=', $imageId)
                ->update(['is_primary' => false]);
        }

        $image->update([
            'is_primary' => $request->is_primary,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Image updated successfully'
        ]);
    }

    /**
     * Set product image as primary.
     *
     * @param  int  $productId
     * @param  int  $imageId
     * @return \Illuminate\Http\Response
     */
    public function setImageAsPrimary($productId, $imageId)
    {
        $product = Product::find($productId);

        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        $image = ProductImage::where('id', $imageId)
            ->where('product_id', $productId)
            ->first();

        if (!$image) {
            return response()->json([
                'status' => 'error',
                'message' => 'Image not found'
            ], 404);
        }

        // Reset all images for this product
        ProductImage::where('product_id', $productId)
            ->update(['is_primary' => false]);

        // Set this image as primary
        $image->update(['is_primary' => true]);

        return response()->json([
            'status' => 'success',
            'message' => 'Image set as primary successfully'
        ]);
    }

    /**
     * Delete product image.
     *
     * @param  int  $productId
     * @param  int  $imageId
     * @return \Illuminate\Http\Response
     */
    public function deleteProductImage($productId, $imageId)
    {
        $product = Product::find($productId);

        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        $image = ProductImage::where('id', $imageId)
            ->where('product_id', $productId)
            ->first();

        if (!$image) {
            return response()->json([
                'status' => 'error',
                'message' => 'Image not found'
            ], 404);
        }

        // Count total images for this product
        $totalImages = ProductImage::where('product_id', $productId)->count();

        // Prevent deleting the only image
        if ($totalImages <= 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete the only image for this product'
            ], 400);
        }

        $isPrimary = $image->is_primary;

        // Get image path relative to the public directory
        $path = str_replace(url('/'), '', $image->image_path);
        $path = ltrim($path, '/');

        // Delete file from public directory
        if (file_exists(public_path($path))) {
            unlink(public_path($path));
        }

        // Delete image from database
        $image->delete();

        // If deleted image was primary, set another one as primary
        if ($isPrimary) {
            $newPrimaryImage = ProductImage::where('product_id', $productId)->first();
            if ($newPrimaryImage) {
                $newPrimaryImage->update(['is_primary' => true]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Image deleted successfully'
        ]);
    }
}