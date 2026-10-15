<?php

namespace App\Http\Controllers\Admin;

use App\Models\Freezer;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class FreezerLoadConfigController extends Controller
{
    /**
     * The refill mix a freezer should be loaded with, per product.
     *
     * GET /api/freezers/{freezerId}/load-config
     */
    public function show($freezerId)
    {
        $freezer = Freezer::with(['productSettings.product'])->find($freezerId);

        if (! $freezer) {
            return response()->json([
                'success' => false,
                'message' => 'Freezer tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Freezer load config retrieved successfully',
            'data' => $this->payload($freezer),
        ], 200);
    }

    /**
     * Replace the refill mix and note for a freezer.
     *
     * PUT /api/freezers/{freezerId}/load-config
     */
    public function update(Request $request, $freezerId)
    {
        $freezer = Freezer::find($freezerId);

        if (! $freezer) {
            return response()->json([
                'success' => false,
                'message' => 'Freezer tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'note' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.percentage' => 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $items = $request->input('items');

        // A product listed twice would let two shares for the same product be
        // read as one, and the total would silently drift from a hundred.
        $productIds = array_map(fn ($row) => (int) $row['product_id'], $items);
        if (count($productIds) !== count(array_unique($productIds))) {
            return response()->json([
                'success' => false,
                'message' => 'Produk yang sama tercantum lebih dari satu kali',
                'errors' => ['items' => ['Each product may appear only once']],
            ], 422);
        }

        // The shares describe one full refill, so they have to add up to a
        // whole. Anything else leaves part of the load unaccounted for or
        // promises more than there is.
        $total = round(array_sum(array_map(fn ($row) => (float) $row['percentage'], $request->input('items'))), 2);
        if (abs($total - 100) > 0.01) {
            return response()->json([
                'success' => false,
                'message' => 'Total persentase harus sama dengan 100',
                'errors' => ['items' => ['The product shares must add up to 100']],
                'total' => $total,
            ], 422);
        }

        $freezer->update(['load_note' => $request->input('note')]);

        $freezer->productSettings()->delete();
        $freezer->productSettings()->createMany(array_map(fn ($row) => [
            'product_id' => $row['product_id'],
            'percentage' => $row['percentage'],
        ], $request->input('items')));

        $freezer->load('productSettings.product');

        return response()->json([
            'success' => true,
            'message' => 'Freezer load config updated successfully',
            'data' => $this->payload($freezer),
        ], 200);
    }

    /**
     * Shape the config the same way whether it was hand-set or is the default,
     * so the frontend always reads one list of products and shares.
     *
     * @return array<string, mixed>
     */
    private function payload(Freezer $freezer): array
    {
        $settings = $freezer->productSettings;
        $isDefault = $settings->isEmpty();

        if ($isDefault) {
            $ratio = Product::defaultLoadRatio();
            $items = Product::whereIn('id', array_keys($ratio))
                ->orderByDesc('weight_kg')
                ->get()
                ->map(fn (Product $product) => [
                    'product_id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'weight_kg' => $product->weight_kg,
                    'percentage' => $ratio[$product->id],
                ])
                ->values()
                ->all();
        } else {
            $items = $freezer->productSettings
                ->map(fn ($setting) => [
                    'product_id' => $setting->product_id,
                    'code' => $setting->product->code,
                    'name' => $setting->product->name,
                    'weight_kg' => $setting->product->weight_kg,
                    'percentage' => (float) $setting->percentage,
                ])
                ->values()
                ->all();
        }

        return [
            'freezer_id' => $freezer->id,
            'freezer_code' => $freezer->code,
            'note' => $freezer->load_note,
            'is_default' => $freezer->productSettings->isEmpty(),
            'items' => $items,
        ];
    }
}
