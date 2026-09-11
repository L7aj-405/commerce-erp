<!doctype html><html lang="fr"><body style="font-family:Arial,sans-serif;color:#172033">
<p>Bonjour,</p>
<p>Vous avez été invité(e) à rejoindre <strong>{{ $organizationName }}</strong> avec le rôle « {{ $roleName }} ».</p>
<p><a href="{{ $acceptUrl }}" style="display:inline-block;padding:10px 18px;background:#172033;color:#fff;text-decoration:none;border-radius:6px">Accepter l’invitation</a></p>
<p>Ou copiez ce lien dans votre navigateur :<br><a href="{{ $acceptUrl }}">{{ $acceptUrl }}</a></p>
<p>Ce lien expire le {{ $expiresAt->format('d/m/Y H:i') }}.</p>
<p>Si vous n’attendiez pas cette invitation, vous pouvez ignorer cet email.</p>
</body></html>
