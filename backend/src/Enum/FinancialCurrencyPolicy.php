<?php

namespace App\Enum;

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — un seul cas aujourd'hui : les lignes de
 * devises différentes ne sont jamais additionnées entre elles (voir
 * FinancialCalculation::totalsByCurrency()), aucun taux de change n'est appliqué. Champ
 * conservé distinct (plutôt qu'une simple constante) pour documenter explicitement la
 * politique et permettre une évolution future (ex: conversion à un taux figé) sans
 * migration de schéma supplémentaire.
 */
enum FinancialCurrencyPolicy: string
{
    case PER_CURRENCY_NO_CONVERSION = 'PER_CURRENCY_NO_CONVERSION';
}
