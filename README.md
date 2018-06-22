# Vos loisirs en Live API

This API provides the data harvesting and normalization to be send to the "Vos loisirs en Live" client.

+ Client address: https://loisirs-live.tourcoing.fr
+ Client GitHub: https://github.com/Lab-numerique-LIVE/live-loisirs

The attended data format is:

```js
categories = ["", "", "", ""];
events = [
    {
        "title": "<title>",
        "description": "<description>",
        "longDescription": "<long description>",
        "url": "<url>",
        "image": "<image url>",
        "category": "<category, in >",
        "startDate": "<start date, as ISO8601>",
        "endDate": "<end date, as ISO8601>",
        "location": {
            "latitude": "<latitude>",
            "longitude": "<longitude>",
            "name": "<Location name>",
            "address": "<Location address>",
            "email": "<Location email>",
            "url": "<Location url>",
            "area": "<Location area>",
        },
        "publics": {
            "type": "<>", 
            "label": "<>"
        }, 
        "rates": [
            {
                "label": "<rate label>", 
                "type": "<rate type>", 
                "amount": "<rate amount, in €>", 
                "condition": "<rate condition>"
            }, 
            ...
        ]
        "timings": [
            {
                "start": "<ISO8601 start date>", 
                "end": "<ISO8601 end date>", 
            }, 
            ...
        ]
    }, 
    ...
];
```



