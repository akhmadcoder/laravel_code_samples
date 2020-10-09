<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\Product\Product;
use App\Models\Product\ProductMedia;
use App\Models\ProductBundle;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Mockery\Exception;

class ProductController extends ApiController
{

    /**
     * OrderController constructor.
     */
    public function __construct()
    {
        // This will automatically add the "can" middleware to each function
         $this->authorizeResource(Product::class, 'product');
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index(Request $request)
    {

        $limit = min(intval($request->get('limit', 10)), self::DEFAULT_MAX_LIMIT);

        $search = $request->input('search');

        $category_id = $request->input('category_id');
        $sort_field = $request->input('sort_field');
        $sort_type = $request->input('sort_type');

        if (auth()->check() && auth()->user()->isA(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN)) {
            $query = Product::with(['images', 'supplier', 'category']);
        } else {
            $query = Product::with(['images', 'supplier', 'category'])->active();
        }

        $bundle = $request->input('bundle');

        if (!empty($category_id)) {
            $query->where('category_id', $category_id);
        }

        if (!empty($bundle)) {
            $query->has('bundle');
        }

        if (!empty($search)) {
            $query = $query->where(function($q) use ($search) {
                $q->where('name', 'LIKE', '%' . $search . '%')
                    ->orWhere('sku', 'LIKE', '%' . $search . '%')
                    ->orWhere('upc', 'LIKE', '%' . $search . '%')
                    ->orWhere('barcode', 'LIKE', '%' . $search . '%');
            });
        }

        $featured = $request->input('featured');
        if (!empty($featured)) {
            $query = $query->featured();
        }

        $hostSpecial = $request->input('host_special');
        if (!empty($hostSpecial)) {
            $query = $query->hostSpecial();
        }

        if (!empty($sort_field) && !empty($sort_type)) {

            if (Schema::hasColumn($query->getModel()->getTable(), $sort_field)) {
                $query = $query->orderBy($sort_field, $sort_type);
            } else {

                $query->with(['user' => function($q) use ($sort_type, $sort_field) {
                    $sortColumn = substr($sort_field, strpos($sort_field, ".") + 1);
                    $q->orderBy('users.'.$sortColumn, $sort_type);
                }]);
            }

        } else {
            $query = $query->orderBy('created_at','asc');
        }

        return $this->respondPagination($request, $query->paginate($limit));
    }

    public function show(Product $product)
    {
        $product->load(['bundle','images', 'supplier', 'category','myWishlist']);
        return $this->respond($product);
    }

    /**
     * Stores the resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $input = $request->input();

        $validator = Validator::make($input, [
            'images' => 'nullable|array',
            'images.*' => 'image',
            'name' => 'required|min:3',
            'uom' => 'required|min:2',
            'sku' => 'nullable|unique:products,sku',
            'bundle' => 'array',
            'bundle.*.product_id' => 'exists:products,id',
            'bundle.*.quantity' => 'integer|min:1|max:999',
        ]);

        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }
        if (empty($input['sku'])) {
            $input['sku'] = Product::getNewSku();
        }

        try {
            $product = null;
            DB::transaction(function() use ($input, $request, &$product) {
                $input['available_stock'] = $input['stock'] ?? 0;
                $product = Product::create($input);

                if (isset($request->images)) {
                    foreach ($request->images as $index => $image) {

                        $path = singleImageFileUpload($image, 'images/products/'.$product->id);

                        ProductMedia::create([
                            'url' => $path,
                            'media_type' => 'image',
                            'is_default' => ($request->is_default[$index]) ?: 0,
                            'product_id' => $product->id,
                        ]);
                    }

                }

                if (!empty($input['bundle'])) {
                    foreach ($input['bundle'] as $bundledProduct) {
                        ProductBundle::create([
                            'main_product_id' => $product->id,
                            'product_id' => $bundledProduct['product_id'],
                            'quantity' => $bundledProduct['quantity']
                        ]);
                    }
                }
            });
            if ($product) {
                $product = $product->fresh(['images', 'supplier', 'bundle']);

                return $this->respondCreated($product->toArray());
            }
            return $this->respondInternalError('Unknown error occurred.');
        } catch (Exception $exception){
            return $this->respondInternalError('Internal Server Error!', $exception);
        }
    }

    /**
     * Updates the resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param Product $product
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, Product $product)
    {
        $input = $request->input();

        $validator = Validator::make($input, [
            'name' => 'required|min:3',
            'uom' => 'required|min:2',
            'sku' => 'required|unique:products,sku,' . $product->id,
        ]);

        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }

        $oldStock = $product->stock;
        $oldAvailStock = $product->available_stock;

        $difference = $oldStock - $oldAvailStock;

        $input['available_stock'] = $input['stock'] ?? $oldStock - $difference;

        if (empty($input['is_host_special'])) {
            $input['is_host_special'] = 0;
        } else {
            $input['is_host_special'] = 1;
        }

        // Update Bundle Products
        if (!empty($input['bundle'])) {

            ProductBundle::where('main_product_id', $product->id)->delete();

            foreach ($input['bundle'] as $bundledProduct) {
                ProductBundle::create([
                    'main_product_id' => $product->id,
                    'product_id' => $bundledProduct['product_id'],
                    'quantity' => $bundledProduct['quantity']
                ]);
            }
        }


        $product->update($input);

        return $this->respond($product);

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Product $product
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function destroy(Product $product)
    {
        $product->delete();
        return $this->respond();
    }

    /**
     * Updates the resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param Product $product
     * @param ProductMedia $media
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function updateMedia(Request $request, Product $product, ProductMedia $media)
    {
        $this->authorize('update', $product);
        if ($media->product_id != $product->id) {
            return $this->respondNotFound();
        }

        $input = $request->only(['position', 'is_default']);

        $media->update($input);

        return $this->respond($media);

    }

    /**
     * Updates the resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param Product $product
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function addImages(Request $request, Product $product)
    {
        $this->authorize('update', $product);
        $validator = Validator::make($request->input(), [
            'images' => 'required|array',
            'images.*' => 'image',
        ]);

        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }

        foreach ($request->images as $image) {
            /** @var UploadedFile $image */
            $path = $image->storePublicly('products/' . $product->id);
            ProductMedia::create([
                'url' => $path,
                'media_type' => 'image',
                'product_id' => $product->id,
            ]);
        }

        $product = $product->fresh(['images']);

        return $this->respond($product);

    }

    public function addMedia(Request $request, Product $product, ProductMedia $media)
    {
        $this->authorize('update', $product);

        if ($request->file('image')) {
            $path = singleImageFileUpload($request->file('image'), 'images/products/'.$product->id);

            $media = ProductMedia::create([
                    'url' => $path,
                    'media_type' => 'image',
                    'is_default' => 0,
                    'product_id' => $product->id,
                ]);

            $product = $product->fresh(['images']);

            return $this->respond($media);

        } else {
            return $this->respondInternalError($message = 'No media attached, please check your uploads.');
        }
    }

    /**
     * Updates the resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param Product $product
     * @param ProductMedia $media
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function removeMedia(Request $request, Product $product, ProductMedia $media)
    {
        $this->authorize('update', $product);
        if ($media->product_id != $product->id) {
            return $this->respondNotFound();
        }
        Storage::delete($media->url);

        $media->delete();

        return $this->respond();

    }

    public function updateDefaultMedia(Request $request, Product $product, ProductMedia $media)
    {
        $this->authorize('update', $product);

        if ($media->product_id != $product->id) {
            return $this->respondNotFound();
        }

        $input = $request->only(['position', 'is_default']);

        // Set all this Product media is not default
        $product->images()->update(['is_default' => 0]);
        $product->save();
        $product->fresh(['images']);

        // Set selected Default Value
        $media->update($input);

        return $this->respond($media);

    }

}
