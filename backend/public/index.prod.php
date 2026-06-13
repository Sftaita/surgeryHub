<?php

// =============================================================================
// Point d'entrée PRODUCTION (Hostinger Cloud Startup).
//
// Hostinger impose le document root du sous-domaine api.surgicalhub.be sur :
//   /home/u245913739/domains/surgicalhub.be/public_html/api
// Le backend Symfony complet (vendor/, src/, config/, var/, .env...) reste
// NON PUBLIC dans :
//   /home/u245913739/domains/surgicalhub.be/backend
//
// Ce fichier est copié (par deploy-hostinger.sh) vers
// public_html/api/index.php — il ne fait QUE charger le runtime Symfony
// depuis ../../backend (2 niveaux au-dessus de public_html/api).
//
// dirname(__DIR__, 2) depuis public_html/api/index.php :
//   __DIR__            = .../public_html/api
//   dirname(__DIR__)   = .../public_html
//   dirname(__DIR__,2) = .../ (domains/surgicalhub.be)
// => dirname(__DIR__, 2).'/backend/vendor/autoload_runtime.php'
//      = .../domains/surgicalhub.be/backend/vendor/autoload_runtime.php
//
// App\Kernel::getProjectDir() résout ensuite automatiquement vers
// .../backend (composer.json y est), donc .env, var/, config/ sont chargés
// correctement sans configuration supplémentaire.
// =============================================================================

use App\Kernel;

require dirname(__DIR__, 2).'/backend/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
