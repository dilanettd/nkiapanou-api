<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\ProductReview;
use App\Models\Category;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Récupère toutes les données pour le tableau de bord
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Vérifier si l'utilisateur est un admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access'
            ], 403);
        }

        // Récupérer toutes les données pour le tableau de bord
        $summary = $this->getDashboardSummary();
        $salesByDay = $this->getSalesByDay();
        $salesByMonth = $this->getSalesByMonth();
        $ordersByDay = $this->getOrdersByDay();
        $ordersByMonth = $this->getOrdersByMonth();
        $usersByDay = $this->getUsersByDay();
        $usersByMonth = $this->getUsersByMonth();
        $topProducts = $this->getTopProducts();
        $topCategories = $this->getTopCategories();

        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => $summary,
                'salesByDay' => $salesByDay,
                'salesByMonth' => $salesByMonth,
                'ordersByDay' => $ordersByDay,
                'ordersByMonth' => $ordersByMonth,
                'usersByDay' => $usersByDay,
                'usersByMonth' => $usersByMonth,
                'topProducts' => $topProducts,
                'topCategories' => $topCategories
            ]
        ]);
    }

    /**
     * Récupère le résumé des statistiques du tableau de bord
     *
     * @return \Illuminate\Http\Response
     */
    public function getSummary()
    {
        // Vérifier si l'utilisateur est un admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access'
            ], 403);
        }

        $summary = $this->getDashboardSummary();

        return response()->json([
            'status' => 'success',
            'data' => $summary
        ]);
    }

    /**
     * Récupère les données de ventes par jour
     *
     * @return \Illuminate\Http\Response
     */
    public function getDailySales()
    {
        // Vérifier si l'utilisateur est un admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access'
            ], 403);
        }

        $salesByDay = $this->getSalesByDay();

        return response()->json([
            'status' => 'success',
            'data' => $salesByDay
        ]);
    }

    /**
     * Récupère les données de ventes par mois
     *
     * @return \Illuminate\Http\Response
     */
    public function getMonthlySales()
    {
        // Vérifier si l'utilisateur est un admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access'
            ], 403);
        }

        $salesByMonth = $this->getSalesByMonth();

        return response()->json([
            'status' => 'success',
            'data' => $salesByMonth
        ]);
    }

    /**
     * Récupère les produits les plus vendus
     *
     * @return \Illuminate\Http\Response
     */
    public function getTopProducts()
    {
        // Vérifier si l'utilisateur est un admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access'
            ], 403);
        }

        $topProducts = $this->getTopProductsData();

        return response()->json([
            'status' => 'success',
            'data' => $topProducts
        ]);
    }

    /**
     * Récupère les catégories les plus performantes
     *
     * @return \Illuminate\Http\Response
     */
    public function getTopCategories()
    {
        // Vérifier si l'utilisateur est un admin
        if (!Auth::user()->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access'
            ], 403);
        }

        $topCategories = $this->getTopCategoriesData();

        return response()->json([
            'status' => 'success',
            'data' => $topCategories
        ]);
    }

    /**
     * Calcule le résumé des statistiques du tableau de bord
     *
     * @return array
     */
    private function getDashboardSummary()
    {
        // Calculer les totaux
        $totalSales = Order::where('payment_status', 'paid')
            ->sum('total_amount');

        $totalOrders = Order::count();

        $totalCustomers = User::doesntHave('admin')->count();

        $totalProducts = Product::count();

        $averageOrderValue = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

        $pendingOrders = Order::where('status', 'pending')->count();

        $lowStockProducts = Product::whereColumn('quantity', '<=', 'low_stock_threshold')
            ->where('quantity', '>', 0)
            ->count();

        $recentReviews = ProductReview::where('created_at', '>=', Carbon::now()->subDays(7))
            ->count();

        return [
            'totalSales' => round($totalSales, 2),
            'totalOrders' => $totalOrders,
            'totalCustomers' => $totalCustomers,
            'totalProducts' => $totalProducts,
            'averageOrderValue' => round($averageOrderValue, 2),
            'pendingOrders' => $pendingOrders,
            'lowStockProducts' => $lowStockProducts,
            'recentReviews' => $recentReviews
        ];
    }

    /**
     * Calcule les ventes par jour sur les 7 derniers jours
     *
     * @return array
     */
    private function getSalesByDay()
    {
        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $salesByDay = Order::where('payment_status', 'paid')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(total_amount) as amount')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $result = [];
        $currentDate = $startDate->copy();

        // Remplir le tableau avec des données pour chaque jour
        while ($currentDate <= $endDate) {
            $formattedDate = $currentDate->format('Y-m-d');
            $dayName = $currentDate->locale('fr')->isoFormat('ddd');
            $day = $currentDate->format('d');
            $month = $currentDate->format('m');
            $period = "{$dayName} {$day}/{$month}";

            // Chercher les ventes pour cette date
            $daySales = $salesByDay->firstWhere('date', $formattedDate);
            $amount = $daySales ? $daySales->amount : 0;

            $result[] = [
                'period' => $period,
                'amount' => round($amount, 2)
            ];

            $currentDate->addDay();
        }

        return $result;
    }

    /**
     * Calcule les ventes par mois sur les 6 derniers mois
     *
     * @return array
     */
    private function getSalesByMonth()
    {
        $startDate = Carbon::now()->subMonths(5)->startOfMonth();
        $endDate = Carbon::now()->endOfMonth();

        $salesByMonth = Order::where('payment_status', 'paid')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('SUM(total_amount) as amount')
            )
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        $result = [];
        $currentDate = $startDate->copy();

        // Remplir le tableau avec des données pour chaque mois
        while ($currentDate <= $endDate) {
            $year = $currentDate->format('Y');
            $month = $currentDate->format('n');
            $monthName = $currentDate->locale('fr')->isoFormat('MMM');
            $period = "{$monthName} {$year}";

            // Chercher les ventes pour ce mois
            $monthSales = $salesByMonth->first(function ($item) use ($year, $month) {
                return $item->year == $year && $item->month == $month;
            });

            $amount = $monthSales ? $monthSales->amount : 0;

            $result[] = [
                'period' => $period,
                'amount' => round($amount, 2)
            ];

            $currentDate->addMonth();
        }

        return $result;
    }

    /**
     * Calcule le nombre de commandes par jour sur les 7 derniers jours
     *
     * @return array
     */
    private function getOrdersByDay()
    {
        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $ordersByDay = Order::whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as value')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $result = [];
        $currentDate = $startDate->copy();

        // Remplir le tableau avec des données pour chaque jour
        while ($currentDate <= $endDate) {
            $formattedDate = $currentDate->format('Y-m-d');
            $dayName = $currentDate->locale('fr')->isoFormat('ddd');
            $day = $currentDate->format('d');
            $month = $currentDate->format('m');
            $period = "{$dayName} {$day}/{$month}";

            // Chercher les commandes pour cette date
            $dayOrders = $ordersByDay->firstWhere('date', $formattedDate);
            $value = $dayOrders ? $dayOrders->value : 0;

            $result[] = [
                'period' => $period,
                'value' => $value
            ];

            $currentDate->addDay();
        }

        return $result;
    }

    /**
     * Calcule le nombre de commandes par mois sur les 6 derniers mois
     *
     * @return array
     */
    private function getOrdersByMonth()
    {
        $startDate = Carbon::now()->subMonths(5)->startOfMonth();
        $endDate = Carbon::now()->endOfMonth();

        $ordersByMonth = Order::whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('COUNT(*) as value')
            )
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        $result = [];
        $currentDate = $startDate->copy();

        // Remplir le tableau avec des données pour chaque mois
        while ($currentDate <= $endDate) {
            $year = $currentDate->format('Y');
            $month = $currentDate->format('n');
            $monthName = $currentDate->locale('fr')->isoFormat('MMM');
            $period = "{$monthName} {$year}";

            // Chercher les commandes pour ce mois
            $monthOrders = $ordersByMonth->first(function ($item) use ($year, $month) {
                return $item->year == $year && $item->month == $month;
            });

            $value = $monthOrders ? $monthOrders->value : 0;

            $result[] = [
                'period' => $period,
                'value' => $value
            ];

            $currentDate->addMonth();
        }

        return $result;
    }

    /**
     * Calcule le nombre de nouveaux utilisateurs par jour sur les 7 derniers jours
     *
     * @return array
     */
    private function getUsersByDay()
    {
        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $usersByDay = User::whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as value')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $result = [];
        $currentDate = $startDate->copy();

        // Remplir le tableau avec des données pour chaque jour
        while ($currentDate <= $endDate) {
            $formattedDate = $currentDate->format('Y-m-d');
            $dayName = $currentDate->locale('fr')->isoFormat('ddd');
            $day = $currentDate->format('d');
            $month = $currentDate->format('m');
            $period = "{$dayName} {$day}/{$month}";

            // Chercher les utilisateurs pour cette date
            $dayUsers = $usersByDay->firstWhere('date', $formattedDate);
            $value = $dayUsers ? $dayUsers->value : 0;

            $result[] = [
                'period' => $period,
                'value' => $value
            ];

            $currentDate->addDay();
        }

        return $result;
    }

    /**
     * Calcule le nombre de nouveaux utilisateurs par mois sur les 6 derniers mois
     *
     * @return array
     */
    private function getUsersByMonth()
    {
        $startDate = Carbon::now()->subMonths(5)->startOfMonth();
        $endDate = Carbon::now()->endOfMonth();

        $usersByMonth = User::whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('COUNT(*) as value')
            )
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        $result = [];
        $currentDate = $startDate->copy();

        // Remplir le tableau avec des données pour chaque mois
        while ($currentDate <= $endDate) {
            $year = $currentDate->format('Y');
            $month = $currentDate->format('n');
            $monthName = $currentDate->locale('fr')->isoFormat('MMM');
            $period = "{$monthName} {$year}";

            // Chercher les utilisateurs pour ce mois
            $monthUsers = $usersByMonth->first(function ($item) use ($year, $month) {
                return $item->year == $year && $item->month == $month;
            });

            $value = $monthUsers ? $monthUsers->value : 0;

            $result[] = [
                'period' => $period,
                'value' => $value
            ];

            $currentDate->addMonth();
        }

        return $result;
    }

    /**
     * Récupère les produits les plus vendus
     *
     * @return array
     */
    private function getTopProductsData()
    {
        // Calculer les produits les plus vendus (quantité et revenu)
        $topProducts = OrderItem::select(
            'product_id',
            DB::raw('SUM(quantity) as sold'),
            DB::raw('SUM(total) as revenue')
        )
            ->with([
                'product' => function ($query) {
                    $query->select('id', 'name', 'sku', 'quantity');
                }
            ])
            ->groupBy('product_id')
            ->orderBy('sold', 'desc')
            ->limit(5)
            ->get();

        $result = [];

        foreach ($topProducts as $item) {
            if ($item->product) {
                $result[] = [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'sku' => $item->product->sku,
                    'sold' => $item->sold,
                    'revenue' => round($item->revenue, 2),
                    'stock' => $item->product->quantity
                ];
            }
        }

        return $result;
    }

    /**
     * Récupère les catégories les plus performantes
     *
     * @return array
     */
    private function getTopCategoriesData()
    {
        // Joindre les items de commande avec les produits pour obtenir les catégories
        $topCategories = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->select(
                'categories.id',
                'categories.name',
                DB::raw('SUM(order_items.quantity) as sold'),
                DB::raw('SUM(order_items.total) as revenue')
            )
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('sold', 'desc')
            ->limit(4)
            ->get();

        $result = [];

        foreach ($topCategories as $category) {
            $result[] = [
                'id' => $category->id,
                'name' => $category->name,
                'sold' => $category->sold,
                'revenue' => round($category->revenue, 2)
            ];
        }

        return $result;
    }
}