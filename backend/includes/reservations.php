<?php
/**
 * Reservation availability.
 *
 * A booking holds a table for `reservation_dining_minutes` (default 2 hours).
 * For a requested slot:
 *   1. Bookings whose dining window overlaps the slot and already have a
 *      table assigned take that table out of play.
 *   2. Every overlapping booking without a table, plus the new party, must
 *      still fit at the remaining tables (each party at one table with enough
 *      seats; the new party also in its preferred area, if any).
 * Step 2 is a bipartite matching (parties → tables), so a 2-person booking
 * never blocks the only 10-seat table when a 2-seater is free.
 */

declare(strict_types=1);

final class ReservationException extends RuntimeException
{
    public function __construct(string $message, private array $errors = [], int $code = 422)
    {
        parent::__construct($message, $code);
    }

    public function errors(): array
    {
        return $this->errors;
    }
}

function reservation_config(): array
{
    return [
        'enabled'        => (bool) setting('reservations_enabled', true),
        'open'           => (string) setting('reservation_open_time', '12:00'),
        'close'          => (string) setting('reservation_close_time', '22:30'),
        'slot_minutes'   => max(15, (int) setting('reservation_slot_minutes', 30)),
        'dining_minutes' => max(30, (int) setting('reservation_dining_minutes', 120)),
        'max_guests'     => max(1, (int) setting('reservation_max_guests', 20)),
        'max_days_ahead' => max(1, (int) setting('reservation_max_days_ahead', 60)),
        'min_notice'     => max(0, (int) setting('reservation_min_notice_minutes', 60)),
    ];
}

/** All bookable HH:MM slots for a date, before availability is applied. */
function reservation_slot_times(string $date): array
{
    $cfg   = reservation_config();
    $start = strtotime("$date {$cfg['open']}");
    $end   = strtotime("$date {$cfg['close']}");
    if ($start === false || $end === false) {
        return [];
    }
    $earliest = time() + $cfg['min_notice'] * 60;

    $slots = [];
    for ($t = $start; $t <= $end; $t += $cfg['slot_minutes'] * 60) {
        if ($t >= $earliest) {
            $slots[] = date('H:i', $t);
        }
    }
    return $slots;
}

/**
 * Availability for every slot on a date for a party size.
 * @param ?int $ignoreId reservation to leave out (when staff re-check an existing booking)
 * @return list<array{time:string, available:bool}>
 */
function reservation_availability(string $date, int $guests, ?string $location = null, ?int $ignoreId = null): array
{
    $cfg      = reservation_config();
    $location = $location && strcasecmp($location, 'any') !== 0 ? $location : null;

    $tables = db_all('SELECT id, capacity, location FROM restaurant_tables WHERE is_active = 1');
    $bookings = db_all(
        "SELECT id, reservation_time, guests, table_id FROM reservations
          WHERE reservation_date = ? AND status IN ('pending','confirmed') AND id <> ?",
        [$date, $ignoreId ?? 0]
    );

    $out = [];
    foreach (reservation_slot_times($date) as $time) {
        $slotStart = strtotime("$date $time");
        $taken     = [];
        $parties   = [];
        foreach ($bookings as $b) {
            if (abs($slotStart - strtotime($date . ' ' . $b['reservation_time'])) >= $cfg['dining_minutes'] * 60) {
                continue;                                         // no overlap
            }
            if ($b['table_id'] !== null) {
                $taken[(int) $b['table_id']] = true;
            } else {
                $parties[] = ['guests' => (int) $b['guests'], 'location' => null];
            }
        }
        $parties[] = ['guests' => $guests, 'location' => $location];   // the new party

        $free  = array_values(array_filter($tables, static fn ($t) => !isset($taken[(int) $t['id']])));
        $out[] = ['time' => $time, 'available' => parties_fit_tables($parties, $free)];
    }
    return $out;
}

/**
 * Can every party be seated at its own table? (Kuhn's bipartite matching.)
 * @param list<array{guests:int, location:?string}> $parties
 * @param list<array{id:mixed, capacity:mixed, location:?string}> $tables
 */
function parties_fit_tables(array $parties, array $tables): bool
{
    if (count($parties) > count($tables)) {
        return false;
    }
    $fits = static fn (array $p, array $t): bool =>
        (int) $t['capacity'] >= $p['guests'] && ($p['location'] === null || $t['location'] === $p['location']);

    $tableOwner = [];                                         // table index => party index
    $tryAssign  = static function (int $pi, array &$seen) use (&$tryAssign, &$tableOwner, $parties, $tables, $fits): bool {
        foreach ($tables as $ti => $t) {
            if (isset($seen[$ti]) || !$fits($parties[$pi], $t)) {
                continue;
            }
            $seen[$ti] = true;
            if (!isset($tableOwner[$ti]) || $tryAssign($tableOwner[$ti], $seen)) {
                $tableOwner[$ti] = $pi;
                return true;
            }
        }
        return false;
    };

    foreach (array_keys($parties) as $pi) {
        $seen = [];
        if (!$tryAssign($pi, $seen)) {
            return false;
        }
    }
    return true;
}

/** Validate input and create a pending reservation. Returns the row. */
function create_reservation(array $input): array
{
    $cfg = reservation_config();
    if (!$cfg['enabled']) {
        throw new ReservationException('Online reservations are closed right now. Please call us to book.', [], 503);
    }

    $v = Validator::make($input, [
        'name'             => 'required|string|min:2|max:100',
        'phone'            => 'required|phone',
        'email'            => 'nullable|email|max:150',
        'date'             => 'required|date|after_or_today|before_days:' . $cfg['max_days_ahead'],
        'time'             => 'required|time',
        'guests'           => 'required|integer|between:1,' . $cfg['max_guests'],
        'table_preference' => 'nullable|in:Any,Courtyard,Indoor,Terrace',
        'special_requests' => 'nullable|string|max:500',
    ], ['phone' => 'Phone number']);

    if ($v->fails()) {
        throw new ReservationException('Please correct the highlighted fields.', $v->errors());
    }
    $d    = $v->validated();
    $time = substr((string) $d['time'], 0, 5);

    $slot = null;
    foreach (reservation_availability($d['date'], $d['guests'], $d['table_preference'] ?? null) as $s) {
        if ($s['time'] === $time) {
            $slot = $s;
            break;
        }
    }
    if ($slot === null) {
        throw new ReservationException('Please choose one of the available times.', ['time' => 'That time is outside booking hours or too soon.']);
    }
    if (!$slot['available']) {
        throw new ReservationException('That time is fully booked. Please pick another slot.', ['time' => 'Fully booked.'], 409);
    }

    for ($attempt = 0; ; $attempt++) {
        try {
            $id = db_insert('reservations', [
                'reference'        => generate_reservation_reference(),
                'name'             => $d['name'],
                'phone'            => $d['phone'],
                'email'            => $d['email'] ?: null,
                'reservation_date' => $d['date'],
                'reservation_time' => $time . ':00',
                'guests'           => $d['guests'],
                'table_preference' => $d['table_preference'] ?: 'Any',
                'special_requests' => $d['special_requests'] ?: null,
                'status'           => 'pending',
            ]);
            break;
        } catch (PDOException $e) {
            if ($attempt >= 4 || ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }
    }
    return db_one('SELECT * FROM reservations WHERE id = ?', [$id]);
}
