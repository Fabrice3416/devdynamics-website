<?php
declare(strict_types=1);

/**
 * Helpers transverses (affichage, formats, redirections, flash).
 */

/**
 * Echappement HTML. Accepte les nombres autant que les chaines : sous
 * declare(strict_types=1), une cle de tableau numerique - PHP transforme '1' et
 * '10' en entiers - ferait sinon tomber la page entiere sur un TypeError, muet
 * en production ou display_errors est a Off.
 */
function e(string|int|float|null $s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Montant en gourdes : 1 234 567,89 HTG */
function htg(float|int|string|null $m, bool $unit = true): string
{
    $v = number_format((float)($m ?? 0), 2, ',', ' ');
    return $unit ? $v . ' HTG' : $v;
}

function date_fr(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $t = strtotime($iso);
    return $t ? date('d/m/Y', $t) : '';
}

function datetime_fr(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $t = strtotime($iso);
    return $t ? date('d/m/Y H:i', $t) : '';
}

function redirect(string $path): void
{
    header('Location: ' . (str_starts_with($path, 'http') || str_starts_with($path, '/') ? $path : base_path($path)));
    exit;
}

function flash_set(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }
}

function flash_get(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function client_ip(): ?string
{
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

function client_agent(): string
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
}

/** Empreinte SHA-256 d'un fichier, utilisee par FICHIER, APPOSITION et JOURNAL_AUDIT. */
function empreinte_fichier(string $absPath): ?string
{
    $h = @hash_file('sha256', $absPath);
    return $h === false ? null : $h;
}
