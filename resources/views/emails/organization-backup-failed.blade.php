<p>Bonjour,</p>

<p>La sauvegarde automatique de l’organisation <strong>{{ $organization->name }}</strong> a échoué.</p>

<p>
    Date : {{ optional($backup->scheduled_for ?? $backup->updated_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }}<br>
    Raison : {{ $reason }}
</p>

<p>
    Ouvrez <a href="{{ $settingsUrl }}">Sauvegardes & restauration</a> pour consulter l’état des sauvegardes.
</p>

<p>Aucun contenu de sauvegarde, identifiant secret ou donnée client n’est inclus dans cet e-mail.</p>
