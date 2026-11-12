<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        <x-filament::section
            :heading="__('Activity Date Range')"
            :description="__('Dates are inclusive and interpreted in the selected company timezone.')"
        >
            <form wire:submit="generateReport" class="flex flex-col gap-4">
                {{ $this->form }}

                <div>
                    <x-filament::button type="submit">
                        {{ __('Generate Activity Report') }}
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        <x-filament::section
            :heading="__('Recorded Transportation Activity')"
            :description="__('This report describes recorded Trip activity only. It is not proof of physical attendance or payroll eligibility.')"
        >
            @if ($report === null)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Select an inclusive date range to review recorded activity.') }}
                </p>
            @else
                <div class="flex flex-col gap-6">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Company timezone: :timezone', ['timezone' => $report['company_timezone']]) }}
                    </p>

                    @foreach ($report['days'] as $day)
                        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                            <div class="flex flex-col gap-3 bg-gray-50 p-4 dark:bg-white/5">
                                <h3 class="font-semibold text-gray-950 dark:text-white">
                                    {{ $day['service_date'] }}
                                </h3>

                                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 xl:grid-cols-6">
                                    @foreach ([
                                        'assigned_count' => __('Assigned'),
                                        'started_count' => __('Started'),
                                        'completed_count' => __('Completed'),
                                        'cancelled_count' => __('Cancelled'),
                                        'pending_count' => __('Pending'),
                                        'in_progress_count' => __('In Progress'),
                                    ] as $field => $label)
                                        <div>
                                            <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                            <dd class="font-semibold text-gray-950 dark:text-white">{{ $day[$field] }}</dd>
                                        </div>
                                    @endforeach
                                </dl>

                                <dl class="grid gap-3 text-sm md:grid-cols-2">
                                    <div>
                                        <dt class="text-gray-500 dark:text-gray-400">{{ __('First Recorded Departure') }}</dt>
                                        <dd class="font-mono text-gray-950 dark:text-white">
                                            {{ $day['first_departure_at'] ?? __('No Recorded Time') }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Last Recorded Arrival') }}</dt>
                                        <dd class="font-mono text-gray-950 dark:text-white">
                                            {{ $day['last_arrival_at'] ?? __('No Recorded Time') }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>

                            @if ($day['trips'] === [])
                                <p class="p-4 text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('No recorded Trips for this service date.') }}
                                </p>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-sm">
                                        <thead class="border-y border-gray-200 bg-gray-50 text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                                            <tr>
                                                <th class="px-4 py-3 font-medium">{{ __('Trip') }}</th>
                                                <th class="px-4 py-3 font-medium">{{ __('Pattern') }}</th>
                                                <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
                                                <th class="px-4 py-3 font-medium">{{ __('Schedule') }}</th>
                                                <th class="px-4 py-3 font-medium">{{ __('Bus') }}</th>
                                                <th class="px-4 py-3 font-medium">{{ __('Planned Times') }}</th>
                                                <th class="px-4 py-3 font-medium">{{ __('Recorded Times') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                            @foreach ($day['trips'] as $trip)
                                                <tr class="text-gray-700 dark:text-gray-200">
                                                    <td class="px-4 py-3 font-mono">{{ $trip['id'] }}</td>
                                                    <td class="px-4 py-3">
                                                        {{ $trip['pattern_code'] ?? __('Unavailable') }}
                                                        @if (filled($trip['pattern_name']))
                                                            <span class="block text-gray-500 dark:text-gray-400">{{ $trip['pattern_name'] }}</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-4 py-3">{{ __(str($trip['status'])->replace('_', ' ')->title()->toString()) }}</td>
                                                    <td class="px-4 py-3 font-mono">{{ $trip['route_schedule_id'] }}</td>
                                                    <td class="px-4 py-3 font-mono">{{ $trip['bus_id'] }}</td>
                                                    <td class="px-4 py-3 font-mono">
                                                        {{ $trip['scheduled_departure_at'] }}
                                                        <span class="block">{{ $trip['scheduled_arrival_at'] }}</span>
                                                    </td>
                                                    <td class="px-4 py-3 font-mono">
                                                        {{ $trip['actual_departure_at'] ?? __('No Recorded Time') }}
                                                        <span class="block">{{ $trip['actual_arrival_at'] ?? __('No Recorded Time') }}</span>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
