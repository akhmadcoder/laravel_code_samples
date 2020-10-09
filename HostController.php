<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\Host;
use App\Notifications\HostApplicationAccepted;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Mockery\Exception;


class HostController extends ApiController
{

    /**
     * HosterController constructor.
     */
    public function __construct()
    {
        // This will automatically add the "can" middleware to each function
         $this->authorizeResource(Host::class, 'host');
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

        $lat = $request->input('latitude');
        $lng = $request->input('longitude');

        $query = Host::with('user');

        if (!empty($search)) {
            $query = $query->with(["user" => function ($q) use ($search) {
                $q->where('users.name', 'LIKE', '%' . $search . '%')
                    ->orWhere('users.phone_number', 'LIKE', '%' . $search . '%')
                    ->orWhere('users.address_1', 'LIKE', '%' . $search . '%')
                    ->orWhere('users.address_2', 'LIKE', '%' . $search . '%')
                    ->orWhere('users.city', 'LIKE', '%' . $search . '%')
                    ->orWhere('users.postal_code', 'LIKE', '%' . $search . '%');
            }]);
        } else {
            $query = $query->with('user');
        }

        if ($request->input('orders') && auth()->user() && auth()->user()->isA(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN)) {
            $query = $query->whereHas('orders')->with('orders.items');
        }

        $monday = $request->input('monday');
        $tuesday = $request->input('tuesday');
        $wednesday = $request->input('wednesday');
        $thursday = $request->input('thursday');
        $friday = $request->input('friday');
        $saturday = $request->input('saturday');
        $sunday = $request->input('sunday');

        if (!empty($monday) || !empty($tuesday) || !empty($wednesday) || !empty($thursday) || !empty($friday) || !empty($saturday) || !empty($sunday)) {
            $query->where(function ($q) use ($monday, $tuesday, $wednesday, $thursday, $friday, $saturday, $sunday) {
                if (!empty($monday)) {
                    $q->orWhereJsonContains('monday_slots', $monday);
                }
                if (!empty($tuesday)) {
                    $q->orWhereJsonContains('tuesday_slots', $tuesday);
                }
                if (!empty($wednesday)) {
                    $q->orWhereJsonContains('wednesday_slots', $wednesday);
                }
                if (!empty($thursday)) {
                    $q->orWhereJsonContains('thursday_slots', $thursday);
                }
                if (!empty($friday)) {
                    $q->orWhereJsonContains('friday_slots', $friday);
                }
                if (!empty($saturday)) {
                    $q->orWhereJsonContains('saturday_slots', $saturday);
                }
                if (!empty($sunday)) {
                    $q->orWhereJsonContains('sunday_slots', $sunday);
                }
            });
        }

        if (!empty($sort_field) && !empty($sort_type)) {

            if (Schema::hasColumn($query->getModel()->getTable(), $sort_field)) {
                $query = $query->orderBy($sort_field, $sort_type);
            } else {

                $query->with(['user' => function ($q) use ($sort_type, $sort_field) {
                    $sortColumn = substr($sort_field, strpos($sort_field, ".") + 1);
                    $q->orderBy('users.' . $sortColumn, $sort_type);
                }]);
            }
        } else {
            $query = $query->orderBy('created_at', 'asc');
        }

        if (!empty($lat) && !empty($lng)) {
            $query->distance($lat, $lng, 1);
        }

        // status
        if ($request->input('status')) {
            $query->where('status', $request->input('status'));
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

        $input = $request->input();

        $validator = Validator::make($input, [
            'name' => 'required|min:3',
            'phone_number' => 'required|max:16|min:8|unique:users,phone_number',
            'email' => 'required|unique:users,email',
            'date_of_birth' => 'nullable|date_format:Y-m-d',
            'monday_slots' => 'nullable|array',
            'monday_slots.*' => 'min:0|max:11',
            'tuesday_slots' => 'nullable|array',
            'tuesday_slots.*' => 'min:0|max:11',
            'wednesday_slots' => 'nullable|array',
            'wednesday_slots.*' => 'min:0|max:11',
            'thursday_slots' => 'nullable|array',
            'thursday_slots.*' => 'min:0|max:11',
            'friday_slots' => 'nullable|array',
            'friday_slots.*' => 'min:0|max:11',
            'saturday_slots' => 'nullable|array',
            'saturday_slots.*' => 'min:0|max:11',
            'sunday_slots' => 'nullable|array',
            'sunday_slots.*' => 'min:0|max:11',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {

            DB::transaction(function () use ($request, $input) {

                if (empty($input['password'])) {
                    $input['password'] = Str::random(16);
                }
                $input['password'] = Hash::make($input['password']);

                $user = User::create($input);

                /** @var Host $host */
                $host = Host::create(['user_id' => $user->id] + $input);

                if ($request->hasFile('profile_picture')) {

                    $folder = public_path('storage/images/users');

                    if (!File::isDirectory($folder)) {
                        File::makeDirectory($folder, 0777, true, true);
                    }

                    $filename = singleImageFileUpload($request->file('profile_picture'), $folder,  512);

                    $user->profile_picture = $filename;

                    $user->save();
                }
                $user->assign(User::ROLE_HOST);

                //                $host = $host->fresh(['user']);

                return $this->respondCreated($host->toArray());
            });
        } catch (Exception $exception) {
            return $this->respondInternalError($message = 'Internal Server Error!', $errors = $exception);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param Host $host
     * @return \Illuminate\Http\Response
     */
    public function show(Host $host)
    {
        $host->load(['user']);
        return $this->respond($host);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param Host $host
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, Host $host)
    {
        $input = $request->input();

        $validator = Validator::make($input, [
            'phone_number' => 'max:16|min:8|unique:users,phone_number,' . $host->user_id,
            'email' => 'unique:users,email,' . $host->user_id,
            'date_of_birth' => 'nullable|date_format:Y-m-d',
            'monday_slots' => 'nullable|array',
            'monday_slots.*' => 'min:0|max:11',
            'tuesday_slots' => 'nullable|array',
            'tuesday_slots.*' => 'min:0|max:11',
            'wednesday_slots' => 'nullable|array',
            'wednesday_slots.*' => 'min:0|max:11',
            'thursday_slots' => 'nullable|array',
            'thursday_slots.*' => 'min:0|max:11',
            'friday_slots' => 'nullable|array',
            'friday_slots.*' => 'min:0|max:11',
            'saturday_slots' => 'nullable|array',
            'saturday_slots.*' => 'min:0|max:11',
            'sunday_slots' => 'nullable|array',
            'sunday_slots.*' => 'min:0|max:11',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if (!empty($input['password'])) {
            $input['password'] = Hash::make($input['password']);
        }

        $host->user->update($input);
        if (!empty($input['status']) && (auth()->user()->isA(User::ROLE_ADMIN) || auth()->user()->isA(User::ROLE_SUPER_ADMIN))) {
            if ($host->status != Host::STATUS_ACTIVE && $input['status'] == Host::STATUS_ACTIVE) {
                try {
                    $host->user->notify(new HostApplicationAccepted($host->user));
                } catch (\Exception $e) {
                    Log::error($e);
                }
            }
            $host->status = $input['status'];
            $host->save();
        }
        $host->update($input);

        if ($request->hasFile('profile_picture')) {

            $folder = public_path('storage/images/users');

            if (!File::isDirectory($folder)) {
                File::makeDirectory($folder, 0777, true, true);
            }

            $filename = singleImageFileUpload($request->file('profile_picture'), $folder,  512);

            $host->user->profile_picture = $filename;
            $host->user->save();
        }

        $host = $host->fresh(['user']);

        return $this->respond($host);
    }

    /**
     * Update timeslots.
     *
     * @param \Illuminate\Http\Request $request
     * @param Host $host
     * @return void
     */
    public function updateTimeslots(Request $request, Host $host)
    {

        $this->authorize('update', $host);
        $input = $request->input();

        $validator = Validator::make($input, [
            'monday_slots' => 'nullable|array',
            'monday_slots.*' => 'min:0|max:11',
            'tuesday_slots' => 'nullable|array',
            'tuesday_slots.*' => 'min:0|max:11',
            'wednesday_slots' => 'nullable|array',
            'wednesday_slots.*' => 'min:0|max:11',
            'thursday_slots' => 'nullable|array',
            'thursday_slots.*' => 'min:0|max:11',
            'friday_slots' => 'nullable|array',
            'friday_slots.*' => 'min:0|max:11',
            'saturday_slots' => 'nullable|array',
            'saturday_slots.*' => 'min:0|max:11',
            'sunday_slots' => 'nullable|array',
            'sunday_slots.*' => 'min:0|max:11',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $host->update($input);

        return $this->respond($host);
    }

    /**
     * Update PAYNOW account.
     *
     * @param \Illuminate\Http\Request $request
     * @param Host $host
     * @return void
     */
    public function updatePaynow(Request $request, Host $host)
    {
        $this->authorize('update', $host);
        $input = $request->input();

        $validator = Validator::make($input, [
            'paynow_account' => 'required|max:16|min:8|unique:hosts,paynow_account',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $host->update($input);

        return $this->respond($host);
    }

     /**
     * Register a hoster.
     *
     * @param \Illuminate\Http\Request $request
     * @param Host $host
     * @return void
     */
    public function register(Request $request)
    {
        if (!auth()->check()) {
            return $this->respondBadRequestError('You are not logged in.');
        }
        $user = auth()->user();

        if (!empty($user->host)) {
            return $this->respondBadRequestError('You already have a host application!');
        }
        $input = $request->except(['password']);

        $inputRules = [
            'name' => 'required|min:3',
            'phone_number' => 'required|max:16|min:8|unique:users,phone_number,' . $user->id,
            'email' => 'required|unique:users,email,' . $user->id,
        ];

        $validator = Validator::make($input, $inputRules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            DB::transaction(function () use ($request, $input, $user) {

                $user->update($input);

                /** @var Host $host */
                $host = Host::create(['user_id' => $user->id] + $input);

                $user->assign(User::ROLE_HOST);

                return $this->respondCreated($host->toArray());
            });
        } catch (Exception $exception) {
            return $this->respondInternalError($message = 'Internal Server Error!', $errors = $exception);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Host $host
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function destroy(Host $host)
    {
        $host->delete();
        return $this->respond();
    }


    public function orderProducts(Request $request, Host $host)
    {
        $this->authorize('update', $host);

        $host->load(['user']);

        $products = [];
        $total = 0;

        if ($request->input('orders') && auth()->user() && auth()->user()->isA(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN)) {

            $host->load('orders.items.product.supplier');

            foreach ($host->orders as $order){
                foreach ($order->items as $index=>$item){
                    $products[] = $item;
                    $total = $total + (float) $item->total_price;
                }
            }
        }

        return $this->respondPagination($request, $this->paginate($request, $products), ['total'=> $total]);
    }
    
}
