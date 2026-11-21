<div class="row">
    <div class="col-md-12">
        @include('akvorado-flow::hooks.panel', ['panel' => $panel, 'heading' => 'Akvorado — Switch Traffic', 'context' => $device->hostname ?? 'Device'])
    </div>
</div>
