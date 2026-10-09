@php
    use Illuminate\Support\Js;

    $config = $getMapData();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div wire:ignore x-bind:aria-busy="isResolvingLocation" x-data="(() => {
            const component = leafletMapField(
                $wire,
                {{ Js::from($config) }},
            );

            component.isResolvingLocation = false;

            const setupEventHandlers = component.setupEventHandlers;

            component.setupEventHandlers = function () {
                setupEventHandlers.call(this);

                this.mapCore.callbacks.onMapClick = async (latitude, longitude) => {
                    if (this.isResolvingLocation) {
                        return;
                    }

                    this.isResolvingLocation = true;

                    try {
                        if (!this.config.state.disabled) {
                            this.setState(latitude, longitude);
                        }

                        await this.$wire.callSchemaComponentMethod(
                            this.config.state.key,
                            'handleMapClick',
                            {
                                latitude,
                                longitude,
                            },
                        );
                    } finally {
                        this.isResolvingLocation = false;
                    }
                };
            };

            const updatePickMarker = component.updatePickMarker;

            component.updatePickMarker = function () {
                updatePickMarker.call(this);

                const coordinates = this.getState();

                if (
                    !this.mapCore?.map
                    || coordinates?.lat == null
                    || coordinates?.lng == null
                ) {
                    return;
                }

                const latitude = Number(coordinates.lat);
                const longitude = Number(coordinates.lng);

                if (
                    !Number.isFinite(latitude)
                    || !Number.isFinite(longitude)
                    || latitude < -90
                    || latitude > 90
                    || longitude < -180
                    || longitude > 180
                ) {
                    return;
                }

                const map = window.Alpine.raw(this.mapCore.map);

                map.setView(
                    [latitude, longitude],
                    map.getZoom(),
                    { animate: false },
                );
            };

            return component;
        })()" style="position: relative; height: {{ $config['mapHeight'] }}px; width: 100%; overflow: hidden;">

        <div id="{{ $config['mapId'] }}"></div>

        <div
            x-show="isResolvingLocation"
            data-testid="map-location-busy"
            role="status"
            aria-live="polite"
            x-on:click.stop
            x-on:dblclick.stop
            x-on:pointerdown.stop
            x-on:wheel.stop.prevent
            style="display: none; position: absolute; inset: 0; z-index: 1000; cursor: wait; background: rgba(17, 24, 39, 0.55); color: white;">
            <div
                style="display: flex; align-items: center; justify-content: center; height: 100%; padding: 1rem; text-align: center;">
                {{ __('Looking up location information...') }}
            </div>
        </div>

        @push('styles')
            <style>
                {!! $config['customStyles'] !!}
            </style>
        @endpush

        @push('scripts')
            <script>
                {!! $config['customScripts'] !!}
            </script>
        @endpush
    </div>
</x-dynamic-component>