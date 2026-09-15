<!doctype html><html lang="fr"><body style="font-family:Arial,sans-serif;color:#172033">
<p>{{ __('documents.email.greeting', locale: config('documents.locale')) }}</p>
@if (! empty($customMessage))
<p>{!! nl2br(e($customMessage)) !!}</p>
@else
<p>{{ __('documents.email.delivery_note_body', ['number' => $document['document']['number'], 'date' => $document['document']['date']], config('documents.locale')) }}</p>
@endif
<p>{{ __('documents.email.closing', locale: config('documents.locale')) }}<br>{{ $document['seller']['legal_name'] }}</p>
</body></html>
