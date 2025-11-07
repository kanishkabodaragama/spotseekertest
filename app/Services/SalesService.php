<?php

namespace App\Services;

use Illuminate\Http\Request;

interface SalesService {
    public function getSalesData(Request $request);
    public function getManagerSalesData();
}