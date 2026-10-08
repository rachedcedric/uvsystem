<?php

namespace App\Filament\Pages;

use App\Exports\AgentSituationExport;
use App\Exports\RemboursementsExport;
use App\Exports\UvTransactionsExport;
use App\Models\Agent;
use App\Models\UvTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Maatwebsite\Excel\Facades\Excel;

class ReportsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?string $navigationLabel = 'Rapports & Exports';

    protected static ?string $title = 'Rapports & Exports';

    protected static ?string $navigationGroup = 'Gestion du tenant';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.reports-page';

    // Form data
    public ?array $transactionsData = [];

    public ?array $remboursementsData = [];

    public ?array $agentsData = [];

    /** Rapports : consultation (view_reports) ; chaque génération exige en plus export_reports. */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_reports') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->transactionsData = [
            'agent_id' => null,
            'start_date' => now()->startOfMonth()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'transaction_type' => null,
        ];

        $this->remboursementsData = [
            'agent_id' => null,
            'start_date' => now()->startOfMonth()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'payment_method' => null,
        ];

        $this->agentsData = [
            'status' => null,
            'with_debt_only' => false,
        ];
    }

    protected function getForms(): array
    {
        return [
            'transactionsForm',
            'remboursementsForm',
            'agentsForm',
        ];
    }

    public function transactionsForm(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('agent_id')
                    ->label('Agent')
                    ->options(Agent::pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('Tous les agents'),
                DatePicker::make('start_date')
                    ->label('Date début')
                    ->default(now()->startOfMonth()),
                DatePicker::make('end_date')
                    ->label('Date fin')
                    ->default(now()),
                Select::make('transaction_type')
                    ->label('Type')
                    ->options([
                        'distribution' => 'Distribution',
                        'retour' => 'Retour',
                    ])
                    ->placeholder('Tous'),
            ])
            ->columns(4)
            ->statePath('transactionsData');
    }

    public function remboursementsForm(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('agent_id')
                    ->label('Agent')
                    ->options(Agent::pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('Tous les agents'),
                DatePicker::make('start_date')
                    ->label('Date début')
                    ->default(now()->startOfMonth()),
                DatePicker::make('end_date')
                    ->label('Date fin')
                    ->default(now()),
                Select::make('payment_method')
                    ->label('Mode de paiement')
                    ->options([
                        'cash' => 'Espèces',
                        'mobile_money' => 'Mobile Money',
                        'bank_transfer' => 'Virement',
                        'cheque' => 'Chèque',
                    ])
                    ->placeholder('Tous'),
            ])
            ->columns(4)
            ->statePath('remboursementsData');
    }

    public function agentsForm(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('status')
                    ->label('Statut')
                    ->options([
                        'active' => 'Actif',
                        'inactive' => 'Inactif',
                        'suspended' => 'Suspendu',
                    ])
                    ->placeholder('Tous'),
                Toggle::make('with_debt_only')
                    ->label('Uniquement avec dette')
                    ->default(false),
            ])
            ->columns(2)
            ->statePath('agentsData');
    }

    // =============== EXPORT EXCEL ===============

    public function exportTransactionsExcel()
    {
        abort_unless(auth()->user()?->can('export_reports'), 403);
        $filename = 'transactions_'.now()->format('Y-m-d_His').'.xlsx';

        Notification::make()
            ->title('Export Excel généré!')
            ->success()
            ->send();

        return Excel::download(
            new UvTransactionsExport(
                $this->transactionsData['agent_id'] ?? null,
                $this->transactionsData['start_date'] ?? null,
                $this->transactionsData['end_date'] ?? null,
                $this->transactionsData['transaction_type'] ?? null,
            ),
            $filename
        );
    }

    public function exportRemboursementsExcel()
    {
        abort_unless(auth()->user()?->can('export_reports'), 403);
        $filename = 'remboursements_'.now()->format('Y-m-d_His').'.xlsx';

        return Excel::download(
            new RemboursementsExport(
                $this->remboursementsData['agent_id'] ?? null,
                $this->remboursementsData['start_date'] ?? null,
                $this->remboursementsData['end_date'] ?? null,
                $this->remboursementsData['payment_method'] ?? null,
            ),
            $filename
        );
    }

    public function exportAgentsExcel()
    {
        abort_unless(auth()->user()?->can('export_reports'), 403);
        $filename = 'situation_agents_'.now()->format('Y-m-d_His').'.xlsx';

        return Excel::download(
            new AgentSituationExport(
                $this->agentsData['status'] ?? null,
                $this->agentsData['with_debt_only'] ?? false,
            ),
            $filename
        );
    }

    // =============== EXPORT PDF ===============

    public function exportTransactionsPdf()
    {
        abort_unless(auth()->user()?->can('export_reports'), 403);
        $filename = 'transactions_'.now()->format('Y-m-d_His').'.pdf';

        $query = UvTransaction::query()
            ->with(['agent', 'fournisseur']);

        if ($this->transactionsData['agent_id'] ?? null) {
            $query->where('agent_id', $this->transactionsData['agent_id']);
        }

        if ($this->transactionsData['start_date'] ?? null) {
            $query->whereDate('transaction_date', '>=', $this->transactionsData['start_date']);
        }

        if ($this->transactionsData['end_date'] ?? null) {
            $query->whereDate('transaction_date', '<=', $this->transactionsData['end_date']);
        }

        if ($this->transactionsData['transaction_type'] ?? null) {
            $query->where('transaction_type', $this->transactionsData['transaction_type']);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')->get();
        $agent = ($this->transactionsData['agent_id'] ?? null)
            ? Agent::find($this->transactionsData['agent_id'])
            : null;

        $pdf = Pdf::loadView('exports.transactions-pdf', [
            'transactions' => $transactions,
            'agent' => $agent,
            'startDate' => $this->transactionsData['start_date'] ?? null,
            'endDate' => $this->transactionsData['end_date'] ?? null,
        ])->setPaper('a4', 'landscape');

        Notification::make()
            ->title('PDF généré!')
            ->success()
            ->send();

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename
        );
    }

    public function exportAgentsPdf()
    {
        abort_unless(auth()->user()?->can('export_reports'), 403);
        $filename = 'situation_agents_'.now()->format('Y-m-d_His').'.pdf';

        $query = Agent::query();

        if ($this->agentsData['status'] ?? null) {
            $query->where('status', $this->agentsData['status']);
        }

        if ($this->agentsData['with_debt_only'] ?? false) {
            $query->where('current_debt', '>', 0);
        }

        $agents = $query->orderBy('name')->get();

        $pdf = Pdf::loadView('exports.agents-situation-pdf', [
            'agents' => $agents,
        ])->setPaper('a4', 'landscape');

        Notification::make()
            ->title('PDF généré!')
            ->success()
            ->send();

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename
        );
    }
}
