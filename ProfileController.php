<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\GeneratedWishlist;
use App\Models\GeneratedWishlistItem;
use App\Models\Order;
use App\Models\Product\Product;
use App\Models\Wishlist;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;


class ProfileController extends ApiController
{

    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function orderHistory(Request $request)
    {
        $limit = min(intval($request->get('limit', 10)), self::DEFAULT_MAX_LIMIT);

        $search = $request->input('search');
        $fulfillmentStatus = $request->input('fulfillment_status');
        
        $host = $request->input('host');
        if (empty($host)) {
            $query = Auth::user()->orders();
        } else {
            $query = Order::where('host_id', Auth::user()->id)->uncancelled();
        }

        if (!empty($search)) {
            $query = $query->where(function($q) use ($search) {
                $q->where('id', 'LIKE', '%' . $search . '%');
            });
        }
        $past = $request->input('past');
        if (!empty($past)) {
            $query = $query->where('created_at', '<', now()->startOfDay());
        }
        $today = $request->input('today');
        if (!empty($today)) {
            $query = $query->where('created_at', '>=', now()->startOfDay());
        }

        if ($fulfillmentStatus != '') {
            $query = $query->where('fulfillment_status', $fulfillmentStatus);
        }

        if (!empty($sort_field) && !empty($sort_type)) {
            if (Schema::hasColumn($query->getModel()->getTable(), $sort_field)) {
                $query = $query->orderBy($sort_field, $sort_type);
            }
        } else {
            $query = $query->orderBy('id','desc');
        }

        return $this->respondPagination($request, $query->paginate($limit));
    }

    public function orderCounter(Request $request)
    {
        $query = Order::where('user_id', Auth::user()->id)->get();

        $return = [
            'total' => $query->count(),
            'total_to_pay' => $query->where('fulfillment_status', 0)->count(),
            'total_to_ship' => $query->where('fulfillment_status', 10)->count(),
            'total_to_receive' => $query->where('fulfillment_status', 50)->count(),
            'total_to_review' => $query->where('fulfillment_status', 60)->count(),
            'total_refund' => $query->where('fulfillment_status', 20)->count(),
        ];

        return response()->json($return);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        $input = $request->input();

        $validator = Validator::make($input, [
            'name' => 'required|min:3',
            'phone_number' => 'required|max:16|min:8',
            'email' => 'required|unique:users,email,' . $user->id,
            'date_of_birth' => 'nullable|date_format:Y-m-d',
        ]);

        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }

        if (!empty($input['password'])) {
            $input['password'] = Hash::make($input['password']);
        }
        $user->update($input);

        if ($request->hasFile('image')) {

            $folder = public_path('storage/images/users');

            if (!File::isDirectory($folder)){
                File::makeDirectory($folder, 0777, true, true);
            }

            $filename = singleImageFileUpload($request->file('image'), $folder,  512);

            $user->profile_picture = $filename;
            $user->save();
        }

        $user = $user->fresh();

        return $this->respond($user);

    }

    /**
     * Display the specified resource.
     *
     * @param User $user
     * @return \Illuminate\Http\Response
     */
    public function self()
    {
        return $this->respond(Auth::user()->toArray());
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param User $user
     * @return void
     */
    public function update(Request $request, User $user)
    {
        $input = $request->input();

        $validator = Validator::make($input, [
            'name' => 'required|min:3',
            'phone_number' => 'required|max:16|min:8',
            'email' => 'required|unique:users,email,' . $user->user_id,
            'date_of_birth' => 'nullable|date_format:Y-m-d',
        ]);

        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }

        if (!empty($input['password'])) {
            $input['password'] = Hash::make($input['password']);
        }
        $user->update($input);

        if ($request->hasFile('image')) {

            $folder = public_path('storage/images/users');

            if (!File::isDirectory($folder)){
                File::makeDirectory($folder, 0777, true, true);
            }

            $filename = singleImageFileUpload($request->file('image'), $folder,  512);

            $user->profile_picture = $filename;
            $user->save();
        }

        $user = $user->fresh();

        return $this->respond($user);
    }

    public function addToWishlist(Request $request)
    {
        $productId = $request->input('product_id');
        if (empty($productId)) {
            return $this->respondBadRequestError('Product not specified');
        }
        $product = Product::active()->where('id', $productId)->first();
        if (empty($product)) {
            return $this->respondBadRequestError('Product not found');
        }

        $user = Auth::user();

        if ($user->wishlist()->where('product_id', $productId)->count()) {
            return $this->respondBadRequestError('This product is already on your wishlist!');
        }

        Wishlist::create([
            'user_id' => $user->id,
            'product_id' => $productId,
            'wishlisted_name' => $product->name,
            'wishlisted_price' => $product->getSellingPrice(),
        ]);

        return $this->respond();
    }

    public function removeFromWishlist(Request $request)
    {
        $productId = $request->input('product_id');
        if (empty($productId)) {
            return $this->respondBadRequestError('Product not specified');
        }

        $user = Auth::user();

        $wishlist = $user->wishlist()->where('product_id', $productId)->first();
        if (empty($wishlist)) {
            return $this->respondBadRequestError('This product is not on your wishlist!');
        }

        $wishlist->delete();

        return $this->respond();
    }

    public function wishlist(Request $request)
    {
        $limit = min(intval($request->get('limit', 10)), self::DEFAULT_MAX_LIMIT);

        $search = $request->input('search');

        $query = Auth::user()->wishlist()->with(['product.images']);

        if (!empty($search)) {
            $query = $query->where('wishlisted_name', 'LIKE', '%' . $search . '%');
        }

        if (!empty($sort_field) && !empty($sort_type)) {
            if (Schema::hasColumn($query->getModel()->getTable(), $sort_field)) {
                $query = $query->orderBy($sort_field, $sort_type);
            }
        } else {
            $query = $query->orderBy('id', 'desc');
        }

        return $this->respondPagination($request, $query->paginate($limit));
    }

    public function generateWishlist()
    {
        $items = Auth::user()->wishlist;
        if ($items->count() === 0) {
            return $this->respondBadRequestError('You do not have anything in your wishlist!');
        }
        $wishlist = GeneratedWishlist::create([
            'user_id' => Auth::user()->id,
            'code' => GeneratedWishlist::randomId(),
        ]);
        foreach ($items as $item) {
            GeneratedWishlistItem::create([
                'generated_wishlist_id' => $wishlist->id,
                'product_id' => $item->product_id,
                'price_at_wishlist' => $item->wishlisted_price,
            ]);
        }
        return $this->respondCreated($wishlist->fresh(['items.product']));
    }

    /**
     * @param GeneratedWishlist $wishlist
     * @return GeneratedWishlist
     */
    public function searchWishlist($code)
    {
        $wishlist = GeneratedWishlist::where('code', $code)->first();

        if($wishlist)
        {
            $wishlist->total_views += 1;
            $wishlist->save();
            return $this->respondCreated( $wishlist->load(['items.product.images']));
        }

        return $this->respondCreated([]);
    }

}
