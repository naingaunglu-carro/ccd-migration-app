<?php

namespace App\Http\Controllers;

use App\Models\StgOneParty;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CcdPartyStagingController extends Controller
{
    /**
     * Dashboard over stg_one_parties — filterable by country/status/reason/search,
     * with total counts per status and per reason for the current country scope.
     */
    public function index(Request $request): Response
    {
        $countryId = $request->integer('country_id') ?: null;
        $status    = $request->string('status')->toString() ?: null;
        $reason    = $request->string('reason')->toString() ?: null;
        $search    = $request->string('search')->toString() ?: null;

        $countryScoped = fn () => StgOneParty::query()
            ->when($countryId, fn ($q) => $q->where('country_id', $countryId));

        $rows = $countryScoped()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($reason, fn ($q) => $q->where('reason', $reason))
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('original_name', 'like', "%{$search}%")
                    ->orWhere('ocr_name', 'like', "%{$search}%")
                    ->orWhere('original_national_id', 'like', "%{$search}%")
                    ->orWhere('ocr_person_national_id', 'like', "%{$search}%")
                    ->orWhere('ocr_person_passport_number', 'like', "%{$search}%")
                    ->orWhere('reference_id', $search);
            }))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (StgOneParty $p) => [
                'id'                             => $p->id,
                'country_id'                     => $p->country_id,
                'reference_id'                   => $p->reference_id,
                'original_name'                  => $p->original_name,
                'ocr_name'                       => $p->ocr_name,
                'original_national_id'           => $p->original_national_id,
                'ocr_person_national_id'         => $p->ocr_person_national_id,
                'ocr_person_passport_number'     => $p->ocr_person_passport_number,
                'identification_key'             => $p->identification_key,
                'identification_column'          => $p->identification_column,
                'status'                         => $p->status,
                'reason'                         => $p->reason,
                'confidence_score'               => $p->confidence_score,
                'is_verified'                    => $p->is_verified,
                'confidence_score_for_original'  => $p->confidence_score_for_original,
                'is_original_verified'           => $p->is_original_verified,
                'transaction_ids'                => $p->transaction_ids,
                'created_at'                     => $p->created_at?->toIso8601String(),
            ]);

        $statusCounts = $countryScoped()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $reasonCounts = $countryScoped()
            ->selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->pluck('total', 'reason');

        $countries = StgOneParty::query()
            ->select('country_id')
            ->distinct()
            ->orderBy('country_id')
            ->pluck('country_id');

        return Inertia::render('CcdPartyStaging/Index', [
            'rows'          => $rows,
            'total'         => $countryScoped()->count(),
            'statusCounts'  => $statusCounts,
            'reasonCounts'  => $reasonCounts,
            'countries'     => $countries,
            'filters'       => [
                'country_id' => $countryId,
                'status'     => $status,
                'reason'     => $reason,
                'search'     => $search,
            ],
        ]);
    }
}
