<?php

namespace App\Http\Controllers;

use App\Support\LegalMetadata;
use Inertia\Inertia;
use Inertia\Response;

class PublicLegalController extends Controller
{
    public function privacy(): Response
    {
        return Inertia::render('Legal/Privacy', [
            'pageTitle' => 'Politique de confidentialité',
            'reviewDisclosures' => [
                'product' => '10xScale ERP',
                'googleAuth' => 'Connexion avec Google',
                'googleAuthScopes' => 'openid email profile',
                'googleDrive' => 'Sauvegardes Google Drive',
                'googleDriveScope' => 'https://www.googleapis.com/auth/drive.file',
                'googleDataUse' => 'Utilisation des données Google',
                'retention' => 'Conservation des données',
                'revocation' => 'Révocation de l’accès Google',
            ],
            'legal' => LegalMetadata::props(),
            'legalLinks' => LegalMetadata::links(),
        ]);
    }

    public function terms(): Response
    {
        return Inertia::render('Legal/Terms', [
            'pageTitle' => 'Conditions d’utilisation',
            'legal' => LegalMetadata::props(),
            'legalLinks' => LegalMetadata::links(),
        ]);
    }
}
