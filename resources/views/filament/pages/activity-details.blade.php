<div class="space-y-4">
    <div class="grid grid-cols-2 gap-4">
        <div>
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Date</span>
            <p class="text-gray-900 dark:text-white">{{ $activity->created_at->format('d/m/Y H:i:s') }}</p>
        </div>
        <div>
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Action</span>
            <p class="text-gray-900 dark:text-white">{{ ucfirst($activity->event ?? 'N/A') }}</p>
        </div>
        <div>
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Type</span>
            <p class="text-gray-900 dark:text-white">{{ $activity->log_name }}</p>
        </div>
        <div>
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Utilisateur</span>
            <p class="text-gray-900 dark:text-white">{{ $activity->causer?->name ?? 'Système' }}</p>
        </div>
    </div>

    <div>
        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Description</span>
        <p class="text-gray-900 dark:text-white">{{ $activity->description }}</p>
    </div>

    @if($activity->properties && count($activity->properties))
    <div>
        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Changements</span>
        <div class="mt-2 bg-gray-100 dark:bg-gray-800 rounded-lg p-4 overflow-x-auto">
            <pre class="text-sm text-gray-800 dark:text-gray-200">{{ json_encode($activity->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    </div>
    @endif
</div>
