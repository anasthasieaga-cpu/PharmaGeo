-- ============================================================
-- PharmaGeo — Audit des coordonnees des pharmacies (lecture seule)
--
-- Liste les pharmacies dont la latitude/longitude est suspecte :
-- hors de la zone de Porto-Novo, valeurs inversees, point nul, ou partagees avec une autre
-- pharmacie (signe d'une position estimee par IP au centre de la ville).
-- Aucune donnee n'est modifiee : corrigez ensuite chaque pharmacie depuis
-- « Ma pharmacie » (carte) ou admin/pharmacies.php.
-- ============================================================

SELECT p.Id_pharmacie, p.Nom, p.Quartier, p.Commune, p.Latitude, p.Longitude,
       CASE
           WHEN p.Latitude IS NULL OR p.Longitude IS NULL THEN 'coordonnees absentes'
           WHEN p.Latitude = 0 AND p.Longitude = 0 THEN 'point nul (0, 0)'
           WHEN p.Longitude BETWEEN 6.33 AND 6.67 AND p.Latitude BETWEEN 2.46 AND 2.79 THEN 'latitude/longitude inversees'
           ELSE 'hors de la zone de Porto-Novo'
       END AS Probleme
FROM pharmacie p
WHERE p.Latitude IS NULL OR p.Longitude IS NULL
   OR NOT (p.Latitude BETWEEN 6.33 AND 6.67 AND p.Longitude BETWEEN 2.46 AND 2.79)
ORDER BY p.Id_pharmacie;

-- Pharmacies distinctes situees exactement au meme point (arrondi a ~10 m).
SELECT ROUND(p.Latitude, 4) AS Lat, ROUND(p.Longitude, 4) AS Lng,
       COUNT(*) AS Nb_pharmacies, GROUP_CONCAT(p.Nom ORDER BY p.Nom SEPARATOR ' | ') AS Pharmacies
FROM pharmacie p
WHERE p.Latitude IS NOT NULL AND p.Longitude IS NOT NULL
GROUP BY ROUND(p.Latitude, 4), ROUND(p.Longitude, 4)
HAVING COUNT(*) > 1
ORDER BY Nb_pharmacies DESC;
