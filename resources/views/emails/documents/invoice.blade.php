<!doctype html><html lang="fr"><body style="font-family:Arial,sans-serif;color:#172033">
<p>{{ __('documents.email.greeting', locale: config('documents.locale')) }}</p>
<p>{{ __('documents.email.invoice_body', ['number' => $document['document']['number'], 'date' => $document['document']['date']], config('documents.locale')) }}</p>
<p>{{ __('documents.email.closing', locale: config('documents.locale')) }}<br>{{ $document['seller']['legal_name'] }}</p>
</body></html>
