<?php

namespace App\Modules\Audit\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Requests\ListAuditLogsRequest;
use App\Modules\Audit\Resources\AuditLogResource;
use App\Modules\Audit\Services\AuditLogReadService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminAuditLogController extends Controller
{
    public function index(ListAuditLogsRequest $request, AuditLogReadService $logs): AnonymousResourceCollection
    {
        return AuditLogResource::collection($logs->paginate($request->validated()));
    }
}
