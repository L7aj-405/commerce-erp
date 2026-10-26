<?php

namespace App\Http\Controllers\Timeline;

use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Services\Timeline\BusinessTimeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** W5 activity timeline: transport only — sources, permissions and pagination live in BusinessTimeline. Documents open via DocumentOpenController. */
class ActivityTimelineController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context, string $subject, int $id, BusinessTimeline $timeline): JsonResponse
    {
        abort_unless(in_array($subject, BusinessTimeline::SUBJECTS, true), 404);
        $page = $timeline->page($request->user(), $context->organizationOrFail(), $subject, $id, $request->validate(BusinessTimeline::rules()));
        abort_if($page === null, 404);

        return response()->json($page);
    }
}
