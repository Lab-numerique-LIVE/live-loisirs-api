# vliller-api

Un API JSON similaire à l'API XML de VLille.

## GET /stations

Retourne une liste de station au format :
```json
{
    "id": "1",
    "name": "Metropole Europeenne de Lille",
    "latitude": 50.6419,
    "longitude": 3.07599
}
```

## GET /stations/:id

Retourne la station avec l'identifiant `:id` au format :
```json
{
    "address": "MEL RUE DU BALLON ",
    "bikes": "18",
    "docks": "18",
    "payment": "AVEC_TPE",
    "status": "0",
    "lastupd": "7 secondes"
}
```
