# Toast global (Snackbar)

## Objectif

Afficher un message utilisateur qui survit aux navigations React Router
(ex: création de mission puis redirection vers la liste).

## API

Le toast est exposé via le hook :

```ts
import { useToast } from "src/app/ui/toast/useToast";

const toast = useToast();
toast.success("Enregistré");
toast.error("Erreur serveur");
toast.info("Information");
toast.warning("Attention");
```
