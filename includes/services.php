<?php
/**
 * Katalog služeb a pravidla dostupnosti – JEDINÉ místo, kde se mění ceník a otevírací doba.
 * Frontend si to stahuje přes GET /api/config.php, takže sluzby.html a rezervace zůstávají v souladu.
 * Ceny jsou konečné (neplátce DPH), délky v minutách pro 1 osobu.
 */
declare(strict_types=1);

function servicesCatalog(): array {
    return [
        // id => [název, cena Kč, délka min, skupina, volitelně: od (cena „od“), maxOsob]
        'denni'           => ['name' => 'Denní líčení',               'price' => 500,  'duration' => 30,  'group' => 'Líčení'],
        'plesove'         => ['name' => 'Plesové líčení',             'price' => 1000, 'duration' => 50,  'group' => 'Líčení'],
        'festivalove'     => ['name' => 'Festivalové líčení',         'price' => 1000, 'duration' => 60,  'group' => 'Líčení', 'from' => true],
        'foceni'          => ['name' => 'Líčení na focení',           'price' => 800,  'duration' => 45,  'group' => 'Líčení'],
        'uces-jednoduchy' => ['name' => 'Účes – jednoduchá úprava',   'price' => 500,  'duration' => 60,  'group' => 'Účesy'],
        'uces-slozity'    => ['name' => 'Účes – složitější',          'price' => 800,  'duration' => 60,  'group' => 'Účesy'],
        'balicek-ples'    => ['name' => 'Plesový balíček (líčení + účes)', 'price' => 1500, 'duration' => 90, 'group' => 'Balíčky'],
        'balicek-foto'    => ['name' => 'Foto balíček (líčení + účes)',    'price' => 1200, 'duration' => 75, 'group' => 'Balíčky'],
        'zkouska-liceni'  => ['name' => 'Zkouška svatebního líčení',  'price' => 1000, 'duration' => 75,  'group' => 'Svatební zkoušky'],
        'zkouska-uces'    => ['name' => 'Zkouška svatebního účesu',   'price' => 900,  'duration' => 60,  'group' => 'Svatební zkoušky'],
        'kurz'            => ['name' => 'Kurz sebelíčení',            'price' => 3000, 'duration' => 120, 'group' => 'Kurz', 'maxPersons' => 2],
    ];
}

/** Pořadí skupin na stránce rezervace. */
function servicesGroups(): array { return ['Líčení', 'Účesy', 'Balíčky', 'Svatební zkoušky', 'Kurz']; }

/**
 * Pravidla dostupnosti. days: 0 = neděle … 6 = sobota (jako JS getDay()).
 * months: jen tyto měsíce (1–12); chybí = celý rok. services: které služby lze v tomto okně objednat.
 */
function availabilityRules(): array {
    return [
        [
            'days' => [2, 3, 4], 'from' => '15:30', 'to' => '19:00',
            'label' => 'v úterý, ve středu a ve čtvrtek 15:30–19:00',
            'services' => ['denni', 'foceni', 'balicek-foto', 'uces-jednoduchy', 'uces-slozity', 'zkouska-liceni', 'zkouska-uces', 'kurz'],
        ],
        [
            'days' => [5, 6], 'from' => '15:00', 'to' => '20:00', 'months' => [11, 12, 1, 2, 3],
            'label' => 'v pátek a v sobotu 15:00–20:00, jen od listopadu do března',
            'services' => ['plesove', 'festivalove', 'balicek-ples', 'uces-jednoduchy', 'uces-slozity'],
        ],
        [
            'days' => [0], 'from' => '10:00', 'to' => '13:00',
            'label' => 'v neděli 10:00–13:00',
            'services' => ['zkouska-liceni', 'zkouska-uces'],
        ],
    ];
}

function bookingLimits(): array {
    return [
        'minDaysAhead'   => 2,    // nejpozději 2 dny předem
        'maxMonthsAhead' => 2,    // nejdéle 2 měsíce dopředu
        'slotStepMin'    => 30,   // časy po 30 minutách
        'maxPersons'     => 4,
        'cancelFreeUntilDayBefore' => true,   // storno zdarma do dne předem
        'cancelFeeSameDay' => 300,
        'weddingDepositKc' => 1000,
        'weddingDepositDays' => 3,
    ];
}

/** Vrátí katalog + pravidla pro frontend (bez interních polí). */
function publicConfig(): array {
    return [
        'services' => servicesCatalog(),
        'groups'   => servicesGroups(),
        'rules'    => availabilityRules(),
        'limits'   => bookingLimits(),
    ];
}

/**
 * Ověří vybrané služby a počet osob, spočítá délku, cenu a popisek.
 * Vrací ['ok'=>true, 'ids'=>[], 'label'=>..., 'duration'=>int, 'price'=>int|null, 'priceFrom'=>bool] nebo ['ok'=>false,'error'=>...].
 */
function resolveServices(array $ids, int $persons): array {
    $cat = servicesCatalog();
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
    if (!$ids) return ['ok' => false, 'error' => 'Vyberte alespoň jednu službu.'];
    $limits = bookingLimits();
    if ($persons < 1 || $persons > $limits['maxPersons']) return ['ok' => false, 'error' => 'Počet osob musí být 1–' . $limits['maxPersons'] . '.'];

    $names = []; $duration = 0; $price = 0; $from = false;
    foreach ($ids as $id) {
        if (!isset($cat[$id])) return ['ok' => false, 'error' => 'Neznámá služba: ' . $id];
        $s = $cat[$id];
        if (isset($s['maxPersons']) && $persons > $s['maxPersons']) {
            return ['ok' => false, 'error' => $s['name'] . ' lze objednat nejvýše pro ' . $s['maxPersons'] . ' osoby.'];
        }
        $names[]   = $s['name'];
        $duration += $s['duration'];
        $price    += $s['price'];
        if (!empty($s['from'])) $from = true;
    }
    return [
        'ok' => true, 'ids' => $ids, 'label' => implode(' + ', $names),
        'duration' => $duration * $persons, 'price' => $price * $persons, 'priceFrom' => $from,
    ];
}
