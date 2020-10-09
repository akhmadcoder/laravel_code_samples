<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Mockery\Exception;


class SupplierController extends ApiController
{

    /**
     * SupplierController constructor.
     */
    public function __construct()
    {
        // This will automatically add the "can" middleware to each function
        $this->authorizeResource(Supplier::class, 'supplier');
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
        $query = Supplier::query();

        if (!empty($search)) {
            $query = $query->where(function($q) use ($search) {
                $q->where('name', 'LIKE', '%' . $search . '%')
                ->orWhere('cp_first_name', 'LIKE', '%' . $search . '%')
                ->orWhere('cp_last_name', 'LIKE', '%' . $search . '%')
                ->orWhere('phone', 'LIKE', '%' . $search . '%')
                ->orWhere('mobile', 'LIKE', '%' . $search . '%')
                ->orWhere('email', 'LIKE', '%' . $search . '%')
                ->orWhere('website', 'LIKE', '%' . $search . '%');
            });
        }

        $query = $query->orderBy('id', 'asc');

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
        $input = $request->input();

        $validator = Validator::make($input, [
            'name' => 'required|min:2',
            'country' => 'required',
        ]);

        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }

        try {

            DB::transaction(function() use ($request, $input) {

                /** @var Supplier $supplier */
                $supplier = Supplier::create($input);

                if ($request->hasFile('image')) {

                    $filename = singleImageFileUpload($request->file('image'), 'images/suppliers',  512);

                    $supplier->image = $filename;

                    $supplier->save();

                }

                $supplier = $supplier->fresh();

                return $this->respondCreated($supplier->toArray());

            });


        } catch (Exception $exception){
            return $this->respondInternalError($message = 'Internal Server Error!', $errors = $exception);
        }

    }

    /**
     * Display the specified resource.
     *
     * @param Supplier $supplier
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Supplier $supplier)
    {
        return $this->respond($supplier->toArray());
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param Supplier $supplier
     * @return void
     */
    public function update(Request $request, Supplier $supplier)
    {
        $input = $request->input();

        $validator = Validator::make($input, [
            'name' => 'required|min:2',
            'country' => 'required',
        ]);

        if ($validator->fails()){
            return response()->json($validator->errors(), 422);
        }

        $supplier->update($input);

        if ($request->hasFile('image')) {

            $filename = singleImageFileUpload($request->file('image'), 'images/suppliers',  512);

            $supplier->image = $filename;
            $supplier->save();

        }

        $supplier = $supplier->fresh();

        return $this->respond($supplier);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Supplier $supplier
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function destroy(Supplier $supplier)
    {
        $supplier->delete();
        return $this->respond();
    }

}
