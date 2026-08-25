<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Node build kept every override in one content.json:
 *
 *   { texts:{}, styles:{}, notes:{}, slots:{}, theme:{} }
 *
 * Four of those five are flat key -> value maps, so they share one table and
 * are told apart by `group_name`. Notes are the odd one out: they carry a
 * dozen fields each and get queried on their own, so they have their own
 * table. Splitting them this way keeps /api/state to two selects while still
 * letting a single override be written without rewriting the whole document,
 * which is what the JSON file forced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_entries', function (Blueprint $table) {
            $table->id();

            /* texts | styles | slots | theme */
            $table->string('group_name', 16);

            /* Slot ids and style keys come from the templates. api/_store.js
               capped them at 80 characters; 191 leaves room and still fits a
               utf8mb4 index. */
            $table->string('entry_key', 191);

            /* texts hold a string, styles and slots hold a JSON object, theme
               holds a number or a short string. All are stored as text and
               shaped on the way out, so one column serves all four. */
            $table->longText('value');

            $table->timestamps();

            $table->unique(['group_name', 'entry_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_entries');
    }
};
