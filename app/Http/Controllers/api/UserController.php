<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\UnauthorizedException;

class UserController extends Controller
{
    use ApiResponse;

    private UserService $userRepository;

    public function __construct(UserService $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function index(Request $request) {
        $response = $this->userRepository->getAllUsers($request);
        return $this->generateResponse($response);
    }

    public function store(Request $request) {
        $response = $this->userRepository->storeUser($request);
        return $this->generateResponse($response);
    }

    public function getOrders(Request $request) {
        $response = $this->userRepository->getUserData($request);
        return $this->generateResponse($response);
    }
    
    public function getUserData(Request $request) {
        $response = $this->userRepository->getUserData($request);
        return $this->generateResponse($response);
    }

    public function verifyAdminUser(Request $request) {
        $user_id = Auth::user()->id;
        $user = User::findOrFail($user_id);
        if (Hash::check($request->input('password'), $user->password))
        {
            if(Auth::user()->hasRole('Admin')) {
                return $this->successResponse("password verified", null);
            } else {
                throw new UnauthorizedException("You are not authorized to perform this action");
            }
        } else {
            throw new UnauthorizedException("You are not authorized to perform this action");
        }
    }

    public function update(Request $request, $id) {
        $response = $this->userRepository->updateUser($request, $id);
        return $this->generateResponse($response);
    }

    public function updateProfile(Request $request) {
        $response = $this->userRepository->updateProfile($request);
        return $this->generateResponse($response);
    }
    
    public function destroy($id) {
        $response = $this->userRepository->deleteUser($id);
        return $this->generateResponse($response);
    }

    public function destroyUserAccount(Request $request) {
        $response = $this->userRepository->deleteUserAccount($request);
        return $this->generateResponse($response);
    }

    public function banUser($id) {
        $response = $this->userRepository->deactivateUser($id);
        return $this->generateResponse($response);
    }

    public function activateUser($id) {
        $response = $this->userRepository->activateUser($id);
        return $this->generateResponse($response);
    }

    public function getManagerCoordinators() {
        $response = $this->userRepository->getManagerCoordinators();
        return $this->generateResponse($response);
    }

    public function createManagerCoordinator(Request $request) {
        $response = $this->userRepository->storeManagerCoordinator($request);
        return $this->generateResponse($response);
    }

    public function deleteManagerCoordinator($id) {
        $response = $this->userRepository->deleteManagerCoordinator($id);
        return $this->generateResponse($response);
    }

    public function deactivateManagerCoordinator($id) {
        $response = $this->userRepository->deactivateManagerCoordinator($id);
        return $this->generateResponse($response);
    }
    
    public function activateManagerCoordinator($id) {
        $response = $this->userRepository->activateManagerCoordinator($id);
        return $this->generateResponse($response);
    }

    public function updateManagerCoordinator(Request $request, $id) {
        $response = $this->userRepository->updateManagerCoordinator($request, $id);
        return $this->generateResponse($response);
    }
}
