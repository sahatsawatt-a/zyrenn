<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a model is reached becomes one kind of connection among others --
     * a Telegram bot, a LINE channel, a key for some HTTP API -- owned by a
     * user or by a project, like everything else, and used by chats and
     * workflows alike. The table is renamed, not copied, so ids stay and the
     * rooms that answer through a connection keep it.
     */
    public function up(): void
    {
        Schema::rename('ai_connections', 'connections');

        Schema::table('connections', function (Blueprint $table) {
            $table->renameColumn('kind', 'service');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreignId('project_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->after('project_id')->constrained('users')->nullOnDelete();
            // What there is to know about it: an address, a usual model, how messages are received
            $table->jsonb('settings')->nullable()->after('service');
            // What must stay secret, encrypted as a whole: keys, tokens, signing secrets
            $table->text('secrets')->nullable()->after('settings');

            $table->index('project_id');
        });

        DB::table('connections')->orderBy('id')->each(function (object $row) {
            $key = $row->api_key === null ? null : Crypt::decryptString($row->api_key);

            DB::table('connections')->where('id', $row->id)->update([
                'created_by' => $row->user_id,
                'settings' => json_encode(array_filter([
                    'base_url' => $row->base_url,
                    'default_model' => $row->default_model,
                ], fn ($value) => $value !== null)),
                'secrets' => $key === null ? null : Crypt::encryptString((string) json_encode(['api_key' => $key])),
            ]);
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['base_url', 'api_key', 'default_model']);
        });
    }

    /**
     * Reverse the migrations. Only AI connections have somewhere to go back
     * to, and only a user's own: the rest are deleted.
     */
    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->string('base_url')->default('');
            $table->text('api_key')->nullable();
            $table->string('default_model')->nullable();
        });

        DB::table('connections')
            ->whereNull('user_id')
            ->orWhereNotIn('service', ['ollama', 'lmstudio', 'openrouter', 'openai', 'groq', 'gemini', 'custom'])
            ->delete();

        DB::table('connections')->orderBy('id')->each(function (object $row) {
            $settings = json_decode($row->settings ?? '{}', true) ?: [];
            $secrets = $row->secrets === null ? [] : json_decode(Crypt::decryptString($row->secrets), true);

            DB::table('connections')->where('id', $row->id)->update([
                'base_url' => $settings['base_url'] ?? '',
                'default_model' => $settings['default_model'] ?? null,
                'api_key' => isset($secrets['api_key']) ? Crypt::encryptString($secrets['api_key']) : null,
            ]);
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropIndex(['project_id']);
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['settings', 'secrets']);
            $table->renameColumn('service', 'kind');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::rename('connections', 'ai_connections');
    }
};
