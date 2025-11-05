<div class="space-y-4">
    <div class="border-b pb-4">
        <div class="text-sm text-gray-500 dark:text-gray-400">Subject:</div>
        <div class="text-lg font-semibold">{{ $subject }}</div>
    </div>

    @if($preheader)
    <div class="border-b pb-4">
        <div class="text-sm text-gray-500 dark:text-gray-400">Preheader:</div>
        <div class="text-sm italic text-gray-600 dark:text-gray-300">{{ $preheader }}</div>
    </div>
    @endif

    <div>
        <div class="text-sm text-gray-500 dark:text-gray-400 mb-2">Body:</div>
        <div class="prose dark:prose-invert max-w-none border rounded-lg p-4 bg-gray-50 dark:bg-gray-800">
            {!! $body !!}
        </div>
    </div>

    @if($aiEnabled)
    <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
        <div class="flex items-center gap-2 text-sm text-blue-700 dark:text-blue-300">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            <span>This template has AI enhancement enabled</span>
        </div>
    </div>
    @endif

    <div class="mt-4 p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
        <div class="text-sm text-yellow-700 dark:text-yellow-300">
            <strong>Note:</strong> This preview uses sample data. Actual emails will have real values from the website and contact information.
        </div>
    </div>
</div>
