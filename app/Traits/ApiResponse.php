<?php

namespace App\Traits;

trait ApiResponse
{


    /**
     * Generate API response
     */

    public function generateResponse($response)
    {
        $validHttpCodes = array_merge(range(100, 599));
        $code = $response->code ?? 200;

        if (!in_array($code, $validHttpCodes)) {
            $code = 500;
        }

        $obj['status'] = $response->status;
        $obj['code'] = $code;
        $obj['message'] = $response->message;
        $obj['data'] = $response->data ?? [];
        $obj['errors'] = $response->errors ?? [];

        return response()->json($obj, $obj['code']);
    }

    public function successResponse($msg, $response)
    {
        $obj['status'] = "success";
        $obj['code'] = 200;
        $obj['message'] = $msg;
        $obj['data'] = $response;

        return response()->json($obj, $obj['code']);
    }

    public function errorResponse($msg, $response, $code)
    {
        $validHttpCodes = array_merge(range(100, 599));
        if (!in_array($code, $validHttpCodes)) {
            $code = 500;
        }

        $obj['status'] = false;
        $obj['code'] = $code;
        $obj['message'] = $msg;
        $obj['data'] = $response;

        return response()->json($obj, $obj['code']);
    }

    /**
     * Generate validation failed response
     */

    public function validationFailed($validator)
    {
        return $this->generateResponse('validation failed', [], 'failed', 422, $validator->messages()->toArray());
    }
}
