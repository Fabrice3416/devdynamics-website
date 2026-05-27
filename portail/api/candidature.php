<?php
declare(strict_types=1);

/**
 * Reception du formulaire public de candidature (optionnel).
 * Le cahier des charges precise que les inscriptions sont closes avant
 * la livraison de l app - cet endpoint est neutre par defaut.
 *
 * Si les inscriptions sont reouvertes : implementer l INSERT dans
 * candidatures avec deduplication (telephone + date_naissance).
 */

http_response_code(404);
exit('Service inactif - inscriptions ACP closes.');
