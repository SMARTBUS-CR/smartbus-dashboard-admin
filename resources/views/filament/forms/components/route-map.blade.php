@php
use Illuminate\Support\Js;

$config = $getMapData();

// Remount the ignored map when its displayed layers change.
$mapVersion = hash('sha256', json_encode($config['layersData']));
@endphp

@once
    @push('styles')
        <style>
            .leaflet-marker-icon[data-detour-focus="true"] {
                filter: drop-shadow(0 0 5px #f59e0b) drop-shadow(0 0 2px #f59e0b);
            }
        </style>
    @endpush
@endonce

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div wire:ignore wire:key="route-map-{{ $config['mapId'] }}-{{ $mapVersion }}" x-data="(() => {
            const component = leafletMapField(
                $wire,
                {{ Js::from($config) }},
            );

            const setupEventHandlers = component.setupEventHandlers;

            component.setupEventHandlers = function () {
                const layers = window.Alpine.raw(this.mapCore.layers);

                for (const [layerId, entry] of layers.entries()) {
                    const layer = window.Alpine.raw(entry.layer);
                    const element = layer.getElement?.();

                    if (element?.classList.contains('leaflet-marker-icon')) {
                        element.dataset.layerId = layerId;
                    }
                }

                setupEventHandlers.call(this);

                const defaultLayerUpdated =
                    this.mapCore.callbacks.onLayerUpdated;

                this.mapCore.callbacks.onLayerUpdated = (layerId, data) => {
                    if (layerId.startsWith('adjustment:')) {
                        this.$wire.callSchemaComponentMethod(
                            this.config.state.key,
                            'handleAdjustmentPointMove',
                            {
                                layerId,
                                latitude: data.lat,
                                longitude: data.lng,
                            },
                        );

                        return;
                    }

                    defaultLayerUpdated?.(layerId, data);
                };
            };

            component.focusDetourSegment = function (detail) {
            if (detail.mapId !== this.config.mapId) {
            return;
            }

            const layers = window.Alpine.raw(this.mapCore.layers);
            const map = window.Alpine.raw(this.mapCore.map);
            const fromEntry = layers.get(detail.fromLayerId);
            const toEntry = layers.get(detail.toLayerId);

            if (!fromEntry || !toEntry) {
            return;
            }

            const fromLayer = window.Alpine.raw(fromEntry.layer);
            const toLayer = window.Alpine.raw(toEntry.layer);

            if (!fromLayer.getLatLng || !toLayer.getLatLng) {
            return;
            }

            for (const entry of layers.values()) {
            const layer = window.Alpine.raw(entry.layer);
            const element = layer.getElement?.();

            if (element) {
            delete element.dataset.detourFocus;
            }
            }

            for (const layer of [fromLayer, toLayer]) {
            const element = layer.getElement?.();

            if (element) {
            element.dataset.detourFocus = 'true';
            }
            }

            const points = [
            fromLayer.getLatLng(),
            toLayer.getLatLng(),
            ];

            const adjustmentPrefix =
            `adjustment:${detail.fromOccurrenceId}:${detail.toOccurrenceId}:`;

            for (const [layerId, entry] of layers.entries()) {
            if (!layerId.startsWith(adjustmentPrefix)) {
            continue;
            }

            const layer = window.Alpine.raw(entry.layer);

            if (layer.getLatLng) {
            points.push(layer.getLatLng());
            }
            }

            map.fitBounds(points, {
            padding: [32, 32],
            maxZoom: 15,
            animate: false,
            });

            this.$el.scrollIntoView({
            block: 'nearest',
            behavior: 'smooth',
            });          };

            return component;
        })()" x-on:route-detour-segment-focus.window="focusDetourSegment($event.detail)" style="height: {{ $config['mapHeight'] }}px; width: 100%; overflow: hidden;">
        <div id="{{ $config['mapId'] }}"></div>
    </div>
</x-dynamic-component>