<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\DisputeProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\User;
use Illuminate\Http\Request;
use App\Models\Dispute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\Facades\Image;
use Mockery\Exception;


class DisputeController extends ApiController
{

    /**
     * DisputeController constructor.
     */
    public function __construct()
    {
        // This will automatically add the "can" middleware to each function
        $this->authorizeResource(Dispute::class, 'dispute');
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

        $query = Dispute::with(['order']);

        if (!auth()->user()->isA(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN)) {
            $query = $query->where('user_id', auth()->user()->id);
        }

        if (!empty($search)) {
            $query = $query->where(function($query) use ($search) {
                $query->where('order_id', 'LIKE', '%' . $search . '%');
            });
        }
        
        if (!empty($sort_field) && !empty($sort_type)) {
            $query = $query->orderBy($sort_field, $sort_type);
        } else {
            $query = $query->orderBy('created_at','desc');
        }

        return $this->respondPagination($request, $query->paginate($limit));

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        
        $input = $request->input();

        $validator = Validator::make($input, [
            'order_id' => 'required|exists:orders,id|unique:disputes,order_id',
            'description' => 'required',
            'requested_refund_amount' => 'required|numeric|min:0',
            'type' => 'required',
        ]);
        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }

        $order = Order::where('id', $input['order_id'])->first();

        if ($input['requested_refund_amount'] > $order->grand_total) {
            return $this->respondBadRequestError('Amount greater than order.');
        }
        if (auth()->user()->isA(User::ROLE_HOST)) {
            $input['user_id'] = auth()->user()->id;
            if ($order->host_id != $input['user_id']) {
                return $this->respondBadRequestError('Order ID does not exist.');
            }
            $input['status'] = Dispute::STATUS_PENDING;
            $input['refund_status'] = Dispute::REFUND_STATUS_NA;
            unset($input['refund_amount']);
        } else {
            $input['user_id'] = $order->host_id;
            if (is_null($input['status'])) {
                $input['status'] = Dispute::STATUS_PENDING;
            }
            if (is_null($input['refund_status'])) {
                $input['status'] = Dispute::REFUND_STATUS_NA;
            }
        }

        $dispute = Dispute::create($input);
        
        return $this->respondCreated($dispute);

    }

    /**
     * Display the specified resource.
     *
     * @param Dispute $dispute
     * @return \Illuminate\Http\Response
     */
    public function show(Dispute $dispute)
    {
        $dispute->load(['order', 'user']);
        return $this->respond($dispute);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param Dispute $dispute
     * @return void
     */
    public function update(Request $request, Dispute $dispute)
    {
        
        $input = $request->except(['order_id', 'user_id']);

        $validator = Validator::make($input, [
            'description' => 'required',
            'requested_refund_amount' => 'required|numeric|min:0',
            'type' => 'required',
        ]);
        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }
        
        $dispute->update($input);

        $dispute = $dispute->fresh(['order', 'user']);

        return $this->respond($dispute);
    }
    
    public function refund(Request $request, Dispute $dispute)
    {
        // $input = $request->except(['order_id', 'user_id']);
        $input = $request->input();

        $validator = Validator::make($input, [
            'refund_amount' => 'required|numeric|min:1',
        ]);

        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }

        if ($input['refund_amount'] > $input['requested_refund_amount'] ) {
            $validator->errors()->add('refund_amount', 'You entered higher than requested amount.');
                    return response()->json($validator->errors(), 422);
        } 

        if ($input['refund_amount'] < $input['requested_refund_amount'] ) {
            $input['status'] = Dispute::STATUS_PARTIAL_REFUND;
        } 
        
        if ($input['refund_amount'] == $input['requested_refund_amount'] ) {
            $input['status'] = Dispute::STATUS_FULL_REFUND;
        } 
        
        $input['refund_status'] = Dispute::REFUND_STATUS_COMPLETED;
        $dispute->update($input);

        // $dispute = $dispute->fresh(['order', 'user']);

        return $this->respond($dispute);
    }
    
    public function exchange(Request $request, Dispute $dispute)
    {
        $this->authorize('update', $dispute);

        $input = $request->input();
        $validator = Validator::make($input, [
            'products' => 'required|array',
            'products.*.order_item_id' => 'required|exists:order_items,id',
            'products.*.quantity' => 'required|integer|min:1',
        ]);
        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }
        
        $created = [];
        DB::beginTransaction();
        foreach ($input['products'] as $product) {
            $orderItem = OrderItem::where('id', $product['order_item_id'])->where('order_id', $dispute->order_id)->first();
            if (empty($orderItem)) {
                DB::rollBack();
                return $this->respondBadRequestError('Order item id does not exist.');
            }
            if ($product['quantity'] > $orderItem->quantity) {
                DB::rollBack();
                return $this->respondBadRequestError('Quantity must be smaller or equal to order item quantity.');
            }
            $created[] = DisputeProduct::create([
                'dispute_id' => $input['id'],
                'order_item_id' => $product['order_item_id'],
                'quantity' => $product['quantity'],
                'notes' => $product['notes'] ?? null,
            ]);
            
        }
        
        $dispute->status = Dispute::STATUS_EXCHANGED;
        $dispute->save();

        DB::commit();

        return $this->respondCreated($created);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Dispute $dispute
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function destroy(Dispute $dispute)
    {
        $dispute->delete();
        return $this->respond();
    }

}
