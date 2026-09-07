<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('client_name');
            $table->string('consultant_name')->nullable();
            $table->string('process')->nullable();
            $table->decimal('basic_amount', 12, 2)->nullable();
            $table->decimal('gst_amount', 12, 2)->nullable();
            $table->decimal('cgst', 12, 2)->nullable();
            $table->decimal('sgst', 12, 2)->nullable();
            $table->decimal('igst', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->nullable();
            $table->string('invoice_number')->unique();
            $table->date('invoice_date')->nullable();
            $table->text('client_address')->nullable();
            $table->string('state')->nullable();
            $table->string('contact_no')->nullable();
            $table->string('email_address')->nullable();
            $table->string('branch_code')->nullable();
            $table->string('payment_mode')->nullable();
            $table->string('refund_invoice_no')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamps();
        });

        // Accounts users table
        Schema::create('accounts_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('accounts_users');
    }
};
