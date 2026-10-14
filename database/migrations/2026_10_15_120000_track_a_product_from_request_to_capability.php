<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A project's product, tracked: what it is (a tree of components and the
     * capabilities under them, each with a code that is never reused, and
     * what each uses or depends on), what people asked of it (requests, kept
     * word for word), what it must do because of that (requirements), and
     * the work done about it (the project's tickets, linked to both).
     */
    public function up(): void
    {
        Schema::create('product_counters', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // CMP, CAP, RQ, REQ: each counted on its own, never going back
            $table->string('prefix', 8);
            $table->unsignedInteger('last')->default(0);

            $table->primary(['project_id', 'prefix']);
        });

        Schema::create('product_elements', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('product_elements')->nullOnDelete();
            // component: a part of the product; capability: something it can do
            $table->string('kind', 16);
            $table->string('code', 16);
            $table->string('name', 160);
            // What it does today
            $table->text('summary')->nullable();
            $table->string('status', 16)->default('planned');
            // Built once and used from several places
            $table->boolean('shared')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'code']);
            $table->index(['project_id', 'parent_id']);
        });

        Schema::create('product_element_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_element_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // made, status, renamed, moved, summary, linked, unlinked
            $table->string('what', 16);
            $table->string('from', 255)->nullable();
            $table->string('to', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_element_id', 'id']);
        });

        Schema::create('product_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_id')->constrained('product_elements')->cascadeOnDelete();
            $table->foreignId('to_id')->constrained('product_elements')->cascadeOnDelete();
            // uses, depends_on, extends, replaces, related
            $table->string('kind', 16);
            $table->timestamps();

            $table->unique(['from_id', 'to_id', 'kind']);
            $table->index('to_id');
        });

        Schema::create('product_requests', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16);
            // What was asked, word for word: never changed once kept
            $table->text('original');
            // typed, chat, ticket -- and where it came from
            $table->string('source', 16)->default('typed');
            $table->foreignId('chat_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_name', 160)->nullable();
            // new, analysing, clarifying, split, rejected, done
            $table->string('status', 16)->default('new');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'code']);
        });

        Schema::create('requirements', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16);
            $table->foreignId('product_request_id')->nullable()->constrained()->nullOnDelete();
            // The need it serves: the problem behind the request
            $table->text('need')->nullable();
            $table->string('title', 200);
            $table->text('body')->nullable();
            // new, additional, change, constraint, quality
            $table->string('kind', 16)->default('new');
            // draft, clarifying, ready, building, verifying, verified, released, dropped
            $table->string('status', 16)->default('draft');
            // unknown, partial, confirmed
            $table->string('understanding', 16)->default('partial');
            $table->string('priority', 8)->default('normal');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            // [text], [text], [{text, met}]
            $table->jsonb('questions')->nullable();
            $table->jsonb('assumptions')->nullable();
            $table->jsonb('criteria')->nullable();
            // A change to another requirement, which is kept as it was
            $table->foreignId('changes_id')->nullable()->constrained('requirements')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'code']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('requirement_element', function (Blueprint $table) {
            $table->foreignId('requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_element_id')->constrained()->cascadeOnDelete();

            $table->primary(['requirement_id', 'product_element_id']);
            $table->index('product_element_id');
        });

        Schema::create('requirement_ticket', function (Blueprint $table) {
            $table->foreignId('requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();

            $table->primary(['requirement_id', 'ticket_id']);
            $table->index('ticket_id');
        });

        Schema::create('element_ticket', function (Blueprint $table) {
            $table->foreignId('product_element_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();

            $table->primary(['product_element_id', 'ticket_id']);
            $table->index('ticket_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['element_ticket', 'requirement_ticket', 'requirement_element', 'requirements', 'product_requests', 'product_links', 'product_element_events', 'product_elements', 'product_counters'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
