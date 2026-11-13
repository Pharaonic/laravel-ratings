<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ratings table
    |--------------------------------------------------------------------------
    |
    | The table that stores every rating. The package migration creates it
    | with this name, and the Rating model reads and writes it. Changing it
    | after the migration ran does not rename the existing table.
    |
    */

    'table' => 'ratings',

    /*
    |--------------------------------------------------------------------------
    | Rating model
    |--------------------------------------------------------------------------
    |
    | The model the package reads, writes, and returns. It must be the package
    | Rating model or a subclass of it.
    |
    */

    'model' => \Pharaonic\Laravel\Ratings\Models\Rating::class,

    /*
    |--------------------------------------------------------------------------
    | Default rating scale
    |--------------------------------------------------------------------------
    |
    | The values a rating may take: min, min + step, min + 2 * step, ... up to
    | max. max - min must be a whole number of steps, and every number may
    | have up to 4 decimal places (0.5, 0.25). A rated model can define its
    | own scale with a ratingScale() method. Changing a scale never rewrites
    | stored ratings: a rating outside the new scale is only refused when its
    | value is written again.
    |
    */

    'min' => 1,

    'max' => 5,

    'step' => 1,

    /*
    |--------------------------------------------------------------------------
    | Clean up on delete
    |--------------------------------------------------------------------------
    |
    | When a model that uses CanRate or HasRatings is deleted (or force
    | deleted, when it uses SoftDeletes), delete the ratings it gave or
    | received. A soft delete always keeps them, so a restored model keeps
    | its ratings.
    |
    */

    'cleanup_on_delete' => true,

];
