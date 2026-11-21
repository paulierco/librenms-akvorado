@php
    $settings = $data['viewSettings'];
    $fixed = isset($settings['start'], $settings['end']);
    $end = $settings['end'] ?? $data['panel']['windowEnd'] ?? intdiv(time(), 60) * 60;
    $start = $settings['start'] ?? $data['panel']['windowStart'] ?? $end - $settings['period'] * 60;
    $messages = $errors->getBag('akvoradoSavedView');
@endphp
<details style="margin-bottom:10px">
    <summary>Custom time range</summary>
    <form method="get" action="{{ route('device.akvorado', ['device' => $device->device_id]) }}" class="form-inline" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-top:8px">
        @foreach(array_diff_key($settings, ['start' => true, 'end' => true]) as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}" @if($key === 'section') data-akv-current-section @endif>
        @endforeach
        <div class="form-group">
            <label for="akv-range-start">Start (UTC)</label>
            <input class="form-control" id="akv-range-start" name="range_start" type="datetime-local" step="1" value="{{ gmdate('Y-m-d\TH:i:s', $start) }}" required>
        </div>
        <div class="form-group">
            <label for="akv-range-end">End (UTC)</label>
            <input class="form-control" id="akv-range-end" name="range_end" type="datetime-local" step="1" value="{{ gmdate('Y-m-d\TH:i:s', $end) }}" required>
        </div>
        <button type="submit" class="btn btn-default">Apply time range</button>
    </form>
    <p class="text-muted" style="margin:6px 0">Choose a range from 5 minutes to 7 days within the last 8 days.</p>
</details>
<details style="margin-bottom:12px" @if(session()->has('akvoradoSavedStatus') || $messages->any()) open @endif>
    <summary>Saved views</summary>
    @if(session('akvoradoSavedStatus'))
        <p role="status">{{ session('akvoradoSavedStatus') }}</p>
    @endif
    @foreach($messages->all() as $message)
        <p class="text-danger" role="alert">{{ $message }}</p>
    @endforeach
    <form method="post" action="{{ route('device.akvorado.saved-views.store', ['device' => $device->device_id]) }}" class="form-inline" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin:8px 0">
        @csrf
        @include('akvorado-flow::device.tabs.investigation-settings')
        <div class="form-group">
            <label for="akv-view-name">View name</label>
            <input class="form-control" id="akv-view-name" name="name" maxlength="60" required>
        </div>
        @if($fixed)
            <div class="checkbox">
                <label><input type="checkbox" name="keep_fixed" value="1"> Keep fixed UTC dates</label>
            </div>
        @endif
        <button type="submit" class="btn btn-default">Save current view</button>
    </form>
    @if($data['savedViews'] !== [])
        <ul class="list-unstyled">
            @foreach($data['savedViews'] as $id => $saved)
                <li style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:5px">
                    <a href="{{ route('device.akvorado', ['device' => $device->device_id] + $saved['query']) }}">{{ $saved['name'] }}</a>
                    @if($saved['expired'])
                        <small class="text-muted">Fixed window expired. Opens with the saved lookback.</small>
                    @endif
                    <form method="post" action="{{ route('device.akvorado.saved-views.destroy', ['device' => $device->device_id, 'view' => $id]) }}" style="display:inline">
                        @csrf
                        @method('DELETE')
                        @include('akvorado-flow::device.tabs.investigation-settings')
                        <button type="submit" class="btn btn-default btn-xs" aria-label="Remove saved view {{ $saved['name'] }}">Remove</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</details>
