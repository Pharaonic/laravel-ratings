<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\Ratings\Support\RatingManager;

/**
 * One row per rater and rateable pair. The ids are strings, so integer, UUID
 * and ULID keys can share the table, and the unique index is what keeps a
 * pair to one rating, even under concurrent requests.
 *
 * The unique index holds 191 + 64 + 191 + 64 characters: 2,040 bytes in
 * utf8mb4, under InnoDB's 3,072-byte limit, with no column over the 767-byte
 * prefix limit of older MySQL row formats.
 *
 * The table goes on the configured model's connection, with its table name.
 */
class CreatePharaonicRatingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $model = RatingManager::newModel();
        $name = $model->getTable();
        $schema = Schema::connection($model->getConnectionName());

        if ($schema->hasTable($name)) {
            throw new RuntimeException(sprintf(
                'The [%s] table already exists, so pharaonic/laravel-ratings cannot create it. '
                .'Set pharaonic.ratings.table to another name, or move the existing table out of the way.',
                $name
            ));
        }

        $schema->create($name, function (Blueprint $table) use ($name) {
            $table->bigIncrements('id');
            $table->string('rater_type', 191);
            $table->string('rater_id', 64);
            $table->string('rateable_type', 191);
            $table->string('rateable_id', 64);
            // Up to 999999.9999: six whole digits and four decimal places.
            $table->decimal('value', 10, 4);
            $table->text('comment')->nullable();
            $table->timestamps();

            // Also serves "ratings of this model" and its aggregates: its first two columns.
            $table->unique(['rateable_type', 'rateable_id', 'rater_type', 'rater_id'], $name.'_unique_pair');
            $table->index(['rater_type', 'rater_id'], $name.'_rater_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $model = RatingManager::newModel();

        Schema::connection($model->getConnectionName())->dropIfExists($model->getTable());
    }
}
