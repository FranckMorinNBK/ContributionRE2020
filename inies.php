<?php
// Relais vers l'API publique INIES (contourne le blocage CORS du navigateur).
// Usage : inies.php?id=46443  →  JSON {id, nom, declarant, norme, dvr, unite, A1A3, A4A5, B1..B5, C, D}
// Valeurs = indicateur "Changement climatique - total" (EN 15804+A2) ou "Réchauffement climatique" (EN 15804+A1).

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const INIES_API = 'https://www.base-inies.fr/api/';
const CACHE_TTL = 86400; // 24 h

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function fetch_json($path) {
    $ch = curl_init(INIES_API . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'Nobatek-CalculetteRE2020',
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status === 0) fail(502, "INIES ne répond pas.");
    if ($status === 404) return null;
    if ($status !== 200) fail(502, "Réponse INIES inattendue (HTTP $status).");
    $data = json_decode($body, true);
    if ($data === null) fail(502, "Réponse INIES illisible.");
    return $data;
}

// Listes de référence (normes, organismes), mises en cache car volumineuses et quasi statiques
function fetch_cached($path, $cacheName) {
    $cache = sys_get_temp_dir() . "/inies_$cacheName.json";
    if (is_file($cache) && time() - filemtime($cache) < CACHE_TTL) {
        $data = json_decode(file_get_contents($cache), true);
        if ($data) return $data;
    }
    $data = fetch_json($path);
    if (!$data) fail(502, "Impossible de lire la liste INIES « $path ».");
    @file_put_contents($cache, json_encode($data));
    return $data;
}

// Organisme déclarant : celui dont un opérateur correspond à l'idAdmin de la fiche
function get_declarant($idAdmin) {
    foreach (fetch_cached('getAllOrganismeAndUsers/', 'organismes') as $org) {
        foreach ($org['tOperateurs'] ?? [] as $op) {
            if ($op['idOperateur'] == $idAdmin) return $org['nom'];
        }
    }
    return null;
}

$id = $_GET['id'] ?? '';
if (!preg_match('/^\d{1,9}$/', $id)) fail(400, "EPD_id invalide.");

$produit = fetch_json("Produit/$id");
if (!$produit) fail(404, "Fiche INIES $id introuvable.");

$norme = null;
foreach (fetch_cached('Norme', 'normes') as $n) if ($n['idNorme'] == $produit['idNorme']) $norme = $n;
if (!$norme) fail(422, "Norme de la fiche inconnue.");

// Indicateur changement climatique total
$idIndic = null;
foreach ($norme['indicateurs'] as $ind) {
    if (in_array($ind['shortName'], ['Changement climatique - total', 'Réchauffement climatique'], true)) {
        $idIndic = $ind['id'];
        break;
    }
}
if (!$idIndic) fail(422, "Norme « {$norme['nomNorme']} » non prise en charge (pas d'indicateur de changement climatique).");
// PEP : phases Production / Transport / Mise en oeuvre / Vie en oeuvre / Fin de vie (idPhase 1 à 5)
$isPep = in_array(1, array_column($norme['phases'], 'idPhase'));

// idPhaseNorme (propre à la norme) → idPhase (commun) ; puis valeurs par idPhase
$phaseOf = array_column($norme['phases'], 'idPhase', 'idPhaseNorme');
$val = [];
foreach ($produit['tIndicateurQuantites'] as $q) {
    if ($q['idIndicateurNormeNavigation']['idIndicateur'] != $idIndic) continue;
    $p = $phaseOf[$q['idPhaseNorme']] ?? null;
    if ($p !== null && $q['quantite'] !== null) $val[$p] = (float)$q['quantite'];
}
if (!$val) fail(422, "Aucune valeur de changement climatique dans cette fiche.");

// Unité de l'UF (ex. « m² », ou « 10 m² » si l'UF ne porte pas sur une seule unité)
function get_unite_uf($produit) {
    foreach (fetch_cached('Unite', 'unites') as $u) {
        if ($u['idUnite'] == $produit['idUnitUf']) {
            $q = $produit['quantiteUf'];
            return ($q === null || $q == 1) ? $u['nomUnite'] : "$q {$u['nomUnite']}";
        }
    }
    return null;
}

// Total d'étape s'il existe, sinon somme des sous-modules
function stage($val, $total, $parts) {
    if (isset($val[$total])) return $val[$total];
    $s = 0; foreach ($parts as $p) $s += $val[$p] ?? 0;
    return $s;
}

// Codes idPhase INIES : 8-10 A1-A3, 11 étape production, 12 A4, 13 A5, 14-18 B1-B5,
// 21-24 C1-C4, 25 étape fin de vie, 27 D.
// PEP : 1 Production → A1-A3, 2 Transport + 3 Mise en oeuvre → A4-A5, 5 Fin de vie → C ; B1-B5 et D identiques.
echo json_encode([
    'id'    => (int)$produit['idProduit'],
    'nom'       => $produit['nomProduit'],
    'declarant' => get_declarant($produit['idAdmin']),
    'norme'     => $norme['nomNorme'],
    'dvr'   => $produit['dvt'],
    'unite' => get_unite_uf($produit),
    'A1A3'  => $isPep ? ($val[1] ?? 0) : stage($val, 11, [8, 9, 10]),
    'A4A5'  => $isPep ? ($val[2] ?? 0) + ($val[3] ?? 0) : ($val[12] ?? 0) + ($val[13] ?? 0),
    'B1'    => $val[14] ?? 0,
    'B2'    => $val[15] ?? 0,
    'B3'    => $val[16] ?? 0,
    'B4'    => $val[17] ?? 0,
    'B5'    => $val[18] ?? 0,
    'C'     => $isPep ? ($val[5] ?? 0) : stage($val, 25, [21, 22, 23, 24]),
    'D'     => $val[27] ?? null,
], JSON_UNESCAPED_UNICODE);
