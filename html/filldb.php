<?php
// CONNEXION A LA BDD
require_once $_SERVER['DOCUMENT_ROOT'] . '/../includes/dbconnection.php';

// LE TEMPS D'EXÉCUTION DÉPEND DU NOMBRE DE REQUÊTES
// ET DU NOMBRE DE RANGS

// NOMBRE DE REQUÊTES À EFFECTUER
const REQUESTS = 15;

// NOMBRE DE RANGS PAR REQUÊTE EFFECTUÉE
const ROWS = 1000;

function requete($url)
{
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => [
            "cache-control: no-cache"
        ]
    ));

    $data = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        print_r($err);
        return null;
    }

    $data = json_decode($data, true);

    if (json_last_error() === JSON_ERROR_NONE && isset($data['response']['docs'])) {
        return $data['response']['docs'];
    } else {
        print_r("Erreur de décodage JSON ou structure inattendue");
        return null;
    }
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>R&L - ! Insertions en BDD !</title>
</head>

<body>

    <?php

    echo REQUESTS . " REQUÊTE(S) DE " . ROWS . " RANGS...<br><br>";

    $db->beginTransaction();

    $db->query("DELETE FROM _affiliation");
    $db->query("DELETE FROM _auteur");
    $db->query("DELETE FROM _publication");
    $db->query("DELETE FROM _publication_affiliation");
    $db->query("DELETE FROM _publication_auteur");

    $insertPublicationStmt = $db->prepare("INSERT INTO _publication (id, titre, url, type, annee) VALUES (:id, :titre, :url, :type, :annee) ON CONFLICT DO NOTHING");
    $insertAuteurStmt = $db->prepare("INSERT INTO _auteur (id, nom, prénom) VALUES (:id, :nom, :prenom) ON CONFLICT DO NOTHING");
    $insertPublicationAuteurStmt = $db->prepare("INSERT INTO _publication_auteur (pub_id, aut_id) VALUES (:pub_id, :aut_id) ON CONFLICT DO NOTHING");
    $insertAffiliationStmt = $db->prepare("INSERT INTO _affiliation (nom) VALUES (:nom) ON CONFLICT (nom) DO UPDATE SET nom = EXCLUDED.nom RETURNING id");
    $insertPublicationAffiliationStmt = $db->prepare('INSERT INTO _publication_affiliation (pub_id, aff_id) VALUES (:pub_id, :aff_id) ON CONFLICT DO NOTHING');

    for ($i = 0; $i < REQUESTS; $i++) {
        $start = $i * ROWS;
        $results = requete("https://api.archives-ouvertes.fr/search/IRISA/?fl=docid,title_s,uri_s,docType_s,producedDateY_i,authIdHal_i,authLastName_s,authFirstName_s,instStructName_s&sort=producedDateY_i+desc&rows=" . ROWS . "&start=$start");

        foreach ($results as $elt) {
            $pub_id = $elt["docid"];
            $titre = is_array($elt["title_s"]) ? implode(", ", $elt["title_s"]) : $elt["title_s"];
            if (strlen($titre) > 255) {
                $titre = mb_substr($titre,0,250,"UTF-8") . "...";
            }
            $url = is_array($elt["uri_s"]) ? implode(", ", $elt["uri_s"]) : $elt["uri_s"];
            $type = is_array($elt["docType_s"]) ? implode(", ", $elt["docType_s"]) : $elt["docType_s"];
            $annee = is_array($elt["producedDateY_i"]) ? implode(", ", $elt["producedDateY_i"]) : $elt["producedDateY_i"];

            $insertPublicationStmt->bindParam(':id', $pub_id);
            $insertPublicationStmt->bindParam(':titre', $titre);
            $insertPublicationStmt->bindParam(':url', $url);
            $insertPublicationStmt->bindParam(':type', $type);
            $insertPublicationStmt->bindParam(':annee', $annee);
            $insertPublicationStmt->execute();

            $auteurs = $elt["authIdHal_i"] ?? [];
            $noms = $elt["authLastName_s"] ?? [];
            $prenoms = $elt["authFirstName_s"] ?? [];

            for ($j = 0; $j < count($auteurs); $j++) {
                $aut_id = $auteurs[$j];
                $nom = mb_convert_encoding($noms[$j], 'UTF-8', 'auto');
                $prenom = mb_convert_encoding($prenoms[$j], 'UTF-8', 'auto');

                $insertAuteurStmt->bindParam(':id', $aut_id);
                $insertAuteurStmt->bindParam(':nom', $nom);
                $insertAuteurStmt->bindParam(':prenom', $prenom);
                $insertAuteurStmt->execute();

                $insertPublicationAuteurStmt->bindParam(':pub_id', $pub_id);
                $insertPublicationAuteurStmt->bindParam(':aut_id', $aut_id);
                $insertPublicationAuteurStmt->execute();
            }

            $affiliations = $elt["instStructName_s"] ?? [];

            foreach ($affiliations as $affiliation) {
                $affiliation = mb_convert_encoding($affiliation, 'UTF-8', 'auto');

                $insertAffiliationStmt->bindParam(':nom', $affiliation);
                $insertAffiliationStmt->execute();
                $aff_id = $insertAffiliationStmt->fetchColumn();

                $insertPublicationAffiliationStmt->bindParam(':pub_id', $pub_id);
                $insertPublicationAffiliationStmt->bindParam(':aff_id', $aff_id);
                $insertPublicationAffiliationStmt->execute();
            }
        }

        // Temps séparant les blocs de ROWS requêtes
        sleep(2);
    }

    $db->commit();

    echo "ÉXÉCUTION TERMINÉE";

    ?>

</body>

</html>