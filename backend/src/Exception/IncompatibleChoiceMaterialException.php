<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Tarification firme conditionnée à un choix obligatoire — thrown when attaching a
 * MaterialLine whose MaterialItem belongs to a DIFFERENT ChoiceOption of the same
 * RequiredChoiceGroup than the one currently selected on the MissionIntervention
 * (exclusivité automatique, §4/§7 du prompt). Mapped to error.code =
 * 'INCOMPATIBLE_CHOICE_MATERIAL' by ApiExceptionSubscriber.
 */
class IncompatibleChoiceMaterialException extends ConflictHttpException
{
}
