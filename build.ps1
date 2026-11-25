# Crea el paquete de producción dist\bezares-social-<commit>.zip a partir del último commit,
# SIN la carpeta .git (historial), sin config.php y sin archivos de desarrollo (.gitattributes → export-ignore).
#   powershell -ExecutionPolicy Bypass -File build.ps1
$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
# Algunas unidades (p. ej. D: en NTFS sin propietario) hacen que git rechace la carpeta: se autoriza solo para estas órdenes.
$safe = 'safe.directory=' + ((Get-Location).Path -replace '\\', '/')
function Invoke-Git { & git -c $safe @args; if ($LASTEXITCODE -ne 0) { throw "git $args ha fallado" } }

if (Invoke-Git status --porcelain) { Write-Warning 'Hay cambios sin commit: el paquete solo incluye lo que está en el último commit.' }
$rev = (Invoke-Git rev-parse --short HEAD).Trim()
New-Item -ItemType Directory -Force dist | Out-Null
$zip = "dist\bezares-social-$rev.zip"
Invoke-Git archive --format=zip --output=$zip HEAD
Write-Host "Paquete creado: $zip"
Write-Host 'Súbelo al hosting y apunta el dominio a la carpeta public/. Crea allí config.php a partir de config.sample.php.'
