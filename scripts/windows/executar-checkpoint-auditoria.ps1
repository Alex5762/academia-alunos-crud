param(
    [Parameter(Mandatory = $true)][string]$ProjectRoot,
    [Parameter(Mandatory = $true)][string]$StateDirectory,
    [Parameter(Mandatory = $true)][string]$EncryptedConfigPath,
    [Parameter(Mandatory = $true)][string]$PhpPath
)
$ErrorActionPreference = 'Stop'
$logPath = Join-Path $StateDirectory ("checkpoint-" + (Get-Date).ToUniversalTime().ToString("yyyyMMdd-HHmmss") + ".log")
try {
    if (-not (Test-Path -LiteralPath $ProjectRoot -PathType Container)) { throw "Pasta do projeto não encontrada." }
    if (-not (Test-Path -LiteralPath $EncryptedConfigPath -PathType Leaf)) { throw "Configuração protegida não encontrada." }
    $secureConfig = Get-Content -LiteralPath $EncryptedConfigPath -Raw | ConvertTo-SecureString
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureConfig)
    try { $configJson = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
    $config = $configJson | ConvertFrom-Json
    if ([string]::IsNullOrWhiteSpace($config.checkpointSecret) -or $config.checkpointSecret.Length -lt 64) { throw "Chave HMAC protegida inválida." }
    $php = (Resolve-Path -LiteralPath $PhpPath).Path
    if (-not (Test-Path -LiteralPath $php -PathType Leaf)) { throw "Executável PHP não encontrado em $PhpPath." }
    $env:FITCONTROL_AUDIT_CHECKPOINT_SECRET = [string]$config.checkpointSecret
    $env:DB_HOST = [string]$config.dbHost
    $env:DB_PORT = [string]$config.dbPort
    $env:DB_NAME = [string]$config.dbName
    $env:DB_USER = [string]$config.dbUser
    $env:DB_PASSWORD = [string]$config.dbPassword
    $project = (Resolve-Path -LiteralPath $ProjectRoot).Path
    $checkpointDirectory = Join-Path $StateDirectory "checkpoints"
    if (-not (Test-Path -LiteralPath $checkpointDirectory)) { New-Item -ItemType Directory -Path $checkpointDirectory | Out-Null }
    $stamp = (Get-Date).ToUniversalTime().ToString("yyyyMMdd-HHmmss") + "-" + ([Guid]::NewGuid().ToString("N").Substring(0, 6))
    $checkpointPath = Join-Path $checkpointDirectory ("fitcontrol-audit-" + $stamp + ".json")
    $exportScript = Join-Path $project "scripts\exportar-checkpoint-auditoria.php"
    $verifyScript = Join-Path $project "scripts\verificar-checkpoint-auditoria.php"
    Push-Location $project
    try {
        $exportOutput = & $php $exportScript $checkpointPath 2>&1
        if ($LASTEXITCODE -ne 0) { throw ("Exportação falhou: " + ($exportOutput -join [Environment]::NewLine)) }
        $verifyOutput = & $php $verifyScript $checkpointPath 2>&1
        if ($LASTEXITCODE -ne 0) { throw ("Verificação pós-exportação falhou: " + ($verifyOutput -join [Environment]::NewLine)) }
        $result = @("UTC: " + (Get-Date).ToUniversalTime().ToString("o"); "Checkpoint: " + $checkpointPath; ($exportOutput -join [Environment]::NewLine); ($verifyOutput -join [Environment]::NewLine); "RESULTADO: SUCESSO") -join [Environment]::NewLine
        Add-Content -LiteralPath $logPath -Value $result -Encoding UTF8
    }
    finally { Pop-Location }
}
catch {
    Add-Content -LiteralPath $logPath -Value ("UTC: " + (Get-Date).ToUniversalTime().ToString("o") + [Environment]::NewLine + "RESULTADO: FALHA" + [Environment]::NewLine + $_.Exception.Message) -Encoding UTF8
    exit 1
}
finally {
    Remove-Item Env:FITCONTROL_AUDIT_CHECKPOINT_SECRET -ErrorAction SilentlyContinue
    Remove-Item Env:DB_PASSWORD -ErrorAction SilentlyContinue
}
