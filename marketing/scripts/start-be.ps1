# Starts the marketing API/web admin (scratch copy of BE, see README) on :8010 with the clock shifted to a working day.
# Usage: .\start-be.ps1 -Scratch <scratch dir> [-Target '2026-10-12T00:50:00Z']   (default = Monday 07:50 Asia/Jakarta)
# php.ini: a copy of the machine's php.ini plus upload_tmp_dir/sys_temp_dir pointing at the user temp dir. Without it
# PHP's default temp dir is not writable on this machine and selfie (multipart) uploads fail with "unable to create a temporary file".
param([Parameter(Mandatory)][string]$Scratch, [string]$Target = '2026-10-12T00:50:00Z')
$php = "C:\Users\Teguh\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe"
$env:Path = "$php;$env:Path"
$off = [int][math]::Round(([DateTimeOffset]::Parse($Target).ToUnixTimeSeconds() - [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()))
Set-Content "$Scratch\offset.txt" $off   # read on every request by be\bootstrap\mkt_clock.php
New-Item -ItemType Directory -Force "$Scratch\php" | Out-Null
Copy-Item "$php\php.ini" "$Scratch\php\php.ini" -Force
Add-Content "$Scratch\php\php.ini" "`nupload_tmp_dir=$env:TEMP`nsys_temp_dir=$env:TEMP`nextension_dir=`"$php\ext`"`nvariables_order=EGPCS"
Set-Location "$Scratch\be\public"
Start-Process php -ArgumentList "-c","$Scratch\php\php.ini","-S","127.0.0.1:8010","$Scratch\be\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php" -WindowStyle Hidden -RedirectStandardOutput "$Scratch\serve.log" -RedirectStandardError "$Scratch\serve.err"
Write-Host "offset $off s"
