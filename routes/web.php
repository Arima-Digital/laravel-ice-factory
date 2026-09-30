<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| All API endpoints now live in routes/api.php
|--------------------------------------------------------------------------
| Every endpoint this application exposes used to be split across two files:
| master data under /api, and transactions under /admin. That split is gone.
|
| The frontend and the API team consume one prefix, /api, so Swagger shows a
| single surface and there is no second URL shape to remember.
|
| The former /admin/* routes were moved verbatim: same paths, same HTTP verbs,
| same role middleware. Only the prefix changed.
|
| CSRF note: /api is registered outside the `web` middleware group, so these
| routes are never CSRF checked. The old `admin/*` CSRF exemption that lived in
| bootstrap/app.php has been removed because nothing needs it any more.
|
| If you still need a server-rendered page, add it here. For an API endpoint,
| add it to routes/api.php.
*/
