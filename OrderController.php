<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\Host;
use App\Models\Order;
use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;


class OrderController extends ApiController
{

    /**
     * OrderController constructor.
     */
    public function __construct()
    {
        // This will automatically add the "can" middleware to each function
        $this->authorizeResource(Order::class, 'order');
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
        $sort_field = $request->input('sort_field');
        $sort_type = $request->input('sort_type');

        $host = $request->input('host_id');
        $user = $request->input('user_id');
        $type = $request->input('type');
        $fulfillmentStatus = $request->input('fulfillment_status');

        $query = Order::with(['history', 'items.product', 'user', 'host']);

        if (!empty($host)) {
            $query = $query->where('host_id', $host);
        }

        if (!empty($user)) {
            $query = $query->where('user_id', $user);
        }

        if ($type === 'shipping') {
            // Only gets ready to ship
            $query = $query->whereHas('history', function($q){
                $q->where('new_status', Order::FULFILLMENT_STATUS_READYTOSHIP);
            });
        }

        if (!empty($search)) {
            $query = $query->where(function($q) use ($search) {
                $q->where('id', 'LIKE', '%' . $search . '%')
                ->orWhereHas('user', function($query) use ($search) {
                    $query->where('name', 'LIKE', '%'. $search . '%');  
                });
            });
        }

        if (!empty($fulfillmentStatus)) {
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

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        return $this->respondBadRequestError('Action not supported yet.');
    }

    /**
     * Display the specified resource.
     *
     * @param Order $order
     * @return \Illuminate\Http\Response
     */
    public function show(Order $order)
    {
        $order->load(['host','user', 'promocodes','items.product.images', 'disputes']);
        return $this->respond($order);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param Order $order
     * @return void
     */
    public function update(Request $request, Order $order)
    {
        $input = $request->input();

        $order->update($input);

        $order = $order->fresh(['user']);

        return $this->respond($order);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Order $order
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function destroy(Order $order)
    {
        $order->delete();
        return $this->respond();
    }


    /**
     * Update the specified Order fulfillment status.
     *
     * @param \Illuminate\Http\Request $request
     * @param Order $order
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function updateStatus(Request $request, Order $order)
    {
        $this->authorize('update', $order);
        $input = $request->input();

        $status = $order->fulfillment_status;

        foreach (Order::FULFILLMENT_STATUS_ARRAY as $key => $value) {
            if ($value === $input['status']) {
                $status = $key;
            }
        }

        //$order->history()->create(['order_id' => $order->id, 'old_status' => $order->fulfillment_status, 'new_status' => $status]);
        $order->fulfillment_status = $status;

        $order->save();

        $order = $order->fresh(['user', 'history']);

        return $this->respond($order);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param Order $order
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function submitReview(Request $request, Order $order)
    {
        $this->authorize('view', $order);

        $input = $request->input();

        $validator = Validator::make($input, [
            'products' => 'required|array',
            'products.*.order_item_id' => 'required|exists:order_items,id',
            'products.*.rating' => 'required|integer|min:1|max:5',
        ]);

        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }

        if ($order->reviews()->count()) {
            return $this->respondBadRequestError('You have already submitted the review for this order.');
        }

        $reviews = [];
        DB::beginTransaction();
        foreach ($input['products'] as $item) {
            $orderItem = $order->items()->where('order_items.id', $item['order_item_id'])->first();
            if (empty($orderItem)) {
                DB::rollBack();
                return $this->respondBadRequestError('Order item id does not exist.');
            }
            $reviews[] = ProductReview::create([
                'order_id' => $order->id,
                'product_id' => $orderItem->product_id,
                'user_id' => auth()->user()->id,
                'rating' => $item['rating'],
                'review' => $item['review'] ?? null,
            ]);
        }
        DB::commit();

        return $this->respondCreated($reviews);
    }

    public function groupByHost(Request $request){

        $limit = min(intval($request->get('limit', 10)), self::DEFAULT_MAX_LIMIT);

        $search = $request->input('search');

        $query = Host::with( 'user', 'orders');

        if (!empty($search)) {
            $query = $query->where(function($q) use ($search) {
                $q->where('id', 'LIKE', '%' . $search . '%');
            });
        }


        return $this->respondPagination($request, $query->paginate($limit));
    }

}
