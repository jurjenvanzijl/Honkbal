<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * client_id: uniek ID dat het scoorscherm per actie aanmaakt. Daarmee kan een actie die
     * offline is ingevoerd veilig opnieuw worden verstuurd zonder dat hij dubbel wordt opgeslagen.
     */
    public function up(): void
    {
        Schema::table('game_events', function (Blueprint $table) {
            $table->string('client_id', 36)->nullable()->after('payload');
            $table->unique(['game_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('game_events', function (Blueprint $table) {
            $table->dropUnique(['game_id', 'client_id']);
            $table->dropColumn('client_id');
        });
    }
};
