<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('student')->index();
        });
        Schema::create('courses', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('slug')->unique();
            $t->text('description');
            $t->string('category')->default('Kajian Islam');
            $t->foreignId('mentor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('published')->default(false);
            $t->timestamps();
        });
        Schema::create('modules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->unsignedInteger('position')->default(1);
            $t->text('body')->nullable();
            $t->text('video_url')->nullable();
            $t->string('attachment')->nullable();
            $t->boolean('assignment_required')->default(false);
            $t->text('assignment_prompt')->nullable();
            $t->string('quiz_question')->nullable();
            $t->json('quiz_options')->nullable();
            $t->unsignedSmallInteger('quiz_correct')->nullable();
            $t->boolean('published')->default(false);
            $t->timestamps();
            $t->index(['course_id', 'position']);
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('type')->default('single');
            $t->unsignedBigInteger('price');
            $t->unsignedInteger('duration_days')->default(30);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('product_courses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->unique(['product_id', 'course_id']);
        });
        Schema::create('coupons', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->unsignedSmallInteger('percent');
            $t->unsignedInteger('quota');
            $t->timestampTz('expires_at');
            $t->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('coupon_id')->nullable()->constrained();
            $t->string('title');
            $t->string('type');
            $t->unsignedInteger('duration_days');
            $t->json('course_ids');
            $t->unsignedBigInteger('subtotal');
            $t->unsignedBigInteger('discount')->default(0);
            $t->unsignedBigInteger('total');
            $t->string('status')->default('pending');
            $t->uuid('checkout_key')->unique();
            $t->timestampTz('expires_at');
            $t->timestampTz('paid_at')->nullable();
            $t->string('payment_reference')->nullable()->unique();
            $t->text('payment_note')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
        Schema::create('access_terms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('order_id')->unique()->constrained();
            $t->json('course_ids');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->timestampTz('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'product_id']);
        });
        Schema::create('module_progress', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('module_id')->constrained()->cascadeOnDelete();
            $t->timestampTz('completed_at');
            $t->unique(['user_id', 'module_id']);
        });
        Schema::create('private_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('module_id')->constrained()->cascadeOnDelete();
            $t->text('body');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['user_id', 'module_id']);
        });
        Schema::create('discussion_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('module_id')->constrained()->cascadeOnDelete();
            $t->text('body');
            $t->boolean('hidden')->default(false);
            $t->timestamps();
            $t->index(['module_id', 'created_at']);
        });
        Schema::create('quiz_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('module_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('answer');
            $t->boolean('passed');
            $t->string('question_snapshot');
            $t->timestamps();
        });
        Schema::create('assignment_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('module_id')->constrained()->cascadeOnDelete();
            $t->text('body')->nullable();
            $t->string('attachment')->nullable();
            $t->unsignedSmallInteger('grade')->nullable();
            $t->text('feedback')->nullable();
            $t->foreignId('grader_id')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('certificates', function (Blueprint $t) {
            $t->id();
            $t->uuid('code')->unique();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('course_id')->constrained();
            $t->string('student_name');
            $t->string('course_title');
            $t->timestamps();
            $t->unique(['user_id', 'course_id']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('action');
            $t->string('subject');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'certificates', 'assignment_submissions', 'quiz_attempts', 'discussion_posts', 'private_notes', 'module_progress', 'access_terms', 'orders', 'coupons', 'product_courses', 'products', 'modules', 'courses'] as $table) {
            Schema::dropIfExists($table);
        }Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['role']);
            $t->dropColumn('role');
        });
    }
};
