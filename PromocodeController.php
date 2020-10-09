<?php

namespace App\Http\Controllers\Api\v1;

use Illuminate\Http\Request;
use App\Models\Promocode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\Facades\Image;
use Mockery\Exception;


class PromocodeController extends ApiController
{

    /**
     * PromocodeController constructor.
     */
    public function __construct()
    {
        // This will automatically add the "can" middleware to each function
        $this->authorizeResource(Promocode::class, 'promocode');
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
        $status = $request->input('status');

        $sort_field = $request->input('sort_field');
        $sort_type = $request->input('sort_type');

        $query = Promocode::query();

        if (!empty($search)) {
            $query = $query->where(function($query) use ($search) {
                $query->where('name', 'LIKE', '%' . $search . '%')
                    ->orWhere('code', 'LIKE', '%' . $search . '%');
            });
        }

        if (!empty($status)) {
            $query = $query->where('status', $status);
        }

        if (!empty($sort_field) && !empty($sort_type)) {
            $query = $query->orderBy($sort_field, $sort_type);
        } else {
            $query = $query->orderBy('created_at','desc');
        }

        return $this->respondPagination($request, $query->paginate($limit));

    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function userIndex(Request $request)
    {
        $limit = min(intval($request->get('limit', 10)), self::DEFAULT_MAX_LIMIT);

        $query = Promocode::where('public', 1)->where('status', Promocode::STATUS_ACTIVE)->where(function($query) {
            $query->whereNull('valid_from')->orWhere('valid_from', '<=', now());
        })->where(function($query) {
            $query->whereNull('valid_to')->orWhere('valid_to', '>=', now());
        });

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
            'name' => 'required|min:2|max:255',
            'code' => 'required|min:2|unique:promocodes,code|max:255',
            'valid_from' => 'required|date_format:Y-m-d',
            'valid_to' => 'nullable|date_format:Y-m-d',
            'min_spend' => 'numeric',
            'type' => 'required|numeric',
            'status' => 'required|numeric',

            'discount_percentage' => 'required_if:type,==,0|nullable|numeric',
            'discount_amount' => 'required_if:type,==,1|nullable|numeric',

        ]);

        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }

        if ($input['type'] == '0') {
            $validator = Validator::make($input, [
                'discount_percentage' => 'required|numeric|min:1|max:100',
            ]);

            if ($validator->fails()){
                return response()->json($validator->errors(), 422);
            }
            $input['amount'] = $input['discount_percentage'];
        } else {
            $validator = Validator::make($input, [
                'discount_amount' => 'required|numeric|min:1',
            ]);

            if ($validator->fails()){
                return response()->json($validator->errors(), 422);
            }
            $input['amount'] = $input['discount_amount'];
        }

        $promocode = Promocode::create($input);


        if ($request->hasFile('image')) {

            $filename = singleImageFileUpload($request->file('image'), 'images/promocodes',  512);

            $promocode->image= $filename;

            $promocode->save();

        }

        return $this->respondCreated($promocode);

    }

    /**
     * Display the specified resource.
     *
     * @param Promocode $promocode
     * @return \Illuminate\Http\Response
     */
    public function show(Promocode $promocode)
    {
        return $this->respond($promocode);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param Promocode $promocode
     * @return void
     */
    public function update(Request $request, Promocode $promocode)
    {
        $input = $request->input();

        $validator = Validator::make($input, [
            'name' => 'required|min:2|max:255',
            'code' => 'required|min:2|max:255|unique:promocodes,code,' . $promocode->id,
            'valid_from' => 'required|date_format:Y-m-d',
            'valid_to' => 'nullable|date_format:Y-m-d',
            'min_spend' => 'numeric',
            'type' => 'required|numeric',
            'status' => 'required|numeric',

        ]);


        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }
        if ($input['type'] == '0') {
            $validator = Validator::make($input, [
                'discount_percentage' => 'required|numeric|min:1|max:100',
            ]);

            if ($validator->fails()){
                return response()->json($validator->errors(), 422);
            }
            $input['amount'] = $input['discount_percentage'];
        } else {
            $validator = Validator::make($input, [
                'discount_amount' => 'required|numeric|min:1',
            ]);

            if ($validator->fails()){
                return response()->json($validator->errors(), 422);
            }
            $input['amount'] = $input['discount_amount'];
        }


        $promocode->update($input);

        $promocode = $promocode->fresh();

        return $this->respond($promocode);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param Promocode $promocode
     * @return void
     */
    public function updateStatus(Request $request, Promocode $promocode)
    {
        $this->authorize('update', $promocode);
        $promocode->update(['status' => $request->input('status')]);

        $promocode = $promocode->fresh();

        return $this->respond($promocode);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Promocode $promocode
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function destroy(Promocode $promocode)
    {
        $promocode->delete();
        return $this->respond();
    }

}
