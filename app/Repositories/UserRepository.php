<?php

namespace App\Repositories;

use App\Enums\Roles;
use App\Models\Event;
use App\Models\EventCoordinator;
use App\Models\TicketSale;
use App\Models\User;
use App\Services\UserService;
use App\Traits\ApiResponse;
use Exception;
use Fouladgar\MobileVerification\Tokens\TokenBroker;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class UserRepository implements UserService
{

    public function getAllUsers(Request $request)
    {
        $users = null;
        $perPage = $request->input('per_page', 15);
        $page = $request->input('page', 0);
        $search = $request->input('search', '');
        $sortBy = $request->input('sortBy', '');

        try {

            $query = User::with('roles');

            // Apply search filter
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%')
                        ->orWhere('name', 'like', '%' . $search . '%')
                        ->orWhere('nic', 'like', '%' . $search . '%')
                        ->orWhere('phone_no', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            }

            switch ($request->type) {
                case "manager":
                    $query->whereRelation('roles', 'name', '=', 'Manager');
                    break;
                case "staff":
                    $query->whereRelation('roles', 'name', '=', 'Admin');
                    break;
                default:
                    $query->whereRelation('roles', 'name', '=', 'User');
                    break;
            }


            if(!empty($sortBy)) {
                $query->orderBy($sortBy, 'desc');
            }

            if ($request->input('all') == 'true') {
                $users = $query->get();
            } else {
                $users = $query->paginate($perPage, ['*'], 'page', $page);
            }


            return (object) [
                "message" => 'users retrieved successfully',
                "status" => true,
                "data" => $users
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function getUserData(Request $request)
    {
        try {
            // Fetch user with related orders, events, venue, and ticket packages
            $userWithOrders = User::with([
                'orders' => function ($query) {
                    $query->orderBy('created_at', 'desc');
                },
                'orders.packages.package',
                'orders.event',
                'orders.event.venue',
                'orders.event.ticket_packages',
                'orders.addons.addon'
            ])
                ->where('id', $request->user()->id)
                ->withSum('orders', 'tot_amount')
                ->withCount('orders')
                ->first();

            // Handle case where user has no orders
            if (!$userWithOrders) {
                return (object)[
                    "message" => "user not found in our system",
                    "status" => false,
                    "code" => 404,
                ];
            }

            // Prepare user data
            $user = (object) [
                'firstName' => $userWithOrders->first_name,
                'lastName' => $userWithOrders->last_name,
                'email' => $userWithOrders->email,
                'joinedAt' => $userWithOrders->created_at,
                'updatedAt' => $userWithOrders->updated_at,
                'phoneNo' => $userWithOrders->phone_no,
                'nic' => $userWithOrders->nic,
                'verified' => $userWithOrders->hasVerifiedMobile()
            ];

            $totalAmount = 0;
            $ordersData = [];

            foreach ($userWithOrders->orders as $order) {
                $orderTotal = $order->tot_amount ?? 0;
                $totalAmount += $orderTotal;
                $rewards = floor($orderTotal * 0.01);

                // Prepare packages data
                $packagesData = [];
                foreach ($order->packages as $package) {
                    $packagesData[] = [
                        'package_name' => $package->package->name,
                        'ticket_count' => $package->ticket_count
                    ];
                }

                $ordersData[] = [
                    'tot_amount' => $orderTotal,
                    'rewards' => $rewards,
                    'event' => $order->event->serialize(),
                    'booking_status' => $order->payment_status,
                    'booking_date' => $order->transaction_date_time,
                    'order_id' => $order->order_id,
                    'tot_ticket_count' => $order->tot_ticket_count,
                    'e_ticket_url' => $order->e_ticket_url,
                    'packages' => $packagesData,
                    'addons' => $order->addons
                ];
            }

            $totalRewards = floor($totalAmount * 0.01);
            $attendedEvents = $userWithOrders->orders
                ->filter(function ($order) {
                    return in_array($order->payment_status, ['verified', 'partially_verified']);
                })
                ->pluck('event')
                ->unique()
                ->count();

            return (object)[
                "message" => 'User orders retrieved successfully',
                "status" => true,
                "data" => [
                    'user' => $user,
                    'orders' => $ordersData,
                    'totalAmount' => $totalAmount,
                    'rewards' => $totalRewards,
                    'attendedEvents' => $attendedEvents,
                ],
            ];
        } catch (Exception $e) {
            Log::error($e->getMessage());

            return response()->json([
                "message" => 'Something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ], 500);
        }
    }

    public function storeUser(Request $request)
    {
        try {
            Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'role' => ['required', 'string', 'exists:roles,name'],
                'password' => ['required', 'string', 'min:8', 'confirmed']
            ])->validate();

            $user_names = explode(" ", $request->input('name'));

            $first_name = $user_names[0];
            $last_name = sizeof($user_names) > 1 ? $user_names[1] : '';
            $newUser = User::create([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'first_name' => $first_name,
                'last_name' => $last_name,
                'password' => Hash::make($request->input('password')),
            ]);

            $role = Role::where(['name' => $request->input('role')])->first();

            $newUser->syncRoles([$role->id]);

            return (object) [
                "message" => 'users created successfully',
                "status" => true,
                "data" => $newUser
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function updateUser(Request $request, $id)
    {
        try {

            Validator::make($request->all(), [
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'role' => ['string', 'exists:roles,name']
            ])->validate();

            if ($request->input('password')) {
                Validator::make($request->all(), [
                    'password' => ['required', 'string', 'min:8', 'confirmed']
                ])->validate();
            }

            $user = User::findOrFail($id);

            $user->first_name = $request->input('first_name');
            $user->last_name = $request->input('last_name');
            $user->name = $request->input('first_name') . " " . $request->input('last_name');
            $user->email = $request->input('email');
            $user->phone_no = $request->input('phone');
            $user->nic = $request->input('nic');
            if ($request->input('password')) {
                $user->password = Hash::make($request->input('password'));
            }

            $user->save();

            if ($request->input('role')) {
                $role = Role::where(['name' => $request->input('role')])->first();
                $user->syncRoles([$role->id]);
            }

            return (object) [
                "message" => 'users updated successfully',
                "status" => true,
                "data" => $user
            ];
        } catch (QueryException $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => 'Email or Phone no already exists',
                "code" => 500,
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function deleteUser($id)
    {

        Log::info('User delete requested by ' . Auth::user()->id . ' for User Id:' . $id);

        try {

            $user = User::withCount('events', 'orders')->findOrFail($id);

            if ($user->hasRole('Manager')) {
                if ($user->events_count > 0) {
                    Log::info('User has manager role and has related events: ' . $user->events_count);
                    return (object) [
                        "message" => 'user cannot delete, related event exist',
                        "status" => false,
                        "code" => 400
                    ];
                }
            } else if ($user->hasRole('User')) {
                if ($user->orders_count > 0) {
                    $orders = TicketSale::with('event')->where('user_id', $user->id);
                    foreach ($orders as $order) {
                        if ($order->status == 'complete') {
                            Log::info('User has user role and has related completed orders: ' . $order->order_id);
                            return (object) [
                                "message" => 'user cannot delete, related completed orders exist: ' . $order->order_id,
                                "status" => false,
                                "code" => 400
                            ];
                        }
                    }
                }
            }

            $user->delete();
            Log::info('Use removed successfully. User Id: ' . $id . ' . Requested by ' . Auth::user()->id);

            return (object) [
                "message" => 'users removed successfully',
                "status" => true,
                "data" => $user
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function deleteUserAccount(Request $request)
    {

        Log::info('User delete requested by ' . $request->user()->id);

        try {

            $user = User::withCount('events', 'orders')->findOrFail($request->user()->id);

            if ($user->hasRole('Manager')) {
                if ($user->events_count > 0) {
                    Log::info('User has manager role and has related events: ' . $user->events_count);
                    return (object) [
                        "message" => 'user cannot delete, related events exist',
                        "status" => false,
                        "code" => 400
                    ];
                }
            } else if ($user->hasRole('User')) {
                $user->delete();
            }

            Log::info('Use account deleted successfully. User Id: ' . $request->user()->id);

            return (object) [
                "message" => 'users account deleted successfully',
                "status" => true,
                "data" => $user
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function deactivateUser($id)
    {
        try {

            $user = User::findOrFail($id);

            $user->status = false;

            $user->save();

            return (object) [
                "message" => 'users deactivated successfully',
                "status" => true,
                "data" => $user
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function activateUser($id)
    {
        try {

            $user = User::findOrFail($id);

            $user->status = true;

            $user->save();

            return (object) [
                "message" => 'users activated successfully',
                "status" => true,
                "data" => $user
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function getManagerCoordinators()
    {
        try {
            $manager_id = Auth::user()->id;

            // Get all event coordinators with their respective events
            $coordinators = User::whereHas('coordinated_events', function ($query) use ($manager_id) {
                $query->where('manager_id', $manager_id)
                    ->whereNull('event_coordinators.deleted_at');
            })
                ->with(['coordinated_events' => function ($query) {
                    $query->whereNull('event_coordinators.deleted_at');
                }])
                ->get();

            return (object) [
                "message" => 'users retrieved successfully',
                "status" => true,
                "data" => $coordinators
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function storeManagerCoordinator(Request $request)
    {
        try {
            Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'events' => ['required', 'array']
            ])->validate();

            $eventIds = $request->input('events');

            $events = Event::whereIn('id', $eventIds)->get();

            $user_names = explode(" ", $request->input('name'));

            $first_name = $user_names[0];
            $last_name = sizeof($user_names) > 1 ? $user_names[1] : '';
            $newUser = User::create([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'first_name' => $first_name,
                'last_name' => $last_name,
                'nic' => $request->input('nic'),
                'password' => Hash::make($request->input('password')),
            ]);

            $role = Role::where(['name' => Roles::COORDINATOR->value])->first();

            $newUser->syncRoles([$role->id]);

            foreach ($events as $event) {
                EventCoordinator::create([
                    'user_id' => $newUser->id,
                    'manager_id' => $request->input('manager_id') ?? Auth::user()->id,
                    'event_id' => $event->id
                ]);
            }

            return (object) [
                "message" => 'users created successfully',
                "status" => true,
                "data" => $newUser
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function updateManagerCoordinator(Request $request, $id)
    {
        // Define validation rules
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $id],
            'events' => ['required', 'array']
        ];

        // Validate request data
        $validatedData = $request->validate($rules);

        try {
            // Find the user by ID
            $user = User::findOrFail($id);

            // Split full name into first and last names
            $userNames = explode(" ", $validatedData['name']);
            $firstName = $userNames[0];
            $lastName = isset($userNames[1]) ? $userNames[1] : '';

            // Update user details
            $user->update([
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'first_name' => $firstName,
                'last_name' => $lastName,
                'nic' => $request->input('nic', $user->nic), // Keep existing NIC if not provided
                'password' => $request->has('password') ? Hash::make($validatedData['password']) : $user->password,
            ]);

            // Assign the COORDINATOR role to the user if not already assigned
            $role = Role::where('name', Roles::COORDINATOR->value)->first();
            if (!$user->hasRole($role->name)) {
                $user->syncRoles([$role->id]);
            }

            // Get the event IDs from the request
            $eventIds = $validatedData['events'];

            // Fetch the events based on IDs
            $events = Event::whereIn('id', $eventIds)->get();

            // Update EventCoordinator entries
            // First, remove all existing coordinations
            EventCoordinator::where('user_id', $user->id)->delete();

            // Create new EventCoordinator entries
            $managerId = $request->input('manager_id', Auth::user()->id);
            foreach ($events as $event) {
                EventCoordinator::create([
                    'user_id' => $user->id,
                    'manager_id' => $managerId,
                    'event_id' => $event->id
                ]);
            }

            // Return success response
            return (object) [
                "message" => 'User updated successfully',
                "status" => true,
                "data" => $user
            ];
        } catch (Exception $e) {
            // Return error response
            return (object) [
                "message" => 'Something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function deleteManagerCoordinator($id)
    {

        Log::info('User delete requested by ' . Auth::user()->id . ' for User Id:' . $id);

        $coordinator = EventCoordinator::where([
            ['user_id', $id],
            ['manager_id', Auth::id()]
        ])->first();

        if ($coordinator == null) {
            return (object) [
                "message" => 'Cannot find the requested coordinator',
                "status" => false,
                "code" => 400
            ];
        }

        try {

            $user = User::findOrFail($id);
            $coordinator->delete();

            $user->delete();
            Log::info('Use removed successfully. User Id: ' . $id . ' . Requested by ' . Auth::user()->id);

            return (object) [
                "message" => 'users removed successfully',
                "status" => true,
                "data" => $user
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function deactivateManagerCoordinator($id)
    {

        $coordinator = EventCoordinator::where([
            ['user_id', $id],
            ['manager_id', Auth::id()]
        ])->first();

        if ($coordinator == null) {
            return (object) [
                "message" => 'Cannot find the requested coordinator',
                "status" => false,
                "code" => 400
            ];
        }

        try {

            $user = User::findOrFail($id);

            $user->status = false;

            $user->save();

            return (object) [
                "message" => 'users deactivated successfully',
                "status" => true,
                "data" => $user
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function activateManagerCoordinator($id)
    {

        $coordinator = EventCoordinator::where([
            ['user_id', $id],
            ['manager_id', Auth::id()]
        ])->first();

        if ($coordinator == null) {
            return (object) [
                "message" => 'Cannot find the requested coordinator',
                "status" => false,
                "code" => 400
            ];
        }

        try {

            $user = User::findOrFail($id);

            $user->status = true;

            $user->save();

            return (object) [
                "message" => 'users activated successfully',
                "status" => true,
                "data" => $user
            ];
        } catch (Exception $e) {
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }

    public function updateProfile(Request $request)
    {
        DB::beginTransaction();
        try {
            Validator::make($request->all(), [
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $request->user()->id],
                'phone_no' => ['required', 'string', 'unique:users,phone_no,' . $request->user()->id],
                'nic' => ['required', 'string']
            ])->validate();

            if ($request->input('password')) {
                Validator::make($request->all(), [
                    'password' => ['required', 'string', 'min:8', 'confirmed']
                ])->validate();
            }

            $user = User::findOrFail($request->user()->id);

            $wasPhoneChanged = $user->phone_no !== $request->input('phone_no');

            $user->first_name = $request->input('first_name');
            $user->last_name = $request->input('last_name');
            $user->name = $request->input('first_name') . " " . $request->input('last_name');
            $user->email = $request->input('email');
            $user->phone_no = $request->input('phone_no');
            $user->nic = $request->input('nic');

            if ($request->input('password')) {
                $user->password = Hash::make($request->input('password'));
            }

            if ($wasPhoneChanged) {
                $user->mobile_verified_at = null;
                $broker = resolve(TokenBroker::class);
                $broker->sendToken($user);
                DB::commit(); 
            }

            $user->save();  
            DB::commit();

            $user->verified = $user->hasVerifiedMobile();
            
            return (object) [
                "message" => 'user updated successfully',
                "status" => true,
                "data" => $user
            ];
        } catch (QueryException $e) {
            DB::rollBack();
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => 'Email or Phone no already exists',
                "code" => 500,
            ];
        } catch (Exception $e) {
            DB::rollBack();
            return (object) [
                "message" => 'something went wrong',
                "status" => false,
                "errors" => $e->getMessage(),
                "code" => 500,
            ];
        }
    }
}
