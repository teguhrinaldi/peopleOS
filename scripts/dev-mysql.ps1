# Starts the local development MySQL 8.4 instance for PeopleOS (127.0.0.1:3306).
# Data lives in .dev/mysql-data (gitignored). Stop it with Ctrl+C or scripts\dev-mysql-stop.ps1.
$root = Split-Path -Parent $PSScriptRoot
$bin  = 'C:\Program Files\MySQL\MySQL Server 8.4\bin'
$data = Join-Path $root '.dev\mysql-data'
if (-not (Test-Path $data)) {
    & "$bin\mysqld.exe" --initialize-insecure --console --datadir="$data"
}
& "$bin\mysqld.exe" --console --datadir="$data" --bind-address=127.0.0.1 --port=3306 --mysqlx=OFF --default-time-zone="+00:00" --innodb-flush-log-at-trx-commit=0 --innodb-doublewrite=OFF --skip-log-bin --sync-binlog=0

