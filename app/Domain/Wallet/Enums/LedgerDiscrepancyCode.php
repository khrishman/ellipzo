<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/**
 * Every distinct corruption/drift category wallet:reconcile can detect.
 * A plain string-backed enum, not schema-bound - extending this list
 * later needs no migration.
 */
enum LedgerDiscrepancyCode: string
{
    case TransactionTooFewEntries = 'TRANSACTION_TOO_FEW_ENTRIES';
    case TransactionImbalanced = 'TRANSACTION_IMBALANCED';
    case TransactionInvalidCurrencyOrScale = 'TRANSACTION_INVALID_CURRENCY_OR_SCALE';
    case EntryNonPositiveAmount = 'ENTRY_NON_POSITIVE_AMOUNT';
    case EntryTransactionMissing = 'ENTRY_TRANSACTION_MISSING';
    case EntryAccountMissing = 'ENTRY_ACCOUNT_MISSING';
    case AccountInvalidScopeType = 'ACCOUNT_INVALID_SCOPE_TYPE';
    case AccountNegativeBalanceDisallowed = 'ACCOUNT_NEGATIVE_BALANCE_DISALLOWED';
    case ReversalOriginalMissing = 'REVERSAL_ORIGINAL_MISSING';
    case ReversalTransactionMismatch = 'REVERSAL_TRANSACTION_MISMATCH';
    case ReversalEntryMismatch = 'REVERSAL_ENTRY_MISMATCH';
    case ReversalPendingButLinked = 'REVERSAL_PENDING_BUT_LINKED';
    case ReversalAppliedMissingTransaction = 'REVERSAL_APPLIED_MISSING_TRANSACTION';
    case AdjustmentAuditMissing = 'ADJUSTMENT_AUDIT_MISSING';
    case AdjustmentAuditDuplicate = 'ADJUSTMENT_AUDIT_DUPLICATE';
    case AdjustmentAuditConflict = 'ADJUSTMENT_AUDIT_CONFLICT';
    case AuditTransactionMissing = 'AUDIT_TRANSACTION_MISSING';
    case SnapshotCutoffMissing = 'SNAPSHOT_CUTOFF_MISSING';
    case SnapshotCutoffWrongAccount = 'SNAPSHOT_CUTOFF_WRONG_ACCOUNT';
    case SnapshotCutoffTimestampMismatch = 'SNAPSHOT_CUTOFF_TIMESTAMP_MISMATCH';
    case SnapshotBalanceDrift = 'SNAPSHOT_BALANCE_DRIFT';
    case SnapshotEntryCountDrift = 'SNAPSHOT_ENTRY_COUNT_DRIFT';
    case SnapshotFingerprintDrift = 'SNAPSHOT_FINGERPRINT_DRIFT';
    case BalanceDerivationOverflow = 'BALANCE_DERIVATION_OVERFLOW';
}
