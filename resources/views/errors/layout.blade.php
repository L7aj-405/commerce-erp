{{--
    Branded HTTP error page (Liquid Glass V1.0). Self-contained on purpose: it must render even when
    the SPA bundle or the session is the thing that failed, so it does not boot Inertia/React.
    Colours mirror the design tokens in resources/css/app.css; the user's saved theme and language
    (localStorage `erp.theme` / `erp.locale`) are applied before paint, like app.blade.php.
--}}
<!DOCTYPE html>
<html lang="fr" dir="ltr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>@yield('code') · @yield('title_fr') — 10xScale ERP</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        <script>
            try {
                if (localStorage.getItem('erp.theme') === 'dark') document.documentElement.dataset.theme = 'dark';
                if (localStorage.getItem('erp.locale') === 'ar') { document.documentElement.lang = 'ar'; document.documentElement.dir = 'rtl'; }
            } catch (e) {}
        </script>
        <style>
            :root { --canvas:#f6f2ee; --surface:#fffefc; --ink:#121b1c; --muted:#606869; --line:#e6e8e3; --line-strong:#d3d8d2; --primary:#007c73; --primary-hover:#005d56; --primary-fg:#fff; --accent-soft:rgba(0,124,115,.09); color-scheme: light; }
            :root[data-theme="dark"] { --canvas:#0e1213; --surface:#171c1d; --ink:#eef1ef; --muted:#aab4b2; --line:#252b2c; --line-strong:#343c3d; --primary:#3fb8ab; --primary-hover:#5fcabe; --primary-fg:#061413; --accent-soft:rgba(63,184,171,.12); color-scheme: dark; }
            * { box-sizing: border-box; }
            body { margin:0; min-height:100dvh; display:grid; place-items:center; padding:24px 16px; background:var(--canvas); color:var(--ink);
                   font-family:'Plus Jakarta Sans','IBM Plex Sans Arabic',ui-sans-serif,system-ui,'Segoe UI',sans-serif; -webkit-font-smoothing:antialiased; }
            :root[dir="rtl"] body { font-family:'IBM Plex Sans Arabic','Plus Jakarta Sans','Segoe UI',Tahoma,sans-serif; }
            .card { width:100%; max-width:480px; background:var(--surface); border:1px solid var(--line); border-radius:20px; padding:32px 28px;
                    box-shadow:0 1px 2px rgba(18,27,28,.04), 0 12px 32px -12px rgba(18,27,28,.12); }
            .brand { display:flex; align-items:center; gap:10px; font-size:14px; font-weight:600; color:var(--muted); margin-bottom:28px; }
            .mark { display:grid; place-items:center; width:28px; height:28px; border-radius:8px; background:var(--ink); color:var(--canvas); font-size:11px; font-weight:700; }
            .code { display:inline-block; font-variant-numeric:tabular-nums; font-size:13px; font-weight:600; color:var(--primary); background:var(--accent-soft); border-radius:999px; padding:3px 10px; }
            h1 { margin:12px 0 8px; font-size:24px; line-height:1.25; font-weight:600; letter-spacing:-.01em; }
            p { margin:0; font-size:15px; line-height:1.6; color:var(--muted); }
            .actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:24px; }
            .btn { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:0 16px; border-radius:12px; font:inherit; font-size:14px; font-weight:600; text-decoration:none; cursor:pointer; transition:background-color .15s ease; }
            .btn-primary { background:var(--primary); color:var(--primary-fg); border:1px solid transparent; }
            .btn-primary:hover { background:var(--primary-hover); }
            .btn-secondary { background:var(--surface); color:var(--ink); border:1px solid var(--line-strong); }
            .btn-secondary:hover { background:var(--canvas); }
            .btn:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
            [data-lang="ar"] { display:none; }
            :root[lang="ar"] [data-lang="fr"] { display:none; }
            :root[lang="ar"] [data-lang="ar"] { display:revert; }
            @media (prefers-reduced-motion: reduce) { .btn { transition:none; } }
        </style>
    </head>
    <body>
        <main class="card" role="main">
            <div class="brand"><span class="mark" aria-hidden="true">10x</span><span>10xScale ERP</span></div>
            <span class="code">@yield('code')</span>
            <h1><span data-lang="fr">@yield('title_fr')</span><span data-lang="ar">@yield('title_ar')</span></h1>
            <p data-lang="fr">@yield('message_fr')</p>
            <p data-lang="ar">@yield('message_ar')</p>
            <div class="actions">
                <a class="btn btn-primary" href="/platform"><span data-lang="fr">Retour au tableau de bord</span><span data-lang="ar">العودة إلى لوحة القيادة</span></a>
                <button type="button" class="btn btn-secondary" onclick="history.length > 1 ? history.back() : (location.href = '/platform')"><span data-lang="fr">Page précédente</span><span data-lang="ar">الصفحة السابقة</span></button>
            </div>
        </main>
    </body>
</html>
