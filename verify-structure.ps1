# Script de vérification de la structure
Write-Host "Verification de la structure du projet..." -ForegroundColor Cyan
Write-Host ""

$projectRoot = $PSScriptRoot
Set-Location $projectRoot

$checks = @{
    "api/" = @{
        path = ".\api"
        type = "Directory"
        critical = $true
    }
    "api/index.php" = @{
        path = ".\api\index.php"
        type = "File"
        critical = $true
    }
    "api/config/" = @{
        path = ".\api\config"
        type = "Directory"
        critical = $true
    }
    "assets/" = @{
        path = ".\assets"
        type = "Directory"
        critical = $true
    }
    "css/" = @{
        path = ".\css"
        type = "Directory"
        critical = $true
    }
    "js/" = @{
        path = ".\js"
        type = "Directory"
        critical = $true
    }
    "pages/" = @{
        path = ".\pages"
        type = "Directory"
        critical = $true
    }
    "index.html" = @{
        path = ".\index.html"
        type = "File"
        critical = $true
    }
    ".dev/" = @{
        path = ".\.dev"
        type = "Directory"
        critical = $false
    }
    "backend/" = @{
        path = ".\backend"
        type = "Directory"
        shouldNotExist = $true
    }
    "frontend/" = @{
        path = ".\frontend"
        type = "Directory"
        shouldNotExist = $true
    }
}

$errors = 0
$warnings = 0

foreach ($name in $checks.Keys) {
    $check = $checks[$name]
    $exists = Test-Path $check.path

    if ($check.shouldNotExist) {
        if ($exists) {
            Write-Host "[ERREUR] $name existe mais devrait etre supprime" -ForegroundColor Red
            $errors++
        } else {
            Write-Host "[OK] $name n'existe pas (correct)" -ForegroundColor Green
        }
    } else {
        if ($exists) {
            Write-Host "[OK] $name" -ForegroundColor Green
        } else {
            if ($check.critical) {
                Write-Host "[ERREUR] $name manquant (CRITIQUE)" -ForegroundColor Red
                $errors++
            } else {
                Write-Host "[WARN] $name manquant" -ForegroundColor Yellow
                $warnings++
            }
        }
    }
}

Write-Host ""
Write-Host "Verification du lien symbolique WAMP..." -ForegroundColor Cyan
$wampLink = "C:\wamp64\www\api"
if (Test-Path $wampLink) {
    $target = (Get-Item $wampLink).Target
    if ($target) {
        Write-Host "[OK] Lien symbolique existe: $wampLink -> $target" -ForegroundColor Green
        if ($target -like "*\api") {
            Write-Host "[OK] Le lien pointe vers le dossier api/ (correct)" -ForegroundColor Green
        } else {
            Write-Host "[WARN] Le lien pointe vers: $target" -ForegroundColor Yellow
            Write-Host "       Il devrait pointer vers: $projectRoot\api" -ForegroundColor Yellow
            $warnings++
        }
    } else {
        Write-Host "[WARN] $wampLink existe mais n'est pas un lien symbolique" -ForegroundColor Yellow
        $warnings++
    }
} else {
    Write-Host "[ERREUR] Lien symbolique WAMP manquant: $wampLink" -ForegroundColor Red
    $errors++
}

Write-Host ""
Write-Host "Resultat:" -ForegroundColor Cyan
Write-Host "  Erreurs: $errors" -ForegroundColor $(if ($errors -gt 0) { "Red" } else { "Green" })
Write-Host "  Avertissements: $warnings" -ForegroundColor $(if ($warnings -gt 0) { "Yellow" } else { "Green" })

Write-Host ""
if ($errors -eq 0 -and $warnings -eq 0) {
    Write-Host "La structure est correcte!" -ForegroundColor Green
    Write-Host "Vous pouvez maintenant tester: http://localhost" -ForegroundColor Green
} elseif ($errors -eq 0) {
    Write-Host "La structure est fonctionnelle mais il y a des avertissements." -ForegroundColor Yellow
} else {
    Write-Host "Des erreurs critiques doivent etre corrigees." -ForegroundColor Red
}
