@php($locale = config('documents.locale'))
<!doctype html><html lang="fr"><body style="font-family:Arial,sans-serif;color:#172033">
<p>{{ filled($shipment['customer_name'] ?? null) ? __('fulfillment.email.greeting_named', ['name' => $shipment['customer_name']], $locale) : __('documents.email.greeting', locale: $locale) }}</p>
<p>{{ __($event === 'delivered' ? 'fulfillment.email.delivered_body' : 'fulfillment.email.handed_body', ['number' => $shipment['order_number']], $locale) }}@if ($event !== 'delivered') {{ __('fulfillment.email.on_its_way', locale: $locale) }}@endif</p>
@if ($event !== 'delivered' && ($shipment['carrier'] || $shipment['tracking_number'] || $shipment['package_count'] || $shipment['tracking_url']))
<ul>
@if ($shipment['carrier'])<li>{{ __('fulfillment.email.carrier', locale: $locale) }} : {{ $shipment['carrier'] }}</li>@endif
@if ($shipment['tracking_number'])<li>{{ __('fulfillment.email.tracking_number', locale: $locale) }} : {{ $shipment['tracking_number'] }}</li>@endif
@if ($shipment['package_count'])<li>{{ __('fulfillment.email.package_count', locale: $locale) }} : {{ $shipment['package_count'] }}</li>@endif
</ul>
@if ($shipment['tracking_url'])
<p><a href="{{ $shipment['tracking_url'] }}">{{ __('fulfillment.email.tracking_url', locale: $locale) }}</a></p>
@endif
@endif
@if (($proofs ?? []) !== [])
<p>{{ __('fulfillment.email.proofs_attached', locale: $locale) }} : {{ collect($proofs)->pluck('original_name')->implode(', ') }}</p>
@endif
<p>{{ __('documents.email.closing', locale: $locale) }}<br>{{ $shipment['seller_name'] }}</p>
</body></html>
