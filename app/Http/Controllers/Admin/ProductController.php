<?php

namespace App\Http\Controllers\Admin;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    /**
     * Display a listing of all products
     */
    public function index()
    {
        $products = Product::all();

        return response()->json([
            'success' => true,
            'message' => 'Data produk berhasil diambil',
            'data' => $products,
            'count' => count($products),
        ], 200);
    }

    /**
     * Store a newly created product
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|unique:products,code',
            'name' => 'required|string',
            'weight_kg' => 'required|numeric|min:0.01',
            'selling_price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $product = Product::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil dibuat',
            'data' => $product,
        ], 201);
    }

    /**
     * Display a specific product
     */
    public function show($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Produk tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil diambil',
            'data' => $product,
        ], 200);
    }

    /**
     * Update a product
     */
    public function update(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Produk tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|string|unique:products,code,' . $id,
            'name' => 'sometimes|string',
            'weight_kg' => 'sometimes|numeric|min:0.01',
            'selling_price' => 'sometimes|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // The weight is what a buyer pays for, and past deliveries and sales
        // were recorded in ball against this weight. Changing it now would
        // make every historical delivery and sale mean something different,
        // so a product that is already in use has to keep its weight.
        if ($request->has('weight_kg') && (float) $request->weight_kg !== (float) $product->weight_kg) {
            $deliveryCount = $product->deliveryItems()->count();
            $saleCount = $product->sales()->count();
            $hasProductions = $product->productions()->exists();

            if ($deliveryCount > 0 || $saleCount > 0 || $hasProductions) {
                return response()->json([
                    'success' => false,
                    'message' => 'Berat produk tidak dapat diubah karena sudah digunakan',
                    'errors' => [
                        'weight_kg' => [
                            $hasProductions
                                ? 'Locked: this product already has production records.'
                                : "Locked: {$deliveryCount} delivery record(s) and {$saleCount} sale(s) already use this weight.",
                        ],
                    ],
                ], 422);
            }
        }

        $product->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil diperbarui',
            'data' => $product,
        ], 200);
    }

    /**
     * Delete a product
     */
    public function destroy($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Produk tidak ditemukan',
            ], 404);
        }

        // Check references before deleting. Without this the foreign key
        // restriction raises a database error that surfaces as a 500 with raw
        // SQL in the body, instead of a clear message telling the caller why
        // the product is still needed.
        //
        // Delivery rows are the reason a product counts as "in a freezer":
        // the freezer no longer stores a product column, so this is also what
        // freezers()->count() used to report. These rows cascade on delete,
        // which would silently erase delivery history, so deletion is refused
        // rather than allowed to wipe it.
        $deliveryCount = $product->deliveryItems()->count();
        $saleCount = $product->sales()->count();
        $hasProductions = $product->productions()->exists();

        if ($deliveryCount > 0 || $saleCount > 0 || $hasProductions) {
            $reasons = [];
            if ($deliveryCount > 0) {
                $reasons[] = "delivered to {$deliveryCount} freezer record(s)";
            }
            if ($saleCount > 0) {
                $reasons[] = "referenced by {$saleCount} sale(s)";
            }
            if ($hasProductions) {
                $reasons[] = 'has production records';
            }

            return response()->json([
                'success' => false,
                'message' => 'Produk tidak dapat dihapus karena masih digunakan',
                'reason' => implode(', ', $reasons),
                'delivery_count' => $deliveryCount,
                'sale_count' => $saleCount,
                'hint' => 'Freeze a new product code for a different weight instead of editing or deleting this one.',
            ], 422);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil dihapus',
        ], 200);
    }
}
