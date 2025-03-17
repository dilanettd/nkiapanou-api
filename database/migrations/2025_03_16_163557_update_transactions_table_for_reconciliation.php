<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Ajout du champ currency s'il n'existe pas
            if (!Schema::hasColumn('transactions', 'currency')) {
                $table->string('currency', 3)->default('EUR')->after('amount');
            }

            // Ajout des nouveaux champs pour la réconciliation
            if (!Schema::hasColumn('transactions', 'transaction_type')) {
                $table->string('transaction_type')->nullable()->after('status');
            }

            if (!Schema::hasColumn('transactions', 'reference_number')) {
                $table->string('reference_number')->nullable()->after('transaction_type');
            }

            if (!Schema::hasColumn('transactions', 'fee_amount')) {
                $table->decimal('fee_amount', 10, 2)->nullable()->after('reference_number');
            }

            if (!Schema::hasColumn('transactions', 'billing_email')) {
                $table->string('billing_email')->nullable()->after('fee_amount');
            }

            if (!Schema::hasColumn('transactions', 'billing_name')) {
                $table->string('billing_name')->nullable()->after('billing_email');
            }

            if (!Schema::hasColumn('transactions', 'payment_method_details')) {
                $table->string('payment_method_details')->nullable()->after('billing_name');
            }

            if (!Schema::hasColumn('transactions', 'parent_transaction_id')) {
                $table->unsignedBigInteger('parent_transaction_id')->nullable()->after('payment_method_details');
                $table->foreign('parent_transaction_id')->references('id')->on('transactions')->onDelete('set null');
            }

            if (!Schema::hasColumn('transactions', 'notes')) {
                $table->text('notes')->nullable()->after('parent_transaction_id');
            }

            if (!Schema::hasColumn('transactions', 'payment_response')) {
                $table->json('payment_response')->nullable()->after('notes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Supprimer les colonnes ajoutées
            $table->dropForeign(['parent_transaction_id']);

            $table->dropColumn([
                'currency',
                'transaction_type',
                'reference_number',
                'fee_amount',
                'billing_email',
                'billing_name',
                'payment_method_details',
                'parent_transaction_id',
                'notes',
                'payment_response'
            ]);
        });
    }
};