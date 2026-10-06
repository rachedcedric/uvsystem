<?php

namespace App\Livewire\Mobile\Crud;

use App\Support\Mobile\Crud\MobileResource;
use App\Support\Mobile\Crud\Registry;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
class Show extends Component
{
    public string $resource;

    public string $recordId;

    /** @return class-string<MobileResource> */
    private function res(): string
    {
        return Registry::find($this->resource) ?? abort(404);
    }

    private function record(): Model
    {
        return $this->res()::model()::findOrFail($this->recordId);
    }

    public function mount(string $resource, string $id): void
    {
        $this->resource = $resource;
        $this->recordId = $id;

        abort_unless($this->res()::can(auth()->user(), 'view', $this->record()), 403);
    }

    public function delete()
    {
        $r = $this->res();
        $record = $this->record();

        abort_unless($r::canDelete(auth()->user(), $record), 403);

        try {
            $r::delete($record);
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('m-toast', message: 'Suppression impossible : cet élément est utilisé ailleurs.', type: 'error');

            return null;
        }

        session()->flash('m-toast', $r::label().' supprimé.');

        return $this->redirect($r::indexUrl(), navigate: true);
    }

    public function render()
    {
        $r = $this->res();
        $record = $this->record();
        $user = auth()->user();

        return view('livewire.mobile.crud.show', [
            'r' => $r,
            'record' => $record,
            'details' => $r::details($record),
            'canEdit' => $r::can($user, 'update', $record),
            'canDelete' => $r::canDelete($user, $record),
        ])->title($r::label());
    }
}
