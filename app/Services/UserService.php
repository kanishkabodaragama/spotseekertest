<?php

namespace App\Services;

use Illuminate\Http\Request;

interface UserService {
    public function getAllUsers(Request $request);
    public function getUserData(Request $request);
    public function storeUser(Request $request);
    public function updateUser(Request $request, $id);
    public function updateProfile(Request $request);
    public function deleteUser($id);
    public function deleteUserAccount(Request $request);
    public function deactivateUser($id);
    public function activateUser($id);  
    public function getManagerCoordinators();
    public function storeManagerCoordinator(Request $request);
    public function updateManagerCoordinator(Request $request, $id);
    public function deleteManagerCoordinator($id);
    public function deactivateManagerCoordinator($id);
    public function activateManagerCoordinator($id);  
}