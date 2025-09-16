<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\AiSupportService;
use Illuminate\Http\Request;

class AiController extends Controller
{
        protected $aiSupportService;

    public function __construct(AiSupportService $aiSupportService)
    {
        $this->aiSupportService = $aiSupportService;
    }

    public function ask(Request $request)
    {
        $request->validate([
            'content' => 'required|string',
        ]);

        $response = $this->aiSupportService->askGemini($request->input('content'));

        return response()->json([
            'status' => "success",
            'answer' => $response,
        ]);
    }
}
