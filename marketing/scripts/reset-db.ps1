# Restores the marketing DB to the baseline snapshot (copy of the dev DB with QA leftovers removed) so a recording can be repeated.
param([Parameter(Mandatory)][string]$Scratch)
$m = "C:\Program Files\MySQL\MySQL Server 8.4\bin"
& "$m\mysql.exe" -u root -e "DROP DATABASE IF EXISTS peopleos_marketing; CREATE DATABASE peopleos_marketing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
cmd /c "`"$m\mysql.exe`" -u root peopleos_marketing < `"$Scratch\baseline.sql`""
