@php($akvoradoAlertLink = \LibreNMS\Plugins\AkvoradoFlow\Services\PortAlertInvestigation::link($alert, $fault))
@if($akvoradoAlertLink !== null)
    @if(!empty($html))
        <a href="{{ $akvoradoAlertLink }}">Investigate in Akvorado</a><br>
    @else
        {{ "\n" }}Investigate in Akvorado {!! $akvoradoAlertLink !!}
    @endif
@endif
