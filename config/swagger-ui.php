<?php

use Wotz\SwaggerUi\Http\Middleware\EnsureUserIsAuthorized;

return [
    'files' => [
        /*
         * Order matters here. The package registers each entry as
         * `path`, `path/oauth2-redirect`, `path/{filename}`, so the entry
         * holding `swagger/{filename}` would swallow `swagger/admin` and
         * answer with a 404. The admin document therefore has to come first.
         */
        [
            /*
             * Admin only. The full spec lists every role on one page, which is a lot
             * to read when building the admin screens and most of it cannot be called
             * with an admin token anyway.
             *
             * Generated from the same openapi.json by `php artisan swagger:admin`, so
             * the two documents never disagree about a value. Edit openapi.json and
             * regenerate; never edit admin.json by hand.
             */
            'path' => 'swagger/admin',

            'title' => env('APP_NAME').' - Swagger (Admin)',

            'versions' => [
                'admin' => resource_path('swagger/admin.json'),
            ],

            'default' => 'admin',

            'middleware' => [
                'web',
                EnsureUserIsAuthorized::class,
            ],

            'validator_url' => env('SWAGGER_UI_VALIDATOR_URL'),

            /*
             * If enabled the file will be modified to set the server url and oauth urls.
             */
            'modify_file' => true,

            /*
             * The server URL configuration for the swagger file.
             */
            'server_url' => env('APP_URL'),

            /*
             * The oauth configuration for the swagger file.
             */
            'oauth' => [
                'token_path' => 'oauth/token',
                'refresh_path' => 'oauth/token',
                'authorization_path' => 'oauth/authorize',

                'client_id' => env('SWAGGER_UI_OAUTH_CLIENT_ID'),
                'client_secret' => env('SWAGGER_UI_OAUTH_CLIENT_SECRET'),
            ],

            /*
             * Path to a custom stylesheet file if you want to customize the look and feel of swagger-ui.
             * The content of the file will be read and added into a style-tag on the swagger-ui page.
             */
            'stylesheet' => null,
        ],
        [
            /*
             * The path where the swagger file is served.
             */
            'path' => 'swagger',

            /*
             * The title of the page where the swagger file is served.
             */
            'title' => env('APP_NAME').' - Swagger',

            /*
             * The versions of the swagger file. The key is the version name and the value is the path to the file.
             *
             * v2 (openapi2.yaml) is hidden: it was a small subset of endpoints with
             * no Step 1 content at all, so it only served to mislead the frontend.
             * Re-enable it once it is actually maintained.
             */
            'versions' => [
                'v1' => resource_path('swagger/openapi.json'),
            ],

            /*
             * The default version that is loaded when the route is accessed.
             */
            'default' => 'v1',

            /*
             * The middleware that is applied to the route.
             */
            'middleware' => [
                'web',
                EnsureUserIsAuthorized::class,
            ],

            /*
             * Specify the validator URL. Set to false to disable validation.
             */
            'validator_url' => env('SWAGGER_UI_VALIDATOR_URL'),

            /*
             * If enabled the file will be modified to set the server url and oauth urls.
             */
            'modify_file' => true,

            /*
             * The server URL configuration for the swagger file.
             */
            'server_url' => env('APP_URL'),

            /*
             * The oauth configuration for the swagger file.
             */
            'oauth' => [
                'token_path' => 'oauth/token',
                'refresh_path' => 'oauth/token',
                'authorization_path' => 'oauth/authorize',

                'client_id' => env('SWAGGER_UI_OAUTH_CLIENT_ID'),
                'client_secret' => env('SWAGGER_UI_OAUTH_CLIENT_SECRET'),
            ],

            /*
             * Path to a custom stylesheet file if you want to customize the look and feel of swagger-ui.
             * The content of the file will be read and added into a style-tag on the swagger-ui page.
             */
            'stylesheet' => null,
        ],
    ],
];
