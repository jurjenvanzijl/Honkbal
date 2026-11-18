<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 16)->unique();
            $table->foreignId('home_team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('away_team_id')->constrained('teams')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->string('location')->nullable();
            $table->unsignedTinyInteger('innings_planned')->default(9);
            $table->string('youtube_url')->nullable();
            // Vertraging van het openbare scorebord, zodat het gelijk loopt met de stream.
            $table->unsignedSmallInteger('score_delay_seconds')->default(0);
            $table->foreignId('home_pitcher_id')->nullable()->constrained('players')->nullOnDelete();
            $table->foreignId('away_pitcher_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('status', 10)->default('scheduled'); // scheduled | live | final
            // Gecachte stand voor overzichten; de bron van waarheid zijn de game_events.
            $table->unsignedSmallInteger('home_score')->default(0);
            $table->unsignedSmallInteger('away_score')->default(0);
            $table->string('inning_label', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('game_lineups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('side', 4); // home | away
            $table->unsignedTinyInteger('batting_order'); // 1..n
            $table->foreignId('player_id')->constrained()->restrictOnDelete();
            $table->string('position', 3)->nullable();
            $table->unique(['game_id', 'side', 'batting_order']);
        });

        Schema::create('game_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->json('payload');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['game_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_events');
        Schema::dropIfExists('game_lineups');
        Schema::dropIfExists('games');
    }
};
