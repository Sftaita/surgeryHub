<?php

namespace App\Monitoring;

use Sentry\Event;

/**
 * D-140 — `before_send` Sentry : le corps d'une requête d'association MedVue contient le code
 * d'association, et les requêtes de l'API machine portent le secret MedVue dans leur en-tête.
 * Ni l'un ni l'autre ne doit quitter le serveur dans un rapport d'erreur : on retire le corps,
 * la query string et les en-têtes de la requête capturée pour ces routes uniquement.
 */
final class SentryIntegrationSecretScrubber
{
    private const SENSITIVE_PATH = '#/api/(medvue-integration|integrations/medvue)/#';

    public function __invoke(Event $event): ?Event
    {
        $request = $event->getRequest();
        $url = (string) ($request['url'] ?? '');

        if ($url !== '' && preg_match(self::SENSITIVE_PATH, $url) === 1) {
            unset($request['data'], $request['query_string'], $request['headers'], $request['cookies']);
            $event->setRequest($request);
        }

        return $event;
    }
}
