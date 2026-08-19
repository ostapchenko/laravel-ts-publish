<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

/**
 * Plain backed enum for Team::week_days — no #[TsEnum] family attributes and no
 * ArchTech\Enums traits. This fixture exercises AsEnumCollection casting, not
 * enum-feature generation (Status and Season already cover that).
 */
enum WeekDays: string
{
    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';
    case Sunday = 'sunday';
}
