<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local Development Staff
    |--------------------------------------------------------------------------
    |
    | Optional. The email of an already-existing local user to promote to
    | the Administrator role (see database/seeders/LocalStaffSeeder.php).
    | Never creates a user or a credential of any kind. The seeder itself
    | additionally refuses to run outside the local/testing environments,
    | regardless of this value.
    |
    */

    'local_admin_email' => env('LOCAL_STAFF_ADMIN_EMAIL'),

];
