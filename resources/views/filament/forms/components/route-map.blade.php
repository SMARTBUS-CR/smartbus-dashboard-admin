@php
    use Illuminate\Support\Js;

    $config = $getMapData();

    // Remount the ignored map when its displayed layers change.
    $mapVersion = hash('sha256', json_encode($config['layersData']));
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        wire:ignore
        wire:key="route-map-{{ $config['mapId'] }}-{{ $mapVersion }}"
        x-data="(() => {
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

            return component;
        })()"
        style="height: {{ $config['mapHeight'] }}px; width: 100%; overflow: hidden;"
    >
        <div id="{{ $config['mapId'] }}"></div>
    </div>
</x-dynamic-component>