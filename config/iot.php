<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IoT Gateway (PULL model)
    |--------------------------------------------------------------------------
    | Endpoint milik boss yang kita tarik data lewat `php artisan iot:sync`
    | atau trigger HTTP `GET/POST /api/iot/sync` (dipanggil schedule Dokploy).
    | Selama belum ada spec asli, `pullFromIoTGateway()` memakai mock.
    */

    'gateway_url' => env('IOT_GATEWAY_URL', 'https://mock.iot.local/api/freezers'),

    'gateway_token' => env('IOT_GATEWAY_TOKEN', ''),

    'sync_interval_minutes' => (int) env('IOT_SYNC_INTERVAL', 5),

];