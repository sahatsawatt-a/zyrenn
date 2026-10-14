<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * A project's customer, whichever services they write on: each of their
     * chats points to them. What is noted about them by hand is kept with
     * them, once; what a workflow keeps of one service (their id there,
     * their language) stays with that chat.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('ref_id', 16)->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // A picture's address: the face of one of their chats, until one is chosen
            $table->text('picture')->nullable();
            // What is the same for them on every service -- an email, a phone number -- that a workflow joins chats by
            $table->string('key', 190)->nullable();
            // {name: value}, noted by hand
            $table->jsonb('details')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'key']);
        });

        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('tags')->constrained()->nullOnDelete();
        });

        // Each chat with a customer there already is someone of its own, with the notes made in it
        foreach (DB::table('chat_rooms')->where('kind', 'customer')->get() as $room) {
            $details = json_decode((string) $room->details, true) ?: [];
            $kept = json_decode((string) $room->details_kept, true) ?: [];
            $noted = array_diff_key($details, array_flip($kept));

            $contact = DB::table('contacts')->insertGetId([
                'ref_id' => Str::lower(Str::random(10)),
                'project_id' => $room->project_id,
                'name' => mb_substr((string) ($details['name'] ?? '') ?: (string) $room->title, 0, 120) ?: 'Someone',
                'picture' => $room->picture,
                'details' => $noted === [] ? null : json_encode($noted),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $left = array_intersect_key($details, array_flip($kept));
            DB::table('chat_rooms')->where('id', $room->id)->update([
                'contact_id' => $contact,
                'details' => $left === [] ? null : json_encode($left),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
        });

        Schema::dropIfExists('contacts');
    }
};
