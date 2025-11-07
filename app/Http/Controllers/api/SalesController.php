<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Services\SalesService;
use Illuminate\Http\Request;

class SalesController extends Controller
{
    private SalesService $salesRepository;

    public function __construct(SalesService $salesRepository)
    {
        $this->salesRepository = $salesRepository;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        return $this->salesRepository->getSalesData($request);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function managerSales()
    {
        return $this->salesRepository->getManagerSalesData();
    }
}
