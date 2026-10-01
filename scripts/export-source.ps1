param(
    [Parameter(Mandatory = $true)]
    [string] $OutputPath
)

$ErrorActionPreference = 'Stop'

# Export only committed, tracked source. Runtime .env files, storage contents,
# logs, uploads, generated backups, vendor and node_modules cannot enter this
# archive unless someone first (incorrectly) commits them.
git rev-parse --is-inside-work-tree | Out-Null
$trackedSecrets = git ls-files -- '.env' '.env.*' 'storage/app/*' 'storage/logs/*' '*.erpbackup' '*.sql' |
    Where-Object { $_ -ne '.env.example' -and -not $_.EndsWith('/.gitignore') }
if ($trackedSecrets) {
    throw "Refusing source export because a forbidden runtime/secret path is tracked: $trackedSecrets"
}

$parent = Split-Path -Parent $OutputPath
if ($parent -and -not (Test-Path -LiteralPath $parent)) {
    New-Item -ItemType Directory -Path $parent | Out-Null
}

git archive --format=zip --output=$OutputPath HEAD
if ($LASTEXITCODE -ne 0) {
    throw 'git archive failed.'
}

Write-Host "Safe tracked-source archive created at $OutputPath"
