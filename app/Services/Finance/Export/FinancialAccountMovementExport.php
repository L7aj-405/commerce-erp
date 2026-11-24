<?php

namespace App\Services\Finance\Export;

use App\Enums\FinancePaymentMethod;
use App\Enums\PaymentMethod;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\User;
use App\Services\Finance\FinancialAccountMovementJournal;
use App\Support\SpreadsheetSafeText;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * CSV / XLSX export of the account movement journal. Rows come from
 * FinancialAccountMovementJournal::stream() — the very same query, filters, order,
 * decoration and running balance as the on-screen journal — written incrementally
 * (never one in-memory workbook). Free text is passed through SpreadsheetSafeText.
 * No idempotency key or internal numeric id is exported.
 */
class FinancialAccountMovementExport
{
    public const TYPE_LABELS = [
        'opening_balance' => 'Solde d’ouverture',
        'customer_payment' => 'Encaissement client',
        'customer_payment_reversal' => 'Annulation encaissement client',
        'customer_refund' => 'Remboursement client',
        'supplier_payment' => 'Paiement fournisseur',
        'supplier_payment_reversal' => 'Annulation paiement fournisseur',
    ];

    private const STATUS_LABELS = ['posted' => 'Comptabilisé', 'reversed' => 'Annulé'];

    private const SCOPE_LABELS = [
        'in_balance' => '',
        'pre_opening' => 'Avant solde d’ouverture — hors solde',
        'after_as_of' => 'Postérieur à la date du jour — hors solde courant',
        'unconfigured' => 'Solde d’ouverture non configuré — hors solde',
    ];

    public function __construct(private readonly FinancialAccountMovementJournal $journal) {}

    /** @return list<string> */
    private function headers(bool $withRunning): array
    {
        return array_values(array_filter([
            'Date', 'Type', 'Référence', 'Référence externe', 'Description', 'Mode de paiement', 'Entrée', 'Sortie',
            $withRunning ? 'Solde courant du journal' : null,
            'Devise', 'Statut', 'Position vs solde', 'Utilisateur', 'Magasin', 'Motif', 'Mouvement lié',
        ], fn ($v) => $v !== null));
    }

    /** @param array<string,mixed> $row @return list<mixed> */
    private function line(array $row, bool $withRunning, bool $numeric): array
    {
        $money = fn (?string $v) => $v === null ? '' : ($numeric ? (float) $v : $v);
        $description = trim(implode(' · ', array_filter([
            $row['counterparty'],
            $row['documents'] === [] ? null : implode(', ', $row['documents']),
            $row['instrument'] ? ucfirst((string) $row['instrument']['type']).' '.$row['instrument']['number'].' ('.$row['instrument']['state'].')' : null,
        ])));
        $linked = $row['linked'] ? (self::TYPE_LABELS[$row['linked']['type']] ?? $row['linked']['type']).' '.($row['reference'] ?? '') : '';

        return array_values(array_filter([
            $row['date'],
            self::TYPE_LABELS[$row['type']] ?? $row['type'],
            $row['reference'] ?? '',
            $row['external_reference'] ?? '',
            $description,
            $this->method($row['method']),
            $money($row['money_in']),
            $money($row['money_out']),
            $withRunning ? ($row['running_balance'] === null ? '' : $money($row['running_balance'])) : null,
            $row['currency_code'],
            self::STATUS_LABELS[$row['status']] ?? $row['status'],
            self::SCOPE_LABELS[$row['balance_scope']] ?? '',
            $row['user'] ?? '',
            $row['store'] ?? '',
            $row['reason'] ?? '',
            $linked,
        ], fn ($v, $i) => ! ($withRunning === false && $i === 8), ARRAY_FILTER_USE_BOTH));
    }

    private function method(?string $method): string
    {
        if ($method === null || $method === '') {
            return '';
        }

        return PaymentMethod::tryFrom($method)?->operationalLabel() ?? FinancePaymentMethod::tryFrom($method)?->label() ?? $method;
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $filters */
    public function csv(Organization $organization, FinancialAccount $account, array $ctx, array $filters, User $user, bool $withRunning): \Closure
    {
        return function () use ($organization, $account, $ctx, $filters, $user, $withRunning): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $this->headers($withRunning), ';');
            foreach ($this->journal->stream($organization, $account, $ctx, $filters, $user, $withRunning) as $row) {
                fputcsv($out, array_map(fn ($v) => SpreadsheetSafeText::escape($v), $this->line($row, $withRunning, false)), ';');
            }
            fclose($out);
        };
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $filters */
    public function xlsx(Organization $organization, FinancialAccount $account, array $ctx, array $filters, User $user, bool $withRunning): string
    {
        $path = tempnam(sys_get_temp_dir(), 'account_movements_');
        if ($path === false) {
            throw new RuntimeException('Impossible de préparer le fichier Excel.');
        }
        $writer = new Writer;
        try {
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName('Mouvements');
            $writer->addRow(Row::fromValuesWithStyles(SpreadsheetSafeText::row($this->headers($withRunning)), (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('243B31')));
            $money = (new Style)->setFormat('#,##0.00');
            $styles = $withRunning ? [6 => $money, 7 => $money, 8 => $money] : [6 => $money, 7 => $money];
            foreach ($this->journal->stream($organization, $account, $ctx, $filters, $user, $withRunning) as $row) {
                $writer->addRow(Row::fromValuesWithStyles(SpreadsheetSafeText::row($this->line($row, $withRunning, true)), null, $styles));
            }
            $writer->close();
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        return $path;
    }
}
