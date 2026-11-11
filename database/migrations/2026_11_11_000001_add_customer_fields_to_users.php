<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('address')->nullable();
            $table->boolean('marketing_opt_in')->default(false);
            $table->enum('role', ['customer', 'staff', 'admin'])->default('customer')->index();
            // Responsible play (amounts in pence; null = no limit)
            $table->unsignedInteger('limit_daily_pence')->nullable();
            $table->unsignedInteger('limit_weekly_pence')->nullable();
            $table->unsignedInteger('limit_monthly_pence')->nullable();
            $table->json('pending_limit_increases')->nullable();
            $table->timestamp('break_until')->nullable();
            $table->timestamp('self_excluded_until')->nullable();
            $table->timestamp('suspended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'phone', 'date_of_birth', 'address', 'marketing_opt_in', 'role',
                'limit_daily_pence', 'limit_weekly_pence', 'limit_monthly_pence', 'pending_limit_increases',
                'break_until', 'self_excluded_until', 'suspended_at']);
        });
    }
};
