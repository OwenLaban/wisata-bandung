# deploy.ps1 - Deploy branch main terbaru ke VPS produksi.
# Pemakaian: .\deploy.ps1   (opsional: .\deploy.ps1 -Key "C:\path\kunci.pem")
param(
    [string]$Key = "$HOME\.ssh\hydroponics-iot-server_key.pem",
    [string]$VpsUser = "azureuser",
    [string]$VpsHost = "20.255.56.74"
)

$ErrorActionPreference = "Stop"

# Wajib dari branch main agar tidak salah deploy kode eksperimen
$branch = (git rev-parse --abbrev-ref HEAD).Trim()
if ($branch -ne "main") {
    Write-Host "BATAL: kamu di branch '$branch'. Deploy hanya boleh dari main." -ForegroundColor Red
    exit 1
}

# Pastikan main lokal sudah sinkron dengan GitHub sebelum deploy
git pull origin main

Write-Host "`n==> Deploy ke $VpsHost ..." -ForegroundColor Cyan
ssh -i $Key "$VpsUser@$VpsHost" "cd ~/wisata && git pull origin main && docker compose -f docker-compose.prod.yml up -d --build && sudo docker ps --filter name=wisata --format '{{.Names}}: {{.Status}}'"

if ($LASTEXITCODE -eq 0) {
    Write-Host "`n==> Deploy selesai. Cek https://wisatabandung.duckdns.org" -ForegroundColor Green
} else {
    Write-Host "`n==> Deploy GAGAL (exit code $LASTEXITCODE)" -ForegroundColor Red
    exit 1
}
