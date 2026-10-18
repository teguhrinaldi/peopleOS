# One-time setup of the isolated marketing instance (reference; these are the exact steps used). Never touches the repo or the `peopleos` DB.
# Usage: .\setup.ps1 -Scratch <empty scratch dir>
param([Parameter(Mandatory)][string]$Scratch)
$repo = (Resolve-Path "$PSScriptRoot\..\..").Path
$m = "C:\Program Files\MySQL\MySQL Server 8.4\bin"
New-Item -ItemType Directory -Force $Scratch | Out-Null
# 1. private copy of BE (vendor / node_modules are junctions to the repo's; everything else is copied)
robocopy "$repo\BE" "$Scratch\be" /E /XD vendor node_modules /NFL /NDL /NJH /NJS | Out-Null
cmd /c mklink /J "$Scratch\be\vendor" "$repo\BE\vendor"; cmd /c mklink /J "$Scratch\be\node_modules" "$repo\BE\node_modules"
(Get-Content "$Scratch\be\.env") -replace 'DB_DATABASE=peopleos','DB_DATABASE=peopleos_marketing' -replace 'APP_URL=.*','APP_URL=http://127.0.0.1:8010' | Set-Content "$Scratch\be\.env"
Add-Content "$Scratch\be\.env" "SESSION_COOKIE=peopleos_mkt_session"
# 2. clock shift (marketing copy only): bootstrap/app.php requires mkt_clock.php, which reads <scratch>\offset.txt on every request
Copy-Item "$PSScriptRoot\mkt_clock.php" "$Scratch\be\bootstrap\mkt_clock.php"
(Get-Content "$Scratch\be\bootstrap\app.php" -Raw) -replace '(?s)^<\?php\r?\n', "<?php`r`n`r`nrequire_once __DIR__ . '/mkt_clock.php';`r`n" | Set-Content "$Scratch\be\bootstrap\app.php"
# 3. private DB copy
& "$m\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS peopleos_marketing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
cmd /c "`"$m\mysqldump.exe`" -u root --routines peopleos | `"$m\mysql.exe`" -u root peopleos_marketing"
# 4. Flutter web build from a copy of app/ (the repo's app/build is untouched)
robocopy "$repo\app" "$Scratch\app" /E /XD build .dart_tool /NFL /NDL /NJH /NJS | Out-Null
Push-Location "$Scratch\app"; flutter build web --release --no-web-resources-cdn --dart-define=PEOPLEOS_USE_API=true --dart-define=API_BASE_URL=http://127.0.0.1:8010; Pop-Location
# 5. node deps (outside the repo)
Push-Location $Scratch; npm init -y | Out-Null; npm i puppeteer-core@latest pdfjs-dist@3.11.174; Pop-Location
Write-Host "Now: clean QA leftovers if the dev DB has any (see README), snapshot to $Scratch\baseline.sql, then start-be.ps1, serve.js, set-clock.js, record.js ..."
