param(
    [Parameter(Mandatory = $true)][string]$ProjectRoot,
    [string]$StateDirectory = "$env:LOCALAPPDATA\FitControl\AuditCheckpoints",
    [string]$PhpPath = "C:\xampp\php\php.exe",
    [string]$DailyAt = "08:00",
    [switch]$ShowRecoveryKey,
    [switch]$UseExistingRecoveryKey
)
$ErrorActionPreference = 'Stop'
$project = (Resolve-Path -LiteralPath $ProjectRoot).Path
if (-not (Test-Path -LiteralPath (Join-Path $project "scripts\exportar-checkpoint-auditoria.php"))) { throw "A pasta informada não parece ser a raiz do FitControl." }
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw "PHP não encontrado: $PhpPath" }
$state = [System.IO.Path]::GetFullPath($StateDirectory)
if ($state.Equals($project, [System.StringComparison]::OrdinalIgnoreCase) -or $state.StartsWith($project.TrimEnd('\') + '\', [System.StringComparison]::OrdinalIgnoreCase)) { throw "O armazenamento dos checkpoints deve ficar fora da pasta do projeto." }
if ($DailyAt -notmatch '^(?:[01]\d|2[0-3]):[0-5]\d$') { throw "Informe o horário no formato HH:mm." }
$taskName = "FitControl - Checkpoint externo da auditoria"
$checkpointDirectory = Join-Path $state "checkpoints"
New-Item -ItemType Directory -Force -Path $checkpointDirectory | Out-Null
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
& icacls.exe $state /inheritance:r /grant:r "$($identity):(OI)(CI)F" /T /C | Out-Null
if ($LASTEXITCODE -ne 0) { throw "Não foi possível restringir as permissões da pasta de estado." }
$configPath = Join-Path $state "config.dpapi"
if (Test-Path -LiteralPath $configPath) { throw "Já existe uma configuração protegida em $configPath. Não será sobrescrita; preserve a chave anterior." }
if ($UseExistingRecoveryKey) {
    $recoveryKeySecure = Read-Host "Cole a chave HMAC de 64 caracteres hexadecimais do cofre" -AsSecureString
    $recoveryKeyPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($recoveryKeySecure)
    try { $secret = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($recoveryKeyPointer) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($recoveryKeyPointer) }
    if ($secret -notmatch '^[a-fA-F0-9]{64}
$dbHost = Read-Host "DB_HOST (Enter = 127.0.0.1)"
if ([string]::IsNullOrWhiteSpace($dbHost)) { $dbHost = "127.0.0.1" }
$dbPort = Read-Host "DB_PORT (Enter = 3306)"
if ([string]::IsNullOrWhiteSpace($dbPort)) { $dbPort = "3306" }
$dbName = Read-Host "DB_NAME (Enter = academia_alunos)"
if ([string]::IsNullOrWhiteSpace($dbName)) { $dbName = "academia_alunos" }
$dbUser = Read-Host "DB_USER (Enter = root)"
if ([string]::IsNullOrWhiteSpace($dbUser)) { $dbUser = "root" }
$dbPasswordSecure = Read-Host "DB_PASSWORD (Enter se estiver vazio no XAMPP)" -AsSecureString
$dbPasswordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($dbPasswordSecure)
try { $dbPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($dbPasswordPointer) }
finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($dbPasswordPointer) }
$config = @{ checkpointSecret = $secret; dbHost = $dbHost; dbPort = $dbPort; dbName = $dbName; dbUser = $dbUser; dbPassword = $dbPassword } | ConvertTo-Json -Compress
$secureConfig = ConvertTo-SecureString -String $config -AsPlainText -Force
$encrypted = ConvertFrom-SecureString -SecureString $secureConfig
Set-Content -LiteralPath $configPath -Value $encrypted -Encoding ASCII
$runner = Join-Path $PSScriptRoot "executar-checkpoint-auditoria.ps1"
$actionArgs = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $runner + '" -ProjectRoot "' + $project + '" -StateDirectory "' + $state + '" -EncryptedConfigPath "' + $configPath + '" -PhpPath "' + $PhpPath + '"'
$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $actionArgs
$trigger = New-ScheduledTaskTrigger -Daily -At $DailyAt
$principal = New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description "Exporta e verifica diariamente um checkpoint HMAC da auditoria FitControl; a chave é protegida por DPAPI para este usuário Windows." -Force | Out-Null
Write-Host ""
Write-Host "Agendamento instalado: $taskName"
Write-Host "Horário diário: $DailyAt (horário local do Windows)"
Write-Host "Checkpoints: $checkpointDirectory"
Write-Host "Configuração protegida por DPAPI: $configPath"
Write-Warning "A tarefa roda somente quando este usuário Windows está conectado. Faça backup externo da pasta checkpoints; não copie config.dpapi para outra conta ou computador."
if ($ShowRecoveryKey) {
    Write-Warning "A chave será exibida uma única vez. Copie-a diretamente para um cofre de senhas; não a envie por chat, e-mail ou Git."
    Write-Host "FITCONTROL_AUDIT_CHECKPOINT_SECRET=$secret"
} else {
    Write-Warning "Para recuperação em outro computador, reinstale usando a mesma chave; use -ShowRecoveryKey para guardá-la em cofre seguro. A configuração DPAPI só abre neste usuário Windows."
}
) { throw "A chave de recuperação deve conter exatamente 64 caracteres hexadecimais." }
    $secret = $secret.ToLowerInvariant()
} else {
    $random = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($random) } finally { $rng.Dispose() }
    $secret = [System.BitConverter]::ToString($random).Replace("-", "").ToLowerInvariant()
    [Array]::Clear($random, 0, $random.Length)
}
$dbHost = Read-Host "DB_HOST (Enter = 127.0.0.1)"
if ([string]::IsNullOrWhiteSpace($dbHost)) { $dbHost = "127.0.0.1" }
$dbPort = Read-Host "DB_PORT (Enter = 3306)"
if ([string]::IsNullOrWhiteSpace($dbPort)) { $dbPort = "3306" }
$dbName = Read-Host "DB_NAME (Enter = academia_alunos)"
if ([string]::IsNullOrWhiteSpace($dbName)) { $dbName = "academia_alunos" }
$dbUser = Read-Host "DB_USER (Enter = root)"
if ([string]::IsNullOrWhiteSpace($dbUser)) { $dbUser = "root" }
$dbPasswordSecure = Read-Host "DB_PASSWORD (Enter se estiver vazio no XAMPP)" -AsSecureString
$dbPasswordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($dbPasswordSecure)
try { $dbPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($dbPasswordPointer) }
finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($dbPasswordPointer) }
$config = @{ checkpointSecret = $secret; dbHost = $dbHost; dbPort = $dbPort; dbName = $dbName; dbUser = $dbUser; dbPassword = $dbPassword } | ConvertTo-Json -Compress
$secureConfig = ConvertTo-SecureString -String $config -AsPlainText -Force
$encrypted = ConvertFrom-SecureString -SecureString $secureConfig
Set-Content -LiteralPath $configPath -Value $encrypted -Encoding ASCII
$runner = Join-Path $PSScriptRoot "executar-checkpoint-auditoria.ps1"
$actionArgs = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $runner + '" -ProjectRoot "' + $project + '" -StateDirectory "' + $state + '" -EncryptedConfigPath "' + $configPath + '" -PhpPath "' + $PhpPath + '"'
$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $actionArgs
$trigger = New-ScheduledTaskTrigger -Daily -At $DailyAt
$principal = New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description "Exporta e verifica diariamente um checkpoint HMAC da auditoria FitControl; a chave é protegida por DPAPI para este usuário Windows." -Force | Out-Null
Write-Host ""
Write-Host "Agendamento instalado: $taskName"
Write-Host "Horário diário: $DailyAt (horário local do Windows)"
Write-Host "Checkpoints: $checkpointDirectory"
Write-Host "Configuração protegida por DPAPI: $configPath"
Write-Warning "A tarefa roda somente quando este usuário Windows está conectado. Faça backup externo da pasta checkpoints; não copie config.dpapi para outra conta ou computador."
if ($ShowRecoveryKey) {
    Write-Warning "A chave será exibida uma única vez. Copie-a diretamente para um cofre de senhas; não a envie por chat, e-mail ou Git."
    Write-Host "FITCONTROL_AUDIT_CHECKPOINT_SECRET=$secret"
} else {
    Write-Warning "Para recuperação em outro computador, reinstale usando a mesma chave; use -ShowRecoveryKey para guardá-la em cofre seguro. A configuração DPAPI só abre neste usuário Windows."
}
