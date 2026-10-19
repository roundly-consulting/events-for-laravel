<?php

declare(strict_types=1);

// Oznámenia pre účastníkov, pozvaných a organizátorov. :title je podujatie, :when jeho miestny čas.
return [
    'view_event' => 'Zobraziť podujatie',
    'reason' => 'Dôvod: :reason',
    'join' => 'Pripojte sa online: :url',

    'invited' => [
        'subject' => 'Pozvánka: :title',
        'greeting' => 'Ste pozvaný!',
        'body' => 'Pozývame Vás na „:title“ dňa :when.',
        'message' => 'Správa od organizátora: :message',
        'action' => 'Odpovedať na pozvánku',
    ],
    'confirmed' => [
        'subject' => 'Idete: :title',
        'greeting' => 'Uvidíme sa!',
        'body' => 'Vaše miesto na „:title“ dňa :when je potvrdené.',
        'guests' => '{0} Bez hostí.|{1} S :count hosťom.|[2,*] S :count hosťami.',
        'code' => 'Váš kód na vstup: :code',
        'action' => 'Vaša vstupenka',
    ],
    'waitlist_promoted' => [
        'subject' => 'Uvoľnilo sa miesto: :title',
        'greeting' => 'Dobrá správa!',
        'body' => 'Uvoľnilo sa pre Vás miesto na „:title“ dňa :when.',
        'held' => 'Držíme ho pre Vás do :until — dokončite platbu, aby ste si ho ponechali.',
        'pay' => 'Dokončiť platbu',
    ],
    'reminder' => [
        'subject' => 'Pripomienka: :title',
        'greeting' => 'Už čoskoro',
        'body' => '„:title“ sa začína :when.',
    ],
    'updated' => [
        'subject' => 'Zmena: :title',
        'greeting' => 'Niečo sa zmenilo',
        'body' => 'Podrobnosti podujatia „:title“ sa zmenili. Koná sa :when.',
    ],
    'postponed' => [
        'subject' => 'Odložené: :title',
        'greeting' => 'Podujatie je odložené',
        'body' => '„:title“ plánované na :when je odložené. O novom termíne Vás budeme informovať.',
    ],
    'rescheduled' => [
        'subject' => 'Nový termín: :title',
        'greeting' => 'Podujatie má nový termín',
        'body' => '„:title“ sa teraz koná :when.',
        'previous' => 'Pôvodne bolo plánované na :previous.',
    ],
    'cancelled' => [
        'subject' => 'Zrušené: :title',
        'greeting' => 'Podujatie je zrušené',
        'body' => '„:title“ plánované na :when je zrušené.',
    ],
    'moved_online' => [
        'subject' => 'Presunuté online: :title',
        'greeting' => 'Podujatie sa presunulo online',
        'body' => '„:title“ dňa :when sa teraz koná online.',
    ],
];
