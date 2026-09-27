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
