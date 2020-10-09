<?php

namespace App\Http\Controllers\Api\v1;

use Illuminate\Http\Request;
use App\Models\Campaign;
use App\Models\CampaignProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\File;


class CampaignController extends ApiController
{
    /**
     * PromocodeController constructor.
     */
     public function __construct()
     {
         // This will automatically add the "can" middleware to each function
         $this->authorizeResource(Campaign::class, 'campaign');
     }

     public function activeCampaigns()
     {
         $campaigns = Campaign::with(['products.product.images'])->where(function ($query) {
             $query->where('start_date', '<=', now())->orWhereNull('start_date');
         })->where(function ($query) {
             $query->where('end_date', '>=', now())->orWhereNull('end_date');
         });

         if (request()->has('type')) {
             $campaigns->where('type', request()->type);
         }
         if (request()->has('layout_type')) {
             $campaigns->where('layout_type', request()->layout_type);
         }
         
         if (request()->first == 'y') {
             return $this->respond($campaigns->first());
         }
         return $this->respond($campaigns->get());
     }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $limit = min(intval($request->get('limit', 10)), self::DEFAULT_MAX_LIMIT);

        $search = $request->input('search');
        // $status = $request->input('status');

        $query = Campaign::query();

        if (!empty($search)) {
            $query = $query->where(function($query) use ($search) {
                $query->where('name', 'LIKE', '%' . $search . '%');
                    // ->orWhere('code', 'LIKE', '%' . $search . '%');
            });
        }

        // if (!empty($status)) {
        //     $query = $query->where('status', $status);
        // }

        $query = $query->orderBy('created_at','desc');

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
        $validator = Validator::make($request->all(), [
            'name' => 'required|min:2|max:255',
            'type' => 'required',
            'layout_type' => 'required',
            'start_date' => 'required|date_format:Y-m-d H:i:s',
            'last_delivery_date' => 'required|date_format:Y-m-d H:i:s',
        ]);

            // validate dates between each other
            $start_date = $request->start_date;
            $last_delivery_date = $request->last_delivery_date;

            if ($start_date >= $last_delivery_date) {
                $validator->errors()->add('last_delivery_date', 'Please select right date.');
                return response()->json($validator->errors(), 422);
            }

            if ($request->end_date!=null) {
                $end_date = $request->end_date;

                if($start_date >= $end_date)
                {
                    $validator->errors()->add('start_date', 'Please select right date.');
                    return response()->json($validator->errors(), 422);
                }

                if ($last_delivery_date < $end_date) {
                    $validator->errors()->add('last_delivery_date', 'Last delivery date needs to be after the end date');
                    return response()->json($validator->errors(), 422);
                }
            }

        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }


        // save selected products into CampaignProduct table
        $events = $request->input('events');
        if (empty($events)) {
            return $this->respondBadRequestError('You need to add at least one product to this campaign.');
        }
        $campaign = null;
        DB::beginTransaction();
        try {
            $input = $request->input();
            unset($input['image']);
            $campaign = Campaign::create($input);

            foreach ($events as $event) {
                if (!empty($event['product']) && !empty($event['product']['id'])) {
                    CampaignProduct::create($event + ['product_id' => $event['product']['id'], 'campaign_id' => $campaign->id]);
                }
            }

            if ($request->hasFile('image')) {

                $path = singleImageFileUpload($request->file('image'), 'images/campaigns');

                $campaign->image = $path;

                $campaign->save();

            }

            $campaign = $campaign->fresh();
            DB::commit();
            return $this->respondCreated($campaign->toArray());
        } catch (\Exception $e) {
            Log::error($e);
            DB::rollBack();
        }
        return $this->respondInternalError();
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
