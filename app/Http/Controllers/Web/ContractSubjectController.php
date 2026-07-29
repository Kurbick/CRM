<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Order;
use App\Models\Subscription;
use App\Support\Navigation\AuthorizedLandingPage;
use Illuminate\Support\Facades\Gate;

class ContractSubjectController extends Controller
{
    public function create(Contract $contract)
    {
        $canCreateOrder = Gate::allows('create', [Order::class, $contract]);
        $canCreateSubscription = Gate::allows('create', [Subscription::class, $contract]);

        abort_unless($canCreateOrder || $canCreateSubscription, 403);

        $contract->loadMissing('company:id,name');
        $backUrl = $this->backUrl($contract);

        return view('contract-subjects.create', compact(
            'contract',
            'canCreateOrder',
            'canCreateSubscription',
            'backUrl'
        ));
    }

    private function backUrl(Contract $contract): string
    {
        if (Gate::allows('view', $contract)) {
            return route('contracts.show', $contract);
        }

        if (Gate::allows('view', $contract->company)) {
            return route('companies.show', $contract->company);
        }

        return app(AuthorizedLandingPage::class)->url(auth()->user());
    }
}
