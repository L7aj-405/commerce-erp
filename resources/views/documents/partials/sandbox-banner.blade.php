{{--
    Test Mode marking (Phase 3). Included only when the renderer payload sets
    `sandbox`; Production payloads never carry it, so Production output is
    unchanged. The diagonal mark is position:fixed and repeats on every page.
--}}
<div style="position: fixed; top: 36%; left: 4%; right: 4%; text-align: center; font-size: 64px; font-weight: bold; color: #f4c7c3; transform: rotate(-26deg); z-index: 0;">DOCUMENT DE TEST</div>
<div class="sandbox-document-banner" style="border: 2px solid #b45309; background: #fef3c7; color: #7c2d12; text-align: center; font-weight: bold; font-size: 11px; padding: 6px 8px; margin: 0 0 10px; letter-spacing: .04em;">
    DOCUMENT DE TEST — NON COMPTABLE — SANS VALEUR LÉGALE NI FISCALE<br>
    <span style="font-size: 10px;">Référence de test : {{ $sandbox['reference'] ?? 'non attribuée (brouillon)' }} · {{ $sandbox['status'] ?? '' }}</span>
</div>
