<?php

namespace App\Http\Controllers;

use App\Models\CcdPartyStaging;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CcdPartyStagingController extends Controller
{
    /**
     * Dashboard over ccd_party_staging — filterable by tenant/status/reason/search,
     * with total counts per status and per reason for the current tenant scope.
     */
    public function index(Request $request): Response
    {
        $tenantId = $request->integer('tenant_id') ?: null;
        $status   = $request->string('status')->toString() ?: null;
        $reason   = $request->string('reason')->toString() ?: null;
        $search   = $request->string('search')->toString() ?: null;

        $tenantScoped = fn () => CcdPartyStaging::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId));

        $rows = $tenantScoped()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($reason, fn ($q) => $q->where('reason', $reason))
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('person_national_id', 'like', "%{$search}%")
                    ->orWhere('person_passport_number', 'like', "%{$search}%")
                    ->orWhere('reference_id', $search);
            }))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (CcdPartyStaging $p) => [
                'id'                     => $p->id,
                'tenant_id'              => $p->tenant_id,
                'reference_id'           => $p->reference_id,
                'name'                   => $p->name,
                'person_national_id'     => $p->person_national_id,
                'person_passport_number' => $p->person_passport_number,
                'identification_key'     => $p->identification_key,
                'identification_column'  => $p->identification_column,
                'status'                 => $p->status,
                'reason'                 => $p->reason,
                'canonical_reference_id' => $p->canonical_reference_id,
                'merged_reference_ids'   => $p->merged_reference_ids,
                'created_at'             => $p->created_at?->toIso8601String(),
            ]);

        $statusCounts = $tenantScoped()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $reasonCounts = $tenantScoped()
            ->selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->pluck('total', 'reason');

        $tenants = CcdPartyStaging::query()
            ->select('tenant_id')
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id');

        return Inertia::render('CcdPartyStaging/Index', [
            'rows'          => $rows,
            'total'         => $tenantScoped()->count(),
            'statusCounts'  => $statusCounts,
            'reasonCounts'  => $reasonCounts,
            'tenants'       => $tenants,
            'filters'       => [
                'tenant_id' => $tenantId,
                'status'    => $status,
                'reason'    => $reason,
                'search'    => $search,
            ],
        ]);
    }
}
