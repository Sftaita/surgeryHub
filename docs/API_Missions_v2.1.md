# API Missions v2.1 (Symfony 7 / JSON) — SurgicalHub Backend

Toutes les routes sont préfixées par `/api`, réponses JSON, erreurs standard HTTP (400 validation, 401, 403, 404, 409, 422).
Pagination : `page` (default 1), `limit` (default 20, max 100).

## Architecture backend (référence code)

Arborescence source (extrait) :

- `src/Controller/Api/`
  - `MissionController.php`
  - `InterventionController.php`
  - `MaterialCatalogController.php`
  - `MaterialItemRequestController.php`
  - `InstrumentistController.php`
  - `SurgeonController.php`
  - `SiteController.php`
  - `MeController.php`
  - `ServiceController.php`
  - `RatingController.php`
  - `ExportController.php`
- `src/Service/`
  - `MissionService.php`
  - `MissionMapper.php`
  - `MissionActionsService.php`
  - `MissionEncodingService.php`
  - `InterventionService.php`
  - `MaterialCatalogService.php`
  - `MaterialItemRequestService.php`
  - `MaterialItemMapper.php`
  - `RatingService.php`
- `src/Security/Voter/`
  - `MissionVoter.php`
  - `ServiceVoter.php`
  - `RatingVoter.php`
  - `ExportVoter.php`
  - `InstrumentistVoter.php`
- `src/EventSubscriber/`
  - `ApiExceptionSubscriber.php`

## Conventions d’erreurs (ApiExceptionSubscriber)

Réponse :

```json
{
  "error": {
    "status": 422,
    "code": "VALIDATION_FAILED",
    "message": "Validation failed",
    "violations": [],
    "debug": { "exceptionClass": "...", "exceptionMessage": "..." }
  }
}
```
