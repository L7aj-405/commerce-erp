body { color: {{ $ink ?? '#1f211d' }}; font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; line-height: 1.43; }
.runhead { position: fixed; top: -15mm; left: 0; right: 0; height: 12mm; border-bottom: 1px solid #d7d7cf; font-size: 8.5px; color: #3f423b; }
.runhead .r1 { display: block; }
.runhead .r1 .who { font-weight: bold; color: {{ $ink ?? '#1f211d' }}; text-transform: uppercase; }
.runhead .r1 .doc { float: right; font-weight: bold; color: {{ $accent }}; }
.runhead .r2 { display: block; margin-top: 2px; }
.runhead .r2 .sep { color: #9ca096; }
.runfoot { border-top: 1px solid #d7d7cf; padding-top: 4px; font-size: 7.8px; font-style: italic; color: #4f524a; text-align: center; line-height: 1.45; }
.watermark-text { position: fixed; top: 40%; left: 12%; font-size: 90px; font-weight: bold; color: rgba(176, 69, 59, .12); transform: rotate(-26deg); }
.watermark-logo { position: fixed; top: 0; left: 0; right: 0; text-align: center; z-index: -1; }
.watermark-logo img { width: 95mm; margin-top: 118mm; opacity: 0.05; }
.masthead, table.masthead { width: 100%; border-collapse: collapse; }
.masthead td, table.masthead td { vertical-align: top; }
.logo { max-height: 68px; max-width: 238px; margin-bottom: 7px; }
.company-name { font-size: 17px; font-weight: bold; text-transform: uppercase; color: {{ $ink ?? '#1f211d' }}; margin-bottom: 7px; }
.seller { color: #343730; font-size: 9.2px; line-height: 1.42; }
.seller-row, .dest-row { margin-bottom: 2px; }
.info-label { font-weight: 600; color: #4c4f47; }
.info-value { font-weight: 700; color: {{ $ink ?? '#1f211d' }}; }
.dest { font-size: 9.2px; line-height: 1.42; color: #343730; }
.dest .lbl { font-weight: bold; letter-spacing: .08em; font-size: 8.8px; color: #3d4039; }
.dest .name { font-weight: bold; color: {{ $accent }}; font-size: 10.8px; margin: 3px 0 2px; }
h1.title { margin: 18px 0 12px; text-align: center; font-size: 26px; font-weight: bold; letter-spacing: .06em; color: {{ $ink ?? '#1f211d' }}; }
table.meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
table.meta th { border: 1px solid #c9c9c2; padding: 5px 6px; font-size: 8.6px; font-weight: bold; text-align: center; color: #2f322d; }
table.meta td { border: 1px solid #c9c9c2; padding: 6px; text-align: center; font-size: 9.4px; font-weight: 600; color: {{ $ink ?? '#1f211d' }}; }
table.items { width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: auto; }
table.items thead { display: table-header-group; }
table.items tr { page-break-inside: avoid; }
table.items th { background: {{ $accent }}; color: #fff; font-size: 7.9px; font-weight: bold; text-transform: uppercase; letter-spacing: .03em; padding: 6px 5px; }
table.items td { border-bottom: 1px solid #e2e2da; padding: 3px 5px; font-size: 8.8px; line-height: 1.3; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; }
.num { text-align: right; white-space: nowrap; }
.ctr { text-align: center; }
.des-sub, .small { color: #555950; font-size: 8.2px; }
.notes { margin-top: 12px; white-space: pre-line; page-break-inside: avoid; }
.issued-meta { margin: 12px 0 0; color: #666a60; font-size: 8.2px; text-align: left; }
