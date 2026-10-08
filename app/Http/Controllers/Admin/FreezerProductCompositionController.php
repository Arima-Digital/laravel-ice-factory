<?php

namespace App\Http\Controllers\Admin;

use App\Models\Freezer;
use App\Models\FreezerProductComposition;
use Illuminate\Routing\Controller;

class FreezerProductCompositionController extends Controller
{
    public function index($freezerId)
    {
        $freezer = Freezer::find($freezerId);

        if (! $freezer) {
            return response()->json([
                'success' => false,
                'message' => 'Freezer not found',
            ], 404);
        }

        $compositions = FreezerProductComposition::where('freezer_id', $freezerId)
            ->with(['product'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Freezer product compositions retrieved successfully',
            'data' => $compositions,
        ], 200);
    }
}
