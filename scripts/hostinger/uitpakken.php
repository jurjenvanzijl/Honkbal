<?php
/**
 * Honkbal Live – uitpakscript voor hosting zonder SSH (bijv. Hostinger via de telefoon).
 *
 * Gebruik:
 *   1. Upload dit bestand en honkbal-live-hostinger.zip naar public_html
 *      (de zip mag ook in de map erboven staan).
 *   2. Open https://jouwdomein.nl/uitpakken.php en volg de stappen.
 *
 * Wat het doet (pas na bevestiging):
 *   - bestaande inhoud van public_html  -> ../oude-site/ (niets wordt verwijderd)
 *   - map honkbal/ uit de zip           -> ../honkbal/   (naast public_html, niet via het web bereikbaar)
 *   - inhoud van public_html/ uit de zip -> public_html/
 *   - maakt een nieuwe APP_KEY en SETUP_TOKEN aan in ../honkbal/.env
 *   - verwijdert daarna de zip en zichzelf
 *
 * Bevat bewust geen geheimen; die worden pas op de server aangemaakt.
 */

declare(strict_types=1);

const ZIP_NAME = 'honkbal-live-hostinger.zip';
const MIN_PHP = '8.3.0';

$webroot = __DIR__;
$base = dirname(__DIR__);
$appDir = $base.'/honkbal';
$oldSite = $base.'/oude-site';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function page(string $title, string $body): never
{
    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8">'
        .'<meta name="viewport" content="width=device-width, initial-scale=1">'
        .'<title>'.e($title).'</title><style>'
        .'body{font-family:system-ui,sans-serif;max-width:640px;margin:0 auto;padding:16px;line-height:1.5;color:#14181f}'
        .'h1{font-size:1.4rem}.ok{background:#e7f6ec;border:1px solid #1f7a3a;padding:12px;border-radius:8px}'
        .'.err{background:#fdecee;border:1px solid #b3001b;padding:12px;border-radius:8px}'
        .'.warn{background:#fff4d6;border:1px solid #b07800;padding:12px;border-radius:8px}'
        .'button,.btn{display:inline-block;background:#0b2545;color:#fff;border:0;border-radius:8px;padding:12px 18px;font-size:1rem;text-decoration:none;min-height:44px}'
        .'code{background:#eef1f5;padding:2px 4px;border-radius:4px;word-break:break-all}label{display:flex;gap:8px;align-items:flex-start;margin:12px 0}'
        .'input[type=checkbox]{width:22px;height:22px;flex:none}</style></head><body>'
        .'<h1>'.e($title).'</h1>'.$body.'</body></html>';
    exit;
}

function fail(string $message): never
{
    page('Installatie gestopt', '<p class="err">'.e($message).'</p><p>Er is niets kapotgemaakt dat niet terug kan. Stuur deze melding naar degene die je helpt.</p>');
}

/** Verwijdert een map die dit script zelf heeft aangemaakt. */
function removeTree(string $dir): void
{
    if (! is_dir($dir) || is_link($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir.'/'.$item;
        is_dir($path) && ! is_link($path) ? removeTree($path) : unlink($path);
    }
    rmdir($dir);
}

function setEnv(string $env, string $key, string $value): string
{
    $line = $key.'='.$value;
    $count = 0;
    $env = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $env, -1, $count);

    return $count ? $env : rtrim($env)."\n".$line."\n";
}

// ---------------------------------------------------------------------
// Controles vooraf
// ---------------------------------------------------------------------

if (file_exists($appDir.'/.env') && str_contains((string) file_get_contents($appDir.'/.env'), 'APP_KEY=base64:')) {
    @unlink(__FILE__);
    page('Al geïnstalleerd', '<p class="ok">Honkbal Live is al geïnstalleerd. Dit script is verwijderd.</p>'
        .'<p>Je installatielink staat in het bestand <code>INSTALLATIE-LINK.txt</code> naast public_html.</p>');
}

$zip = null;
foreach ([$webroot.'/'.ZIP_NAME, $base.'/'.ZIP_NAME] as $candidate) {
    if (is_file($candidate)) {
        $zip = $candidate;
        break;
    }
}

$problems = [];
if (version_compare(PHP_VERSION, MIN_PHP, '<')) {
    $problems[] = 'De PHP-versie is '.PHP_VERSION.'. Zet in hPanel (PHP-configuratie) de versie op 8.3 of hoger en open deze pagina opnieuw.';
}
if (! class_exists(ZipArchive::class)) {
    $problems[] = 'De PHP-extensie "zip" staat uit. Zet die aan in hPanel (PHP-configuratie → PHP-extensies).';
}
if (! extension_loaded('pdo_sqlite')) {
    $problems[] = 'De PHP-extensie "pdo_sqlite" staat uit. Zet die aan in hPanel (PHP-configuratie → PHP-extensies).';
}
if (! $zip) {
    $problems[] = 'Het bestand '.ZIP_NAME.' is niet gevonden. Upload het naar public_html (of de map erboven).';
}
if (! is_writable($base)) {
    $problems[] = 'De map boven public_html is niet beschrijfbaar; dit script kan de app daar niet neerzetten.';
}
if (file_exists($appDir)) {
    $problems[] = 'Er staat al een map "honkbal" naast public_html. Verwijder of hernoem die eerst.';
}
if ($problems) {
    page('Nog niet klaar om te installeren', '<div class="warn"><ul><li>'.implode('</li><li>', array_map('e', $problems)).'</li></ul></div>');
}

// ---------------------------------------------------------------------
// Stap 1: bevestigen
// ---------------------------------------------------------------------

$existing = array_values(array_filter(scandir($webroot), fn ($i) => ! in_array($i, ['.', '..', basename(__FILE__), ZIP_NAME], true)));

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['backup'] ?? '') !== 'ja') {
    $list = $existing
        ? '<p>Deze bestanden en mappen staan nu in public_html en worden verplaatst naar <code>oude-site</code> (naast public_html):</p><p><code>'
            .e(implode(', ', array_slice($existing, 0, 30))).(count($existing) > 30 ? ', …' : '').'</code></p>'
        : '<p>public_html is leeg.</p>';
    page('Honkbal Live installeren', '<p class="ok">Alles is klaar: PHP '.e(PHP_VERSION).', zip gevonden.</p>'
        .$list
        .'<p>Je huidige website verdwijnt dan van '.e($_SERVER['HTTP_HOST'] ?? 'je domein').'. De bestanden blijven bewaard in <code>oude-site</code>; de WordPress-database wordt niet aangeraakt.</p>'
        .'<form method="post"><label><input type="checkbox" name="backup" value="ja" required> Ik heb een backup gemaakt en wil de huidige site vervangen door Honkbal Live.</label>'
        .'<button type="submit">Installeren</button></form>');
}

// ---------------------------------------------------------------------
// Stap 2: installeren
// ---------------------------------------------------------------------

@set_time_limit(300);
$tmp = $base.'/_honkbal_uitpakken_'.bin2hex(random_bytes(4));

$archive = new ZipArchive;
if ($archive->open($zip) !== true) {
    fail('Het zip-bestand kan niet worden geopend. Upload het opnieuw.');
}
for ($i = 0; $i < $archive->numFiles; $i++) {
    $name = (string) $archive->getNameIndex($i);
    if (str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('#(^|/)\.\.(/|$)#', $name)
        || ! preg_match('#^(honkbal/|public_html/|INSTALLATIE\.txt$)#', $name)) {
        $archive->close();
        fail('Het zip-bestand bevat onverwachte bestanden ('.$name.'). Gebruik het originele pakket.');
    }
}
if (! mkdir($tmp, 0755) || ! $archive->extractTo($tmp)) {
    $archive->close();
    removeTree($tmp);
    fail('Uitpakken is mislukt (schijfruimte vol?).');
}
$archive->close();
if (! is_dir($tmp.'/honkbal') || ! is_file($tmp.'/public_html/index.php')) {
    removeTree($tmp);
    fail('Het zip-bestand is niet compleet. Download en upload het opnieuw.');
}

// Huidige site opzij zetten.
if ($existing) {
    if (! is_dir($oldSite) && ! mkdir($oldSite, 0755)) {
        removeTree($tmp);
        fail('Kan de map oude-site niet aanmaken.');
    }
    foreach ($existing as $item) {
        $target = $oldSite.'/'.$item;
        if (file_exists($target)) {
            $target .= '-'.date('YmdHis');
        }
        if (! rename($webroot.'/'.$item, $target)) {
            removeTree($tmp);
            fail('Kon "'.$item.'" niet verplaatsen naar oude-site. Al verplaatste bestanden staan in oude-site.');
        }
    }
}

// App en openbare bestanden plaatsen.
if (! rename($tmp.'/honkbal', $appDir)) {
    removeTree($tmp);
    fail('Kon de map honkbal niet plaatsen.');
}
foreach (scandir($tmp.'/public_html') as $item) {
    if ($item === '.' || $item === '..') {
        continue;
    }
    if (! rename($tmp.'/public_html/'.$item, $webroot.'/'.$item)) {
        fail('Kon "'.$item.'" niet in public_html zetten.');
    }
}
removeTree($tmp);

// Geheimen aanmaken (alleen op de server, nooit in de zip).
$token = bin2hex(random_bytes(16));
$envFile = $appDir.'/.env';
$env = (string) file_get_contents($envFile);
$env = setEnv($env, 'APP_KEY', 'base64:'.base64_encode(random_bytes(32)));
$env = setEnv($env, 'SETUP_TOKEN', $token);
if (file_put_contents($envFile, $env) === false) {
    fail('Kon het instellingenbestand (.env) niet opslaan.');
}
@chmod($envFile, 0600);

$scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$link = 'https://'.($_SERVER['HTTP_HOST'] ?? 'jouwdomein.nl').'/installatie?token='.$token;
@file_put_contents($base.'/INSTALLATIE-LINK.txt', "Installatielink Honkbal Live (geheim, niet delen):\n{$link}\n\nOok nodig na updates (database bijwerken).\n");

// Opruimen.
@unlink($zip);
@unlink(__FILE__);

page('Uitpakken gelukt', '<p class="ok">Honkbal Live staat klaar. De zip en dit script zijn verwijderd.</p>'
    .($scheme !== 'https' ? '<p class="warn">Zet in hPanel nog <strong>HTTPS forceren</strong> aan (Beveiliging → SSL).</p>' : '')
    .'<p><strong>Laatste stap:</strong> maak je account aan via deze link. Bewaar hem goed en deel hem niet; je hebt hem ook nodig na updates. Hij staat ook in <code>INSTALLATIE-LINK.txt</code> naast public_html.</p>'
    .'<p><code>'.e($link).'</code></p>'
    .'<p><a class="btn" href="'.e($link).'">Account aanmaken</a></p>');
