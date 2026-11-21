@foreach($settings as $key => $value)
    <input type="hidden" name="settings[{{ $key }}]" value="{{ $value }}" @if($key === 'section') data-akv-current-section @endif>
@endforeach
