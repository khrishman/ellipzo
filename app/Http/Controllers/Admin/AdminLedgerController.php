<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Services\AdminLedgerPresenter;
use App\Domain\Wallet\Services\AdminLedgerQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminLedgerFilterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Inertia\Inertia;
use Inertia\Response;
use UnexpectedValueException;

/**
 * The read-only staff ledger explorer (Task 2.8). Every ledger.view
 * holder may browse every transaction - there is no per-staff ownership
 * scoping, only the explicit allowlisted filters below. No route here
 * accepts anything but GET; no method in this class or in
 * AdminLedgerQuery/AdminLedgerPresenter ever calls LedgerPostingEngine,
 * AdministrativeAdjustmentService, ReversalRequestService,
 * WalletAccountProvisioner, or BalanceSnapshotService.
 */
final class AdminLedgerController extends Controller
{
    public function __construct(
        private readonly AdminLedgerQuery $query,
        private readonly AdminLedgerPresenter $presenter,
    ) {}

    public function index(AdminLedgerFilterRequest $request): Response|RedirectResponse
    {
        $filters = $request->toFilters();

        $cursor = $this->resolveCursor($request->query('cursor'));

        if ($cursor === false) {
            return $this->redirectToCanonicalUrl($request);
        }

        try {
            $page = $this->query->paginate($filters, $cursor);
        } catch (UnexpectedValueException) {
            // A structurally-valid cursor whose parameters don't match
            // this query's own created_at/id ordering - caught narrowly
            // here only, mirroring TransactionHistoryController's own
            // established handling for the identical failure mode.
            return $this->redirectToCanonicalUrl($request);
        }

        $pageIds = $page->getCollection()->pluck('id')->all();
        $reversalLinks = $this->query->reversalLinksFor($pageIds);

        return Inertia::render('admin/ledger/index', [
            'transactions' => $this->presenter->presentList($page, $reversalLinks),
            'filters' => $this->rawFilters($request),
            'typeOptions' => $this->presenter->transactionTypeOptions(),
            'accountTypeOptions' => $this->presenter->accountTypeOptions(),
        ]);
    }

    /**
     * $ledgerTransaction is already resolved and already authorized by the
     * time this method runs: PermissionMiddleware (permission:ledger.view)
     * sits before SubstituteBindings in the application's own middleware
     * priority list (verified via reflection during Task 2.8's planning,
     * unchanged since), so an unauthorized request never reaches route-
     * model binding at all and a 403 never reveals whether the requested
     * ID exists. No second `WHERE id = ?` lookup is performed here to
     * re-obtain the same transaction.
     */
    public function show(Request $request, LedgerTransaction $ledgerTransaction): Response
    {
        $this->query->loadDetailRelations($ledgerTransaction);
        $reversalTransactionId = $this->query->reversalOf($ledgerTransaction->id);

        $canViewLedgerAudit = $request->user()->can('ledger.audit.view');

        return Inertia::render('admin/ledger/show', [
            'transaction' => $this->presenter->presentDetail($ledgerTransaction, $reversalTransactionId),
            'entries' => $this->presenter->presentEntries($ledgerTransaction),
            'canViewLedgerAudit' => $canViewLedgerAudit,
            // Only ever queried when the permission is present - the
            // ternary means presentLedgerAudit() (and therefore its own
            // AuditEvent query) is never invoked otherwise.
            'ledgerAudit' => $canViewLedgerAudit ? $this->presenter->presentLedgerAudit($ledgerTransaction) : null,
        ]);
    }

    /**
     * Mirrors TransactionHistoryController::resolveCursor() exactly: null
     * (no cursor supplied), a real Cursor (decoded successfully), or false
     * (a non-empty value was supplied but could not be decoded).
     */
    private function resolveCursor(mixed $raw): Cursor|false|null
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return Cursor::fromEncoded($raw) ?? false;
    }

    private function redirectToCanonicalUrl(AdminLedgerFilterRequest $request): RedirectResponse
    {
        return redirect()->route('admin.ledger.index', $this->rawFilters($request));
    }

    /**
     * The raw, already-validated filter values exactly as submitted -
     * never the parsed AdminLedgerFilters DTO - since both the redirect
     * target's query string and the frontend's echoed-back filter props
     * need the same simple scalar-string shape a form control can
     * directly redisplay.
     *
     * @return array<string, string>
     */
    private function rawFilters(AdminLedgerFilterRequest $request): array
    {
        return array_filter([
            'id' => $request->validated('id'),
            'type' => $request->validated('type'),
            'accountType' => $request->validated('accountType'),
            'userId' => $request->validated('userId'),
            'username' => $request->validated('username'),
            'businessReference' => $request->validated('businessReference'),
            'correlationId' => $request->validated('correlationId'),
            'dateFrom' => $request->validated('dateFrom'),
            'dateTo' => $request->validated('dateTo'),
        ], fn (?string $value): bool => $value !== null);
    }
}
